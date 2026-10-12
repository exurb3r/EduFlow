<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\WalletReadState;
use App\Enums\StudentTuitionPaymentState;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Student;
use App\Models\StudentTuitionPayment;
use App\Models\StudentTuitionPaymentAttempt;
use App\Models\StudentWallet;
use App\Models\Transaction;
use App\Models\TuitionAccount;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;
use Yukazakiri\Lepton\Support\Amounts;
use Yukazakiri\Lepton\Support\LeptonCliException;

/**
 * Moves USDC from a student's generated wallet into the institution treasury.
 *
 * Three facts drive every method here:
 *
 * 1. Arc pays gas in native USDC, so the fee is taken from the STUDENT's wallet
 *    on top of the amount, and it goes to the network — not to the institution.
 *    The wallet must therefore hold the amount plus the fee, and the fee is
 *    quoted before anything is spent.
 * 2. The recipient is the treasury from LEPTON_TREASURY_ADDRESS. It is never
 *    taken from the request, so a crafted form cannot redirect tuition.
 * 3. A returned hash is a claim. Nothing is recorded as paid until the
 *    transaction is read back from the chain.
 */
class StudentTuitionPaymentService
{
    /** USDC as Circle reports it. The fee is 18 decimals and never goes through this. */
    private const USDC_DECIMALS = 6;

    public function __construct(
        private readonly WalletGateway $wallets,
        private readonly ArcNetworkGateway $arc,
        private readonly CircleAgentWalletCli $cli,
        private readonly StudentWalletService $studentWallets,
        // The same strict predicate an outgoing payment is held to, so an
        // inbound receipt is never verified by a weaker standard.
        private readonly ArcSettlementVerifier $settlement,
    ) {}

    /**
     * What a payment of this size would cost, before anything is spent.
     *
     * @return array{
     *     amount_base_units:int, network_fee:string, total:string,
     *     max_amount_base_units:int, balance:string|null,
     *     remaining_base_units:int, recipient:string, chain:string
     * }
     */
    public function quote(Student $student, TuitionAccount $account, int $amountBaseUnits): array
    {
        $this->assertPayable($student, $account, $amountBaseUnits);

        $fee = $this->quoteFee($student->wallet->address, $amountBaseUnits);
        $snapshot = $this->studentWallets->snapshot($student);

        $balanceBaseUnits = is_string($snapshot->balance)
            ? $this->usdcBaseUnitsFrom($snapshot->balance)
            : null;

        // The wallet needs the amount plus the fee it will pay as gas.
        $maxAmountBaseUnits = $balanceBaseUnits === null
            ? 0
            : max(0, $balanceBaseUnits - $this->feeBaseUnits($fee));

        return [
            'amount_base_units' => $amountBaseUnits,
            'network_fee' => $fee,
            'total' => $this->addFee($amountBaseUnits, $fee),
            'max_amount_base_units' => $maxAmountBaseUnits,
            'balance' => $snapshot->balance,
            'remaining_base_units' => $account->remainingAmount(),
            'recipient' => $this->recipientAddress(),
            'chain' => $this->studentWallets->chain(),
        ];
    }

