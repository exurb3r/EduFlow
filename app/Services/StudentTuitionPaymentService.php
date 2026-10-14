<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\WalletReadState;
use App\Enums\StudentTuitionPaymentState;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\Student;
use App\Models\StudentTuitionPaymentAttempt;
use App\Models\StudentTuitionPaymentIntent;
use App\Models\StudentWallet;
use App\Models\TuitionAccount;
use App\Services\Payments\AsyncPaymentTransport;
use App\Services\Payments\FeeBoundedPaymentTransport;
use App\Services\Payments\FeeGuarantee;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;

/** Quotes and student consent only. This service never calls a signer. */
final readonly class StudentTuitionPaymentService
{
    public function __construct(
        private InstallationInstitution $institutions,
        private StudentWalletService $balances,
        private WalletGateway $wallets,
        private ArcNetworkGateway $arc,
        private AsyncPaymentTransport $transport,
        private StudentTuitionPaymentDispatcher $dispatcher,
    ) {}

    /** @return array<string, mixed> */
    public function quote(Student $student, TuitionAccount $account, int $amountBaseUnits, ?string $requestKey = null): array
    {
        if ($requestKey !== null) {
            $intent = StudentTuitionPaymentIntent::query()->where('request_key', $requestKey)->firstOrFail();
            $this->assertOwner($intent, $student, $account, $amountBaseUnits);

            return $this->quoteData($intent);
        }
        $wallet = $this->payableWallet($student, $account, $amountBaseUnits);
        $institution = $this->institutions->require();
        $recipient = $this->recipientAddress();
        $simulated = $this->isSimulated();
        $balance = $this->balances->snapshot($student);
        if ($balance->state !== WalletReadState::OK || ! is_string($balance->balance) || $balance->isFake !== $simulated) {
            throw ValidationException::withMessages(['amount_base_units' => 'Wallet balance is unavailable or its driver differs; no payment can be quoted.']);
        }
        $fee = $simulated ? '0.000000' : self::readFee($this->transport->estimate([
            'from' => strtolower($wallet->address), 'to' => $recipient, 'amount_base_units' => $amountBaseUnits, 'chain' => 'ARC-TESTNET',
        ]));
        $feeUnits = self::feeCeiling($fee);
        $total = BigDecimal::of($amountBaseUnits)->plus($feeUnits)->toInt();
        $available = BigDecimal::of($balance->balance)->withPointMovedRight(6)->toScale(0, RoundingMode::Floor)->toInt();
        // Affordability is judged against what the payer is actually debited, not
        // against a notional total. On a relayed rail the fee is Circle's cost and
        // the payer's whole debit is the principal, so demanding principal + ceiling
        // would refuse a wallet that can in fact pay -- and the fee ceiling is
        // still recorded, because it bounds what the payer would lose on a rail
        // where they do pay it.
        $required = $this->payerDebitBaseUnits($amountBaseUnits, $feeUnits, $simulated);
        if ($required > $available) {
            throw ValidationException::withMessages(['amount_base_units' => 'Wallet cannot cover this principal plus the network fee ceiling.']);
        }
        $s = ['schema_version' => 1, 'purpose' => 'student_tuition_testnet_demo', 'plane' => 'pilot', 'asset' => 'USDC',
            'request_key' => strtolower((string) Str::uuid()), 'organization_id' => $institution->id,
            'quoted_for' => $student->user_id, 'student_id' => $student->id, 'tuition_account_id' => $account->id,
            'student_wallet_id' => $wallet->id, 'payer_address' => strtolower($wallet->address), 'recipient_address' => $recipient,
            'amount_base_units' => $amountBaseUnits, 'quoted_network_fee' => $fee, 'fee_ceiling_base_units' => $feeUnits,
            'available_base_units' => $available, 'total_base_units' => $total, 'chain' => 'ARC-TESTNET', 'chain_id' => 5042002,
            'simulated' => $simulated, 'quoted_at' => now()->utc()->toIso8601String(),
            'expires_at' => now()->utc()->addSeconds(max(30, min(300, (int) config('eduflow.student_tuition.quote_seconds', 120))))->toIso8601String(),
            'consent_text' => StudentTuitionPaymentIntent::CONSENT_TEXT];
        $intent = StudentTuitionPaymentIntent::query()->create([
            'request_key' => $s['request_key'], 'organization_id' => $institution->id, 'quoted_for' => $student->user_id,
            'student_id' => $student->id, 'tuition_account_id' => $account->id, 'student_wallet_id' => $wallet->id,
            'snapshot' => $s, 'snapshot_digest' => PaymentIntent::digest($s),
        ]);

        return $this->quoteData($intent);
    }

    public function pay(Student $student, TuitionAccount $account, int $amountBaseUnits, string $requestKey, string $expectedDigest, bool $accepted): StudentTuitionPaymentAttempt
    {
        $intent = StudentTuitionPaymentIntent::query()->where('request_key', $requestKey)->firstOrFail();
        $this->assertOwner($intent, $student, $account, $amountBaseUnits);
        if (! $accepted || ! hash_equals($intent->snapshot_digest, $expectedDigest)) {
            throw ValidationException::withMessages(['consent' => 'Accept the exact mounted quote and bounded costs.']);
        }

        return DB::transaction(function () use ($intent, $student, $account, $amountBaseUnits): StudentTuitionPaymentAttempt {
            Organization::query()->whereKey($intent->organization_id)->lockForUpdate()->firstOrFail();
            $stored = TuitionAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $existing = StudentTuitionPaymentAttempt::query()->where('request_key', $intent->request_key)->first();
            if ($existing instanceof StudentTuitionPaymentAttempt) {
                if (! $existing->hasValidConsent()) {
                    throw ValidationException::withMessages(['consent' => 'Existing consent is invalid; investigate without sending.']);
                }

                return $existing;
            }
            $wallet = $this->payableWallet($student->fresh(), $stored, $amountBaseUnits);
            if (Carbon::parse($intent->snapshot['expires_at'])->lte(now()) || $intent->snapshot['simulated'] !== $this->isSimulated()
                || $intent->snapshot['recipient_address'] !== $this->recipientAddress()
                || $wallet->id !== $intent->student_wallet_id || strtolower($wallet->address) !== $intent->snapshot['payer_address']) {
                throw ValidationException::withMessages(['consent' => 'Quote expired or wallet/rail changed; obtain fresh consent.']);
            }
            StudentTuitionPaymentAttempt::query()
                ->where('tuition_account_id', $stored->id)
                ->where('state', 'prepared')
                ->whereNull('submission_started_at')
                ->get()
                ->each(function (StudentTuitionPaymentAttempt $stale): void {
                    $staleIntent = StudentTuitionPaymentIntent::query()->find($stale->student_tuition_payment_intent_id);
                    $isExpired = $staleIntent instanceof StudentTuitionPaymentIntent
                        && isset($staleIntent->snapshot['expires_at'])
                        && Carbon::parse($staleIntent->snapshot['expires_at'])->lte(now());

                    if ($isExpired || $stale->stage === 'needs_operator_review' || $stale->stage === 'preflight_blocked') {
                        $stale->update([
                            'state' => 'failed',
                            'stage' => $stale->stage === 'needs_operator_review' || $stale->stage === 'preflight_blocked'
                                ? 'preflight_blocked'
                                : 'quote_expired_unsubmitted',
                            'last_error' => $stale->last_error ?? 'Consent quote expired or unsubmitted hold cleared before submission.',
                            'leased_until' => null,
                            'next_attempt_at' => null,
                        ]);
                    }
                });

            if (StudentTuitionPaymentAttempt::query()->where('tuition_account_id', $stored->id)
                ->where(function ($q): void {
                    $q->whereIn('state', StudentTuitionPaymentState::openStates())->orWhere(function ($q): void {
                        $q->where('state', 'verified')->whereNull('posted_at');
                    });
                })->exists()) {
                throw ValidationException::withMessages(['consent' => 'This account has an unresolved payment. Inspect it; do not create a replacement.']);
            }
            $s = $intent->snapshot;
            $consent = ['schema_version' => 1, 'intent_digest' => $intent->snapshot_digest, 'confirmed_by' => $intent->quoted_for,
                'confirmed_at' => now()->utc()->toIso8601String(), 'accepted' => true,
                'fee_ceiling_base_units' => $s['fee_ceiling_base_units'], 'consent_text' => StudentTuitionPaymentIntent::CONSENT_TEXT];
            $attempt = StudentTuitionPaymentAttempt::query()->create([
                'student_tuition_payment_intent_id' => $intent->id, 'request_key' => $intent->request_key,
                'student_id' => $intent->student_id, 'tuition_account_id' => $intent->tuition_account_id, 'student_wallet_id' => $intent->student_wallet_id,
                ...array_intersect_key($s, array_flip(['amount_base_units', 'quoted_network_fee', 'fee_ceiling_base_units', 'available_base_units',
                    'total_base_units', 'payer_address', 'recipient_address', 'chain', 'chain_id'])),
                'state' => $s['simulated'] ? 'simulated' : 'prepared', 'stage' => $s['simulated'] ? 'simulation_only' : 'consented_not_sent',
                'consent_snapshot' => $consent, 'consent_digest' => PaymentIntent::digest($consent), 'confirmed_at' => $consent['confirmed_at'],
                'investigation_owner_id' => config('eduflow.student_tuition.investigation_owner_id'),
            ]);
            $this->dispatcher->wakeAfterCommit($attempt->id);

            return $attempt;
        }, 3);
    }

    public function recipientAddress(): string
    {
        $address = config('lepton.arc.treasury');
        if (! is_string($address) || preg_match('/^0x[0-9a-fA-F]{40}$/D', $address) !== 1) {
            throw ValidationException::withMessages(['treasury' => 'No valid institution treasury is configured.']);
        }

        return strtolower($address);
    }

    public function isSimulated(): bool
    {
        return config('lepton.default') === 'fake' || $this->wallets instanceof FakeLeptonGateway || $this->arc instanceof FakeLeptonGateway;
    }

    public function payableWallet(Student $student, TuitionAccount $account, int $amountBaseUnits): StudentWallet
    {
        $this->institutions->require();
        if ($student->user_id < 1 || $account->student_id !== $student->id || $amountBaseUnits <= 0 || $amountBaseUnits > $account->remainingAmount()) {
            throw ValidationException::withMessages(['amount_base_units' => 'A positive amount within this student account remaining balance is required.']);
        }
        if (config('lepton.arc.chain') !== 'ARC-TESTNET' || config('lepton.arc.chain_id') !== 5042002) {
            throw ValidationException::withMessages(['chain' => 'Tuition pilot permits Arc testnet 5042002 only.']);
        }
        $wallet = $student->wallet;
        if (! $wallet instanceof StudentWallet || $wallet->status !== 'active' || $wallet->provider !== 'circle' || $wallet->chain !== 'ARC-TESTNET'
            || preg_match('/^0x[0-9a-fA-F]{40}$/D', $wallet->address) !== 1 || strcasecmp((string) $student->payout_address, $wallet->address) !== 0) {
            throw ValidationException::withMessages(['wallet' => 'Active generated operator-controlled pilot wallet must match the student address.']);
        }
        if (! $this->isSimulated() && ! in_array($account->id, config('eduflow.student_tuition.pilot_account_ids', []), true)) {
            throw ValidationException::withMessages(['account' => 'This account is not explicitly designated for testnet tuition demonstration.']);
        }

        return $wallet;
    }

    private function assertOwner(StudentTuitionPaymentIntent $intent, Student $student, TuitionAccount $account, int $amount): void
    {
        abort_unless($intent->student_id === $student->id && $intent->tuition_account_id === $account->id && $intent->quoted_for === $student->user_id, 404);
        if (! $intent->hasValidSnapshot() || $intent->snapshot['amount_base_units'] !== $amount) {
            throw ValidationException::withMessages(['consent' => 'Quote binds a different amount or failed integrity checks.']);
        }
    }

    /** @return array<string, mixed> */
    private function quoteData(StudentTuitionPaymentIntent $intent): array
    {
        $s = $intent->snapshot;

        return [...$s, 'recipient' => $s['recipient_address'], 'network_fee' => $s['quoted_network_fee'],
            'request_digest' => $intent->snapshot_digest, 'total' => BigDecimal::of($s['amount_base_units'])->withPointMovedLeft(6)->plus($s['quoted_network_fee'])->__toString(),
            'remaining_base_units' => TuitionAccount::query()->findOrFail($intent->tuition_account_id)->remainingAmount()];
    }

    /**
     * What this rail will actually debit the payer for.
     *
     * A relayed rail sponsors the gas, so the payer loses exactly the principal
     * and a wallet holding precisely that amount can send it. A rehearsal charges
     * nothing, so a rehearsal is also principal-only. Only a rail where the payer
     * bears the fee needs the ceiling added, and this lane refuses to submit on
     * such a rail without a separate decision, so the ceiling is what the payer
     * would lose if that ever changed.
     *
     * Derived from the transport's own guarantee rather than assumed, so a rail
     * that starts paying the fee again stops being under-funded silently.
     */
    private function payerDebitBaseUnits(int $amountBaseUnits, int $feeCeilingBaseUnits, bool $simulated): int
    {
        if ($simulated || $this->transport instanceof FeeBoundedPaymentTransport
            && $this->transport->feeGuarantee() === FeeGuarantee::RelayerSponsored) {
            return $amountBaseUnits;
        }

        return BigDecimal::of($amountBaseUnits)->plus($feeCeilingBaseUnits)->toInt();
    }

    public static function feeCeiling(string $fee): int
    {
        return BigDecimal::of($fee)->withPointMovedRight(6)->toScale(0, RoundingMode::Ceiling)->toInt();
    }

    /** @param array<string, mixed>|null $raw */
    public static function readFee(?array $raw): string
    {
        $data = is_array($raw['data'] ?? null) ? $raw['data'] : $raw;
        $fee = $data['networkFee'] ?? $data['medium']['networkFee'] ?? null;
        if (! is_string($fee) || preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,18})?$/D', $fee) !== 1 || ! BigDecimal::of($fee)->isPositive()) {
            throw ValidationException::withMessages(['fee' => 'Network fee unavailable; payment blocked, never assumed free.']);
        }

        return $fee;
    }
}
