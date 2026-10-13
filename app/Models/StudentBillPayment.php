<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StudentBillCategory;
use App\Services\StudentBillPaymentProcessor;
use Database\Factories\StudentBillPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;
use Throwable;
use Yukazakiri\Lepton\Support\Amounts;

/**
 * A student's payment of one of their own non-tuition bills.
 *
 * This row is **not** a `Transaction` and does not move a wallet balance. The
 * money moved on the chain when the transfer settled; what this row does is
 * allocate that settlement against a debt the registrar levied. Reading it is
 * therefore never proof that cash arrived — `tx_verified_on_chain` and an
 * intact `settlement_evidence_digest` are, and the allocation path refuses to
 * run without both.
 *
 * The row carries three separately re-derivable immutable layers, in the order
 * they are earned:
 *
 *  1. {@see self::hasValidSnapshot()} — the quote, written before any signer
 *     could be reached. It binds the bill, the category, the exact principal,
 *     the payer, the institution treasury, the chain, the fee ceiling, the
 *     balance and the expiry, and the client echoes its digest.
 *  2. {@see self::hasValidConsent()} — the payer's acceptance of that exact
 *     snapshot, bound to it by digest.
 *  3. {@see self::hasValidSettlement()} — the persisted chain proof, written
 *     once and never rewritten.
 *
 * The classification assessment those layers carry is triage metadata and
 * nothing more: see {@see self::SPEND_ASSESSMENT_SCOPE}. Money flows *into*
 * the college here, so no verdict on this row can authorise money flowing out.
 *
 * @property int $id
 * @property string $request_key
 * @property string|null $movement_key
 * @property string $submission_state
 * @property string|null $provider_handle
 * @property string|null $provider_reference
 * @property string|null $fee_guarantee
 * @property int $observation_count
 * @property Carbon|null $leased_until
 * @property Carbon|null $next_attempt_at
 * @property string|null $last_error
 * @property int $organization_id
 * @property int $quoted_for
 * @property int $student_bill_id
 * @property int $student_id
 * @property int $student_wallet_id
 * @property array<string, mixed> $snapshot
 * @property string $snapshot_digest
 * @property array<string, mixed>|null $consent_snapshot
 * @property string|null $consent_digest
 * @property Carbon|null $consented_at
 * @property Carbon|null $submitted_at
 * @property array<string, mixed>|null $settlement_evidence
 * @property string|null $settlement_evidence_digest
 * @property Carbon|null $settlement_verified_at
 * @property string $policy_decision
 * @property string $policy_code
 * @property bool $requires_human_approval
 * @property int $amount_base_units
 * @property int $applied_base_units
 * @property int $overpayment_base_units
 * @property string|null $network_fee
 * @property string $payer_address
 * @property string $recipient_address
 * @property string $chain
 * @property string|null $tx_hash
 * @property bool $tx_verified_on_chain
 * @property bool $simulated
 * @property Carbon|null $allocated_at
 * @property Carbon|null $executed_at
 * @property-read Student $student
 * @property-read StudentBill $studentBill
 * @property-read StudentWallet $studentWallet
 * @property Carbon|null $created_at
 */
class StudentBillPayment extends Model
{
    /** @use HasFactory<StudentBillPaymentFactory> */
    use HasFactory;

    /**
     * What the payer is agreeing to, in the words the portal renders.
     *
     * Named by the digest-bound snapshot, so an edit here invalidates every
     * outstanding quote rather than silently changing what a consent already
     * accepted.
     */
    public const string CONSENT_TEXT = 'I authorize this one transfer from my wallet to the college treasury on Arc testnet, for the exact bill amount shown plus no more than the shown fee ceiling. Only the part that settles what I still owe is applied to my bill; any excess is held as my overpayment. Testnet USDC does not pay official college charges. Unknown outcomes are investigated, never automatically resent.';

