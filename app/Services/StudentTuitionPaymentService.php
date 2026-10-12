<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\WalletReadState;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Student;
use App\Models\StudentTuitionPayment;
use App\Models\StudentWallet;
use App\Models\Transaction;
use App\Models\TuitionAccount;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
     */
    public function pay(Student $student, TuitionAccount $account, int $amountBaseUnits): StudentTuitionPayment
    {
        $quote = $this->quote($student, $account, $amountBaseUnits);

        $wallet = $student->wallet;
        $recipient = $quote['recipient'];
        $chain = $quote['chain'];

        try {
            $result = $this->wallets->transfer(
                $wallet->address,
                $recipient,
                $amountBaseUnits,
                [
                    'chain' => $chain,
                    'rpcUrl' => $this->rpcUrl(),
                    'idempotencyKey' => (string) Str::uuid(),
                ],
            );
        } catch (LeptonCliException $e) {
            throw new RuntimeException($this->readableFailure($e), previous: $e);
        }

        // A hash on its own is not a settlement: read the transaction back.
        $verified = $this->verifyOnChain($result->txHash);

        $fee = is_array($result->raw['data'] ?? null)
            ? (string) ($result->raw['data']['networkFee'] ?? $quote['network_fee'])
            : $quote['network_fee'];

        return DB::transaction(function () use ($student, $account, $wallet, $result, $amountBaseUnits, $fee, $recipient, $chain, $verified): StudentTuitionPayment {
            $payment = StudentTuitionPayment::create([
                'student_id' => $student->getKey(),
                'tuition_account_id' => $account->getKey(),
                'student_wallet_id' => $wallet->getKey(),
                'tx_hash' => $result->txHash,
                'amount_base_units' => $amountBaseUnits,
                'network_fee' => $fee,
                'payer_address' => $result->fromAddress,
                'recipient_address' => $recipient,
                'chain' => $chain,
                'tx_verified_on_chain' => $verified,
                'executed_at' => now(),
            ]);

            $account->increment('paid_amount', $amountBaseUnits);

            $this->creditTreasuryLedger($account->student, $amountBaseUnits, $recipient, $result->txHash, $fee, $payment);

            return $payment;
        });
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
            throw new RuntimeException(sprintf(
                'Enter %.2f USDC or less — %.2f is still owed on this account.',
                $account->remainingAmount() / 10 ** self::USDC_DECIMALS,
                $account->remainingAmount() / 10 ** self::USDC_DECIMALS,
            ));
        }

        $snapshot = $this->studentWallets->snapshot($student);

        if ($snapshot->state !== WalletReadState::OK || ! is_string($snapshot->balance)) {
            throw new RuntimeException('The wallet balance could not be read from the chain, so nothing can be charged.');
        }

        $fee = $this->quoteFee($wallet->address, $amountBaseUnits);
        $balanceBaseUnits = $this->usdcBaseUnitsFrom($snapshot->balance);

        if ($balanceBaseUnits - $this->feeBaseUnits($fee) < $amountBaseUnits) {
            throw new RuntimeException(sprintf(
                'The wallet holds %.2f USDC but this payment needs %.2f plus about %s in network fees. Enter a smaller amount.',
                $balanceBaseUnits / 10 ** self::USDC_DECIMALS,
                $amountBaseUnits / 10 ** self::USDC_DECIMALS,
                $fee,
            ));
        }
    }

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
        } catch (LeptonCliException) {
            // A quote that cannot be read is reported as unknown, never as zero,
            // so a payer is never told a transfer is free.
            return '0.000000';
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : $response;
        $fee = $data['networkFee'] ?? $data['medium']['networkFee'] ?? null;

        return is_string($fee) && $fee !== '' ? $fee : '0.000000';
    }

    /**
     * Read the transaction back from the chain.
     *
     * A hash in hand is only a claim that a transfer was submitted. Until the
     * transaction is seen on-chain the payment is not recorded as settled.
     */
    private function verifyOnChain(string $txHash): bool
    {
        if ($this->wallets instanceof FakeLeptonGateway) {
            return true;
        }

        try {
            $tx = $this->arc->rpc('eth_getTransactionByHash', [$txHash]);
        } catch (Throwable) {
            return false;
        }

        return is_array($tx) && $tx !== [];
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
     * The fee is 18-decimal native USDC. Truncated to the 6 decimals the ledger
     * speaks, because a sub-cent fee is noise; the exact string is kept on the
     * payment record.
     */
    private function feeBaseUnits(string $fee): int
    {
        if (! preg_match('/^\d+(\.\d+)?$/', $fee)) {
            return 0;
        }

        [$whole, $fraction] = array_pad(explode('.', $fee, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';

        if (strlen($whole) > 12) {
            return 0;
        }

        return ((int) $whole) * 1_000_000 + (int) str_pad(substr($fraction, 0, 6), 6, '0');
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