    /**
     * Transfer the amount and record it once the chain confirms it.
     *
     * `$requestKey` is the identity this payment keeps for its whole life. It is
     * the caller's job to derive it from what the payer actually confirmed, so
     * a refreshed page or a lost response after submitting recovers this
     * attempt instead of sending a second transfer.
     */
    public function pay(Student $student, TuitionAccount $account, int $amountBaseUnits, string $requestKey): StudentTuitionPayment
    {
        $this->assertPayable($student, $account, $amountBaseUnits);

        $wallet = $student->wallet;
        $recipient = $this->recipientAddress();
        $chain = $this->studentWallets->chain();
        $fee = $this->quoteFee($wallet->address, $amountBaseUnits);

        // Written before a signer is asked to move anything, so a retry after a
        // crash or a lost response has something to find.
        $attempt = $this->recordAttempt($student, $account, $wallet, $amountBaseUnits, $requestKey, $fee);

        // A rehearsal is recorded as a rehearsal. Nothing is allocated to
        // tuition and nothing is credited to the treasury from it, because
        // nothing settled: posting it as revenue would be the specific lie this
        // lane exists to stop.
        if ($this->wallets instanceof FakeLeptonGateway) {
            return $this->rehearse($student, $account, $wallet, $amountBaseUnits, $fee, $recipient, $chain, $requestKey);
        }

        try {
            $result = $this->wallets->transfer(
                $wallet->address,
                $recipient,
                $amountBaseUnits,
                [
                    'chain' => $chain,
                    'rpcUrl' => $this->rpcUrl(),
                    'idempotencyKey' => $this->idempotencyKey($requestKey),
                ],
            );
        } catch (LeptonCliException $e) {
            $this->markUnknown($attempt, $this->readableFailure($e));

            throw new RuntimeException($this->readableFailure($e), $e->getCode(), previous: $e);
        }

        $verdict = $this->settlement->verify([
            'chain' => $chain,
            'chain_id' => $this->chainId(),
            'sender' => $wallet->address,
            'recipient' => $recipient,
            'amount_base_units' => $amountBaseUnits,
            'max_fee_base_units' => $this->feeBaseUnits($fee, roundUp: true),
        ], $result->txHash);

        if ($verdict['verdict'] !== 'verified') {
            $this->markUnverified($attempt, $result->txHash, (string) $verdict['verdict'], (string) $verdict['reason']);

            // Nothing is posted. A hash that exists, or a receipt that executed,
            // is not yet money in the treasury: the attempt is preserved so it
            // is observed and reconciled rather than sent again.
            throw new RuntimeException('This payment could not be confirmed on chain, so it has not been recorded on your tuition account. Do not send it again; finance staff are checking the existing attempt.');
        }

        $this->markVerified($attempt, $result->txHash, $verdict, $fee);

        return DB::transaction(function () use ($student, $account, $wallet, $result, $amountBaseUnits, $fee, $recipient, $chain, $requestKey): StudentTuitionPayment {
            $payment = $this->receipt([
                'request_key' => $requestKey,
                'student_id' => $student->getKey(),
                'tuition_account_id' => $account->getKey(),
                'student_wallet_id' => $wallet->getKey(),
                'tx_hash' => $result->txHash,
                'amount_base_units' => $amountBaseUnits,
                'network_fee' => $fee,
                'payer_address' => $wallet->address,
                'recipient_address' => $recipient,
                'chain' => $chain,
                'tx_verified_on_chain' => true,
                'simulated' => false,
                'executed_at' => now(),
            ]);

            // A receipt already written under this key means this posting has
            // happened. Returning it without allocating again is what makes a
            // repeated confirmation idempotent rather than a second credit.
            if (! $payment->wasRecentlyCreated) {
                return $payment;
            }

            $this->allocateToTuition($account, $payment);

            $this->creditTreasuryLedger($account->student, $amountBaseUnits, $recipient, $result->txHash, $fee, $payment);

            return $payment;
        });
    }

    /**
     * Write one receipt, or return the one already written for this attempt.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function receipt(array $attributes): StudentTuitionPayment
    {
        $existing = StudentTuitionPayment::query()
            ->where('request_key', $attributes['request_key'])
            ->first();

        if ($existing instanceof StudentTuitionPayment) {
            return $existing;
        }

        return StudentTuitionPayment::create($attributes);
    }

    /**
     * Record a rehearsal, and nothing else.
     *
     * The journey between the student's confirmation and the receipt has to be
     * exercisable before a payment provider is configured, so the receipt is
     * written and plainly marked. It carries no allocation, no ledger row and
     * no chain claim, and `isReal()` answers false for it everywhere it is read.
     */
    private function rehearse(
        Student $student,
        TuitionAccount $account,
        StudentWallet $wallet,
        int $amountBaseUnits,
        string $fee,
        string $recipient,
        string $chain,
        string $requestKey,
    ): StudentTuitionPayment {
        return $this->receipt([
            'request_key' => $requestKey,
            'student_id' => $student->getKey(),
            'tuition_account_id' => $account->getKey(),
            'student_wallet_id' => $wallet->getKey(),
            'tx_hash' => 'rehearsal:'.$requestKey,
            'amount_base_units' => $amountBaseUnits,
            'network_fee' => $fee,
            'payer_address' => $wallet->address,
            'recipient_address' => $recipient,
            'chain' => $chain,
            'tx_verified_on_chain' => false,
            'simulated' => true,
            'executed_at' => now(),
        ]);
    }