    /** Distinguishes this lane's snapshots from the tuition lane's, which are not interchangeable. */
    public const string PURPOSE = 'student_bill_testnet_payment';

    /**
     * What the recorded classification assessment on this row is for.
     *
     * This row is money **arriving** at the college from a student's own wallet.
     * `BillPaymentPolicy` answers a different question — may the institution
     * release *its* funds unattended — so its verdict, however favourable, is
     * recorded here as staff triage metadata only. PLAN §17.2 correction 4 is
     * explicit that an incoming payment "creates no budget allocation, policy,
     * standing mandate or outgoing approval", and this constant is the
     * machine-readable form of that sentence.
     */
    public const string SPEND_ASSESSMENT_SCOPE = 'outgoing_spend_advisory_only';

    /**
     * Why a clean classification assessment still authorises nothing.
     *
     * Stable and safe to persist, so a row can be read as what it is without
     * inferring anything from the age of the code that wrote it.
     */
    public const string CODE_INBOUND_ADVISORY_ONLY = 'INBOUND_STUDENT_RECEIPT_ADVISORY_ONLY_V1';

    public const string CHAIN = 'ARC-TESTNET';

    public const int CHAIN_ID = 5042002;

    /**
     * Rail-facing states, each saying exactly what the rail knows.
     *
     * None of them may be read as money moved. `prepared` is the only state a
     * signer may be invoked from, and reaching it again after `submitted_at` has
     * been set is impossible by construction rather than by convention.
     */
    public const string SUBMISSION_CONSENTED = 'consented';

    /** Consent exists, nothing has been asked of the rail yet. */
    public const string SUBMISSION_PREPARED = 'prepared';

    /** A signer may have been called; the outcome is not yet known. */
    public const string SUBMISSION_SUBMITTING = 'submitting';

    /** The rail accepted and issued its own handle. No chain hash exists yet. */
    public const string SUBMISSION_ACCEPTED = 'accepted';

    /** A hash exists. Movement is asserted, not yet proven. */
    public const string SUBMISSION_AWAITING_VERIFICATION = 'awaiting_verification';

    /** Proof is persisted and the local allocation is still owed. */
    public const string SUBMISSION_VERIFIED = 'verified';

    /** A person must reconcile an existing submission. Never resolved by sending again. */
    public const string SUBMISSION_NEEDS_REVIEW = 'needs_operator_review';

    /**
     * States a signer has not necessarily been reached from.
     *
     * `consented` and `prepared` are the only two a sweep may redeliver on the
     * path that *can* submit, and even there the write-ahead fence in
     * {@see StudentBillPaymentProcessor::submit()} is what makes a
     * second delivery harmless — the sweep is recovery, not permission.
     */
    public const array SENDABLE_STATES = [self::SUBMISSION_CONSENTED, self::SUBMISSION_PREPARED];

    /**
     * States where the transfer may already exist and only reading can advance.
     *
     * These are never a path to sending again. They are swept so a transfer the
     * rail accepted cannot be stranded: without them, a crash between acceptance
     * and verification leaves a real payment that nobody ever looks at again.
     */
    public const array OBSERVABLE_STATES = [
        self::SUBMISSION_SUBMITTING,
        self::SUBMISSION_ACCEPTED,
        self::SUBMISSION_AWAITING_VERIFICATION,
    ];

    protected $fillable = [
        'request_key', 'movement_key', 'organization_id', 'quoted_for', 'student_bill_id', 'student_id',
        'student_wallet_id', 'snapshot', 'snapshot_digest', 'consent_snapshot', 'consent_digest',
        'consented_at', 'submitted_at', 'settlement_evidence', 'settlement_evidence_digest',
        'settlement_verified_at', 'policy_decision', 'policy_code', 'requires_human_approval',
        'amount_base_units', 'applied_base_units', 'overpayment_base_units', 'network_fee',
        'payer_address', 'recipient_address', 'chain', 'tx_hash', 'tx_verified_on_chain', 'simulated',
        'allocated_at', 'executed_at',
        'submission_state', 'provider_handle', 'provider_reference', 'fee_guarantee',
        'observation_count', 'leased_until', 'next_attempt_at', 'last_error',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'applied_base_units' => 0,
        'overpayment_base_units' => 0,
        'tx_verified_on_chain' => false,
        'simulated' => false,
        'requires_human_approval' => true,
        'submission_state' => self::SUBMISSION_CONSENTED,
        'observation_count' => 0,
    ];

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'quoted_for' => 'integer',
            'student_bill_id' => 'integer',
            'student_id' => 'integer',
            'student_wallet_id' => 'integer',
            'snapshot' => 'array',
            'consent_snapshot' => 'array',
            'consented_at' => 'datetime',
            'submitted_at' => 'datetime',
            'settlement_evidence' => 'array',
            'settlement_evidence_digest' => 'string',
            'settlement_verified_at' => 'datetime',
            'requires_human_approval' => 'boolean',
            'amount_base_units' => 'integer',
            'applied_base_units' => 'integer',
            'overpayment_base_units' => 'integer',
            'tx_verified_on_chain' => 'boolean',
            'simulated' => 'boolean',
            'allocated_at' => 'datetime',
            'executed_at' => 'datetime',
            'observation_count' => 'integer',
            'leased_until' => 'datetime',
            'next_attempt_at' => 'datetime',
        ];
    }

    #[\Override]
    protected static function booted(): void
    {
        /**
         * A quote is created with the evidence it makes a promise about, or not
         * at all. A row that exists without an intact, digest-matching snapshot
         * would be a consent record for terms nobody can re-derive.
         */
        static::creating(function (self $payment): void {
            if (! $payment->hasValidSnapshot()) {
                throw new LogicException('A bill payment quote requires exact intact evidence for one bill and one principal.');
            }
        });

        static::updating(function (self $payment): void {
            if ($payment->isDirty(['request_key', 'organization_id', 'quoted_for', 'student_bill_id',
                'student_id', 'student_wallet_id', 'snapshot', 'snapshot_digest', 'policy_decision', 'policy_code',
                'requires_human_approval', 'amount_base_units', 'payer_address', 'recipient_address', 'chain'])) {
                throw new LogicException('Bill payment quote and request identity cannot be rewritten.');
            }

            // Each of these is earned exactly once and only ever written from
            // nothing to something. A value that exists may not be changed to a
            // different value, which is what keeps consent, the submission fence
            // and the allocation fence single-valued.
            foreach (['consent_snapshot', 'consent_digest', 'consented_at', 'submitted_at', 'movement_key',
                'settlement_evidence', 'settlement_evidence_digest', 'settlement_verified_at', 'network_fee',
                'tx_hash', 'executed_at', 'allocated_at', 'provider_handle', 'provider_reference',
                'fee_guarantee'] as $field) {
                if ($payment->getRawOriginal($field) !== null && $payment->isDirty($field)) {
                    throw new LogicException('Bill payment consent, submission and settlement evidence are write-once.');
                }
            }

            /**
             * The submission fence is the only thing standing between a crash
             * and a second transfer, so a row that has already been handed to a
             * signer may never look unsent again — not by rewinding `submitted_at`,
             * and not by returning to a state a signer is allowed to act from.
             *
             * `provider_handle` and `provider_reference` are separately
             * write-once above, because a Circle agent wallet answers with a
             * transaction id first and a chain hash later, and each may only ever
             * be learned once.
             */
            if ($payment->getRawOriginal('submitted_at') !== null
                && $payment->isDirty(['submitted_at', 'submission_state'])
                && $payment->submission_state === self::SUBMISSION_PREPARED) {
                throw new LogicException('A bill payment that reached a signer can never return to a dispatchable state.');
            }

            // The flag and the split default to "nothing proven" and "nothing
            // applied" rather than to null, so they are fenced by the evidence
            // they are consequences of rather than by their own presence: once a
            // verdict exists, what it concluded is fixed, and once an allocation
            // exists, what it applied is final.
            if ($payment->getRawOriginal('settlement_evidence_digest') !== null
                && $payment->isDirty('tx_verified_on_chain')) {
                throw new LogicException('Verified movement evidence cannot be rewritten.');
            }

            if ($payment->getRawOriginal('allocated_at') !== null
                && $payment->isDirty(['applied_base_units', 'overpayment_base_units'])) {
                throw new LogicException('A bill payment allocation is final; resolve it forward instead of rewriting it.');
            }
        });

        static::deleting(fn (): never => throw new LogicException('Bill payment consent evidence cannot be deleted.'));
    }

    /**
     * Whether the immutable quote is intact and still says what it said.
     *
     * Every field is re-derived, never trusted: the row's own columns must
     * agree with the snapshot, the arithmetic must re-add up, and the digest
     * must still be the digest of those exact bytes. A row that fails here
     * cannot be consented to, cannot be submitted and cannot be allocated.
     */
    public function hasValidSnapshot(): bool
    {
        try {
            $s = $this->snapshot;

            foreach (['organization_id', 'quoted_for', 'student_id', 'student_bill_id', 'student_wallet_id'] as $field) {
                if (($s[$field] ?? null) !== $this->getAttribute($field) || $this->getAttribute($field) < 1) {
                    return false;
                }
            }

            foreach (['amount_base_units', 'fee_ceiling_base_units', 'available_base_units', 'total_base_units'] as $field) {
                if (! is_int($s[$field] ?? null) || $s[$field] < 0) {
                    return false;
                }
            }

            foreach (['payer_address', 'recipient_address'] as $field) {
                if (! is_string($s[$field] ?? null) || preg_match('/^0x[0-9a-f]{40}$/D', $s[$field]) !== 1 || $s[$field] === '0x'.str_repeat('0', 40)) {
                    return false;
                }
            }

            // Every column that also appears in the snapshot must agree with it,
            // so no column can be edited to contradict the terms the digest
            // covers — or the policy verdict recorded beside them.
            $columns = [
                'amount_base_units' => $this->amount_base_units,
                'payer_address' => $this->payer_address,
                'recipient_address' => $this->recipient_address,
                'chain' => $this->chain,
                'policy_decision' => $this->policy_decision,
                'policy_code' => $this->policy_code,
                'requires_human_approval' => $this->requires_human_approval,
            ];
            foreach ($columns as $field => $value) {
                if (($s[$field] ?? null) !== $value) {
                    return false;
                }
            }

            return ($s['schema_version'] ?? null) === 1
                && ($s['purpose'] ?? null) === self::PURPOSE
                && ($s['plane'] ?? null) === 'pilot'
                && ($s['asset'] ?? null) === 'USDC'
                && ($s['request_key'] ?? null) === $this->request_key
                && Str::isUuid($this->request_key)
                // Already known to exist: the column map above compared it.
                && $s['chain'] === self::CHAIN
                && ($s['chain_id'] ?? null) === self::CHAIN_ID
                && in_array($s['bill_category'] ?? null, StudentBillCategory::values(), true)
                && ($s['consent_text'] ?? null) === self::CONSENT_TEXT
                && is_bool($s['simulated'] ?? null)
                && $s['simulated'] === $this->simulated
                && $s['amount_base_units'] > 0
                && is_string($s['quoted_network_fee'] ?? null)
                && preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,18})?$/D', $s['quoted_network_fee']) === 1
                && $s['total_base_units'] === $s['amount_base_units'] + $s['fee_ceiling_base_units']
                && $s['available_base_units'] >= $s['total_base_units']
                && $this->hasValidSpendScope($s)
                && Carbon::parse($s['expires_at'])->gt(Carbon::parse($s['quoted_at']))
                && hash_equals($this->snapshot_digest, PaymentIntent::digest($s));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether the recorded assessment still claims what a receipt may claim.
     *
     * A quote mounted before {@see self::SPEND_ASSESSMENT_SCOPE} existed carries
     * no scope marker, and its `requires_human_approval` flag tracked the
     * policy verdict beside it. Those rows still re-derive: tightening the
     * validator to reject them would expire the consent of every student with
     * an outstanding quote for a reason that has nothing to do with their money,
     * and their digests must keep matching the bytes that were actually signed
     * for. So the marker is required only when it is present, and when it is
     * present nothing may contradict it.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function hasValidSpendScope(array $snapshot): bool
    {
        $authorizes = $snapshot['authorizes_outgoing_spend'] ?? null;
        $scope = $snapshot['outgoing_spend_assessment_scope'] ?? null;

        if ($authorizes === null && $scope === null) {
            // A pre-marker quote: the flag follows the verdict it was written
            // with, which is the only thing that quote ever asserted.
            return $this->requires_human_approval === ($snapshot['policy_decision'] !== 'auto_approve');
        }

        return $authorizes === false
            && $scope === self::SPEND_ASSESSMENT_SCOPE
            // An incoming receipt never authorises a release of institutional
            // funds, so the flag is unconditionally true. Recording it as
            // anything else would be a receipt asserting spend authority.
            && $this->requires_human_approval === true;
    }

    /**
     * Whether the payer accepted this exact quote, inside its own lifetime.
     *
     * Consent is bound to the quote by digest rather than to any live state, so
     * an amount, a wallet or a fee that changed after the quote cannot be
     * smuggled past it: the acceptance only means anything about the bytes it
     * names.
     */
    public function hasValidConsent(): bool
    {
        if (! $this->hasValidSnapshot()) {
            return false;
        }

        $c = $this->consent_snapshot;

        if (! is_array($c) || ! is_string($this->consent_digest) || $this->consented_at === null) {
            return false;
        }

        if (! hash_equals($this->consent_digest, PaymentIntent::digest($c))) {
            return false;
        }

        $confirmedAt = Carbon::parse((string) ($c['confirmed_at'] ?? ''));

        return ($c['schema_version'] ?? null) === 1
            && ($c['intent_digest'] ?? null) === $this->snapshot_digest
            && ($c['confirmed_by'] ?? null) === $this->quoted_for
            && ($c['accepted'] ?? null) === true
            && ($c['consent_text'] ?? null) === self::CONSENT_TEXT
            && is_int($c['fee_ceiling_base_units'] ?? null)
            && $c['fee_ceiling_base_units'] === $this->feeCeilingBaseUnits()
            && $confirmedAt->toIso8601String() === $this->consented_at->utc()->toIso8601String()
            && $this->consented_at->gte(Carbon::parse($this->snapshot['quoted_at']))
            && $this->consented_at->lt(Carbon::parse($this->snapshot['expires_at']));
    }

    /**
     * Whether persisted chain proof says this exact principal moved.
     *
     * Deliberately narrower than "there is a hash": the hash is a claim until a
     * receipt and a transaction back it, and a reverted receipt settles nothing.
     * The evidence digest binds the verdict to the consent it was produced
     * under, so proof cannot be carried over from a different payment.
     */
    public function hasValidSettlement(): bool
    {
        $e = $this->settlement_evidence;

        if (! $this->hasValidConsent() || $this->simulated || ! is_array($e)
            || ! is_string($this->settlement_evidence_digest) || $this->settlement_verified_at === null
            || ! hash_equals($this->settlement_evidence_digest, PaymentIntent::digest($e))
            || ! $this->tx_verified_on_chain
            || preg_match('/^0x[0-9a-f]{64}$/D', (string) $this->tx_hash) !== 1
            || ! is_string($this->network_fee)
            || preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,18})?$/D', $this->network_fee) !== 1) {
            return false;
        }

        // The verdict is only about the movement this consent authorized, so
        // every fact it reports must match the row rather than merely exist.
        $expected = [
            'consent_digest' => $this->consent_digest,
            'tx_hash' => $this->tx_hash,
            'verdict' => 'verified',
            'settled' => true,
            'chain_id' => $this->snapshot['chain_id'] ?? null,
            'sender' => $this->payer_address,
            'recipient' => $this->recipient_address,
            'amount_base_units' => $this->amount_base_units,
        ];
        foreach ($expected as $field => $value) {
            if (($e[$field] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * The fee ceiling the payer accepted, in 6-decimal base units.
     *
     * Read from the snapshot rather than duplicated into a column: a column
     * would be a second place for the same promise to live, and the two would
     * eventually disagree.
     */
    public function feeCeilingBaseUnits(): int
    {
        $fee = $this->snapshot['fee_ceiling_base_units'] ?? null;

        return is_int($fee) && $fee >= 0 ? $fee : 0;
    }

    /**
     * Whether this receipt authorises any release of institutional funds.
     *
     * Never, in any row, of any age — including a row written before
     * {@see self::SPEND_ASSESSMENT_SCOPE} existed and recorded an
     * `auto_approve` verdict beside `requires_human_approval = false`. The
     * verdict was an *outbound* policy evaluation applied to an inbound
     * receipt, so honouring it would let a student's fee payment read as
     * permission to spend the treasury's money. PLAN §17.2 correction 4.
     */
    public function authorizesOutgoingSpend(): bool
    {
        return false;
    }

    /**
     * Whether a queue may release this payment with nobody watching.
     *
     * Always false, for the reason {@see self::authorizesOutgoingSpend()} gives.
     * A student pays their own debt, so this row never carries institutional
     * permission to move; the predicate is kept so a caller asking the old
     * question gets the safe answer rather than a `null` it might read as
     * "nothing to check".
     */
    public function isEligibleForUnattendedSubmission(): bool
    {
        return false;
    }

    /**
     * A rehearsal under a driver that cannot move money.
     *
     * Recorded so the journey can be exercised, never so it can be counted:
     * nothing settled, so nothing is applied to the bill and nothing may be
     * claimed from it.
     */
    public function isRehearsal(): bool
    {
        return $this->simulated;
    }

    /** Whether this settlement has already been applied to its bill. */
    public function isAllocated(): bool
    {
        return $this->allocated_at !== null;
    }

    /** The amount received, in USDC's 6 decimals. */
    public function amount(): string
    {
        return Amounts::toDecimalString($this->amount_base_units, 6).' USDC';
    }

    /** The network fee the payer also lost, at Arc's native 18 decimals. */
    public function fee(): ?string
    {
        return $this->network_fee === null ? null : $this->network_fee.' USDC';
    }

    /**
     * The portion that actually settled what the bill still owed.
     *
     * An overpayment is recorded separately and never folded in: an amount
     * larger than the debt is a liability held for the student, and counting it
     * as settled is how a total plans money it may have to give back.
     */
    public function applied(): string
    {
        return Amounts::toDecimalString($this->applied_base_units, 6).' USDC';
    }

    public function overpayment(): ?string
    {
        if ($this->overpayment_base_units <= 0) {
            return null;
        }

        return Amounts::toDecimalString($this->overpayment_base_units, 6).' USDC';
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<StudentBill, $this> */
    public function studentBill(): BelongsTo
    {
        return $this->belongsTo(StudentBill::class);
    }

    /** @return BelongsTo<StudentWallet, $this> */
    public function studentWallet(): BelongsTo
    {
        return $this->belongsTo(StudentWallet::class);
    }

    /** @return HasMany<CollectionBatch, $this> */
    public function collections(): HasMany
    {
        return $this->hasMany(CollectionBatch::class);
    }
}