    /**
     * Credit verified movement to tuition exactly once, under a lock.
     *
     * Concurrent payments cannot over-credit the debt because the account is
     * re-read locked and the applied amount is the defined outstanding balance.
     * A genuine overpayment is recorded explicitly rather than dropped: an
     * amount larger than what is owed is a liability to the student, never
     * silent budget cash.
     */
    private function allocateToTuition(TuitionAccount $account, StudentTuitionPayment $payment): void
    {
        $stored = TuitionAccount::query()
            ->whereKey($account->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $outstanding = max(0, $stored->remainingAmount());
        $applied = min($payment->amount_base_units, $outstanding);
        $overpayment = max(0, $payment->amount_base_units - $outstanding);

        $payment->forceFill([
            'applied_base_units' => $applied,
            'overpayment_base_units' => $overpayment,
        ])->save();

        if ($applied <= 0) {
            return;
        }

        $stored->increment('paid_amount', $applied);
    }

    /**
     * Write the durable attempt before a signer is asked to move money.
     *
     * The `request_key` is what a retry reuses, so this is an upsert on that
     * key rather than an insert: a second call with the same key returns the
     * same row and therefore the same provider identity instead of minting a
     * second transfer. The fee recorded is the one quoted for this specific
     * transfer, never a placeholder.
     */
    private function recordAttempt(
        Student $student,
        TuitionAccount $account,
        StudentWallet $wallet,
        int $amountBaseUnits,
        string $requestKey,
        string $quotedFee,
    ): StudentTuitionPaymentAttempt {
        $snapshot = $this->studentWallets->snapshot($student);
        $available = is_string($snapshot->balance) ? $this->usdcBaseUnitsFrom($snapshot->balance) : 0;
        $feeBaseUnits = $this->feeBaseUnits($quotedFee, roundUp: true);

        return StudentTuitionPaymentAttempt::query()->updateOrCreate(
            ['request_key' => $requestKey],
            [
                'student_id' => $student->getKey(),
                'tuition_account_id' => $account->getKey(),
                'student_wallet_id' => $wallet->getKey(),
                'amount_base_units' => $amountBaseUnits,
                'quoted_network_fee' => $quotedFee,
                'fee_ceiling_base_units' => $feeBaseUnits,
                'available_base_units' => $available,
                'total_base_units' => $amountBaseUnits + $feeBaseUnits,
                'payer_address' => $wallet->address,
                'recipient_address' => $this->recipientAddress(),
                'chain' => $this->studentWallets->chain(),
                'chain_id' => $this->chainId(),
                'state' => StudentTuitionPaymentState::PREPARED->value,
                'stage' => 'recorded_not_sent',
                'attempts' => 0,
                'last_error' => null,
            ],
        );
    }

    private function markUnknown(StudentTuitionPaymentAttempt $attempt, string $reason): void
    {
        $attempt->forceFill([
            'state' => StudentTuitionPaymentState::UNKNOWN->value,
            'stage' => 'provider_refused_or_unreachable',
            'last_error' => $reason,
            'attempts' => $attempt->attempts + 1,
        ])->save();
    }

    private function markUnverified(StudentTuitionPaymentAttempt $attempt, string $txHash, string $verdict, string $reason): void
    {
        $attempt->forceFill([
            'provider_reference' => $txHash,
            'state' => StudentTuitionPaymentState::AWAITING_VERIFICATION->value,
            'stage' => 'reference_published_movement_unverified',
            'settlement_verdict' => $verdict,
            'settlement_reason' => $reason,
            'last_error' => $reason,
        ])->save();
    }

    /** @param array<string, mixed> $verdict */
    private function markVerified(StudentTuitionPaymentAttempt $attempt, string $txHash, array $verdict, string $fee): void
    {
        $attempt->forceFill([
            'provider_reference' => $txHash,
            'state' => StudentTuitionPaymentState::VERIFIED->value,
            'stage' => 'verified_movement',
            'quoted_network_fee' => $fee,
            'settlement_verdict' => $verdict['verdict'],
            'settlement_reason' => $verdict['reason'],
            'settlement_verified_at' => now(),
            'last_error' => null,
        ])->save();
    }

    private function idempotencyKey(string $requestKey): string
    {
        return 'tuition:'.$requestKey;
    }

    /**
     * The chain an attempt belongs to, read from the installation's Arc config.
     *
     * A verification against the wrong chain proves nothing: an identical hash
     * can exist on a different network and would be read as the same movement.
     */
    private function chainId(): int
    {
        $chainId = config('lepton.arc.chain_id', 5042002);

        return (int) (is_numeric($chainId) ? $chainId : 5042002);
    }

    /**
     * The treasury the institution controls. Fixed at LEPTON_TREASURY_ADDRESS,
     * so a form can never redirect a payment to another destination.
     */
    public function recipientAddress(): string
    {
        $address = $this->arc->treasuryAddress();

        if (! is_string($address) || $address === '') {
            throw new RuntimeException('No institution treasury is configured. Set LEPTON_TREASURY_ADDRESS before collecting tuition.');
        }

        return $address;
    }

    /**
     * Reject a payment before any USDC moves.
     */
    private function assertPayable(Student $student, TuitionAccount $account, int $amountBaseUnits): void
    {
        $wallet = $student->wallet;

        if (! $wallet instanceof StudentWallet) {
            throw new RuntimeException('This student has no generated wallet. Generate one before collecting tuition.');
        }

        $payoutAddress = $this->studentWallets->addressFor($student);

        if ($payoutAddress === null || strcasecmp($payoutAddress, $wallet->address) !== 0) {
            throw new RuntimeException('The payout address on file is not the generated wallet, so it cannot be signed for.');
        }

        if ($account->student_id !== $student->getKey()) {
            throw new InvalidArgumentException('That tuition account does not belong to this student.');
        }

        $remaining = $account->remainingAmount();

        if ($remaining <= 0) {
            throw new RuntimeException('This tuition account is already settled.');
        }

        if ($amountBaseUnits <= 0) {
            throw new InvalidArgumentException('Enter an amount greater than zero.');
        }

        if ($amountBaseUnits > $account->remainingAmount()) {
            $owed = $account->remainingAmount();

            throw new RuntimeException(sprintf(
                'Enter %s USDC or less — %s is still owed on this account.',
                Amounts::toDecimalString($owed, self::USDC_DECIMALS),
                Amounts::toDecimalString($owed, self::USDC_DECIMALS),
            ));
        }

        $snapshot = $this->studentWallets->snapshot($student);

        if ($snapshot->state !== WalletReadState::OK || ! is_string($snapshot->balance)) {
            throw new RuntimeException('The wallet balance could not be read from the chain, so nothing can be charged.');
        }

        $fee = $this->quoteFee($wallet->address, $amountBaseUnits);
        $balanceBaseUnits = $this->usdcBaseUnitsFrom($snapshot->balance);

        // Compared against the fee rounded UP. Flooring it here is how a wallet
        // holding exactly the quoted total ends up short by a remainder, which
        // is a failed transfer the payer was told would work.
        if ($balanceBaseUnits - $this->feeBaseUnits($fee, roundUp: true) < $amountBaseUnits) {
            throw new RuntimeException(sprintf(
                'The wallet holds %s USDC but this payment needs %s plus about %s in network fees. Enter a smaller amount.',
                Amounts::toDecimalString($balanceBaseUnits, self::USDC_DECIMALS),
                Amounts::toDecimalString($amountBaseUnits, self::USDC_DECIMALS),
                $fee,
            ));
        }
    }

    /**
     * The fee the rail will charge, or a refusal when it will not say.
     *
     * An unreadable quote throws rather than resolving to a figure. A payer
     * told a transfer is free will be charged one, and the difference is money
     * they never agreed to spend.
     */
    private function quoteFee(string $fromAddress, int $amountBaseUnits): string
    {
        if ($this->wallets instanceof FakeLeptonGateway) {
            return '0.000000';
        }

        try {
            $response = $this->cli->estimateTransfer(
                $fromAddress,
                $this->recipientAddress(),
                $amountBaseUnits,
                $this->studentWallets->chain(),
            );
        } catch (LeptonCliException $e) {
            throw new RuntimeException(
                'The network fee could not be read, so this payment cannot be quoted. Try again; if it persists, ask finance staff.',
                $e->getCode(),
                previous: $e,
            );
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : $response;
        $fee = $data['networkFee'] ?? $data['medium']['networkFee'] ?? null;

        if (! is_string($fee) || preg_match('/^\d+(\.\d+)?$/D', trim($fee)) !== 1 || (float) $fee <= 0.0) {
            throw new RuntimeException('The network did not report a fee for this transfer, so it cannot be quoted.');
        }

        return $fee;
    }

    /**
     * Amount plus fee as a USDC figure, so the payer is shown the real total
     * that will leave the wallet rather than a rounded one.
     */
    private function addFee(int $amountBaseUnits, string $fee): string
    {
        return Amounts::toDecimalString($amountBaseUnits + $this->feeBaseUnits($fee), self::USDC_DECIMALS);
    }

    /**
     * The fee is 18-decimal native USDC. It is converted exactly, and rounded
     * up when asked, so an affordability check can never treat a sub-unit
     * remainder as free: a wallet holding exactly the floored total is short by
     * that remainder and the transfer fails after the payer was told it would
     * work.
     */
    private function feeBaseUnits(string $fee, bool $roundUp = false): int
    {
        if (preg_match('/^(\d+)(?:\.(\d+))?$/D', trim($fee), $matches) !== 1) {
            return 0;
        }

        $whole = ltrim($matches[1], '0') ?: '0';
        $fraction = $matches[2] ?? '';

        if (strlen($whole) > 12) {
            return 0;
        }

        $truncated = substr($fraction.'000000', 0, 6);
        $baseUnits = ((int) $whole) * 1_000_000 + (int) $truncated;

        if ($roundUp && strlen($fraction) > 6 && ltrim(substr($fraction, 6), '0') !== '') {
            $baseUnits++;
        }

        return $baseUnits;
    }

    /**
     * The institution's ledger only; the student's own receipt lives on
     * StudentTuitionPayment.
     */
    private function creditTreasuryLedger(
        Student $student,
        int $baseUnits,
        string $recipient,
        string $txHash,
        string $fee,
        StudentTuitionPayment $payment,
    ): void {
        $treasury = Wallet::query()
            ->where('address', $recipient)
            ->where('status', 'active')
            ->first();

        if (! $treasury instanceof Wallet) {
            return;
        }

        $decimals = self::USDC_DECIMALS;
        $amountFloat = (float) Amounts::toDecimalString($baseUnits, $decimals);

        $treasury->increment('balance', $amountFloat);

        Transaction::create([
            'organization_id' => $treasury->organization_id,
            'wallet_id' => $treasury->id,
            'type' => TransactionType::TUITION_REVENUE,
            'recipient_address' => $recipient,
            'amount' => $amountFloat,
            'currency' => 'USDC',
            'status' => TransactionStatus::CONFIRMED,
            'provider_tx_hash' => $txHash,
            'network' => 'arc',
            'reference_type' => StudentTuitionPayment::class,
            'reference_id' => $payment->getKey(),
            'metadata' => [
                'sender' => $student->payout_address,
                'payer_student_number' => $student->student_number,
                'description' => 'Tuition paid from the student wallet',
                'source' => 'student_portal_pay_now',
                'network_fee' => $fee,
                'settlement_asset' => 'USDC',
                'amount_base_units' => $baseUnits,
                'explorer_url' => $this->safeExplorer($txHash),
            ],
            'executed_at' => now(),
        ]);
    }

    private function rpcUrl(): ?string
    {
        try {
            $url = $this->arc->rpcUrl();
        } catch (Throwable) {
            return null;
        }

        return is_string($url) && $url !== '' && ! str_starts_with($url, 'fake://') ? $url : null;
    }

    private function safeExplorer(string $txHash): ?string
    {
        try {
            return $this->arc->explorerUrl($txHash);
        } catch (Throwable) {
            return null;
        }
    }

    private function usdcBaseUnitsFrom(string $decimal): int
    {
        if (! preg_match('/^\d+(\.\d+)?$/', $decimal)) {
            return 0;
        }

        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';

        if (strlen($whole) > 12) {
            return 0;
        }

        return ((int) $whole) * 1_000_000 + (int) str_pad(substr($fraction, 0, 6), 6, '0');
    }

    private function readableFailure(LeptonCliException $e): string
    {
        $stderr = trim($e->stderr);

        return match (true) {
            str_contains($stderr, 'Not logged in'), str_contains($stderr, 'session expired') => 'The Circle CLI is not logged in for this chain. Run `php artisan lepton:login` and try again.',
            str_contains($stderr, 'insufficient'), str_contains($stderr, 'Insufficient') => 'The wallet does not hold enough USDC to cover the amount plus the network fee. Try a smaller amount.',
            str_contains($stderr, 'no agent session') => 'The wallet is not a Circle agent wallet, so it cannot be signed for.',
            $stderr !== '' => $stderr,
            default => 'The transfer could not be sent.',
        };
    }
}
