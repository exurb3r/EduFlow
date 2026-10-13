<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StudentTuitionPaymentState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

/**
 * One attempt to collect a student's tuition through the network.
 *
 * The record is written before a signer is asked to do anything, so a retry
 * after a lost response finds it instead of starting a new payment. A
 * `provider_handle` belonging to an accepted asynchronous transfer is not a
 * transaction hash, and a `provider_reference` is not evidence that money
 * moved: only `settlement_verdict` holds a defensible answer.
 *
 * @property int $id
 * @property int $student_id
 * @property int $tuition_account_id
 * @property int $student_wallet_id
 * @property string $request_key
 * @property int $amount_base_units
 * @property string $quoted_network_fee
 * @property int $fee_ceiling_base_units
 * @property int $available_base_units
 * @property int $total_base_units
 * @property string $payer_address
 * @property string $recipient_address
 * @property string $chain
 * @property int $chain_id
 * @property string $state
 * @property string|null $stage
 * @property string|null $provider_handle
 * @property string|null $provider_reference
 * @property Carbon|null $provider_accepted_at
 * @property int $attempts
 * @property string|null $last_error
 * @property string|null $settlement_verdict
 * @property string|null $settlement_reason
 * @property Carbon|null $settlement_verified_at
 * @property int|null $student_tuition_payment_intent_id
 * @property array<string, mixed>|null $consent_snapshot
 * @property string|null $consent_digest
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $submission_started_at
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $leased_until
 * @property array<string, mixed>|null $settlement_evidence
 * @property string|null $settlement_evidence_digest
 * @property string|null $actual_network_fee
 * @property Carbon|null $posted_at
 * @property string|null $fee_guarantee
 * @property int|null $investigation_owner_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Student $student
 * @property-read TuitionAccount $tuitionAccount
 * @property-read StudentWallet $studentWallet
 */
class StudentTuitionPaymentAttempt extends Model
{
    protected $fillable = [
        'student_id', 'tuition_account_id', 'student_wallet_id', 'request_key', 'amount_base_units',
        'quoted_network_fee', 'fee_ceiling_base_units', 'available_base_units', 'total_base_units',
        'payer_address', 'recipient_address', 'chain', 'chain_id', 'state', 'stage', 'provider_handle',
        'provider_reference', 'provider_accepted_at', 'attempts', 'last_error', 'settlement_verdict',
        'settlement_reason', 'settlement_verified_at',
        'student_tuition_payment_intent_id', 'consent_snapshot', 'consent_digest', 'confirmed_at',
        'submission_started_at', 'dispatched_at', 'next_attempt_at', 'leased_until',
        'settlement_evidence', 'settlement_evidence_digest', 'actual_network_fee', 'posted_at', 'investigation_owner_id',
        'fee_guarantee',
    ];

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'student_id' => 'integer',
            'tuition_account_id' => 'integer',
            'student_wallet_id' => 'integer',
            'amount_base_units' => 'integer',
            'fee_ceiling_base_units' => 'integer',
            'available_base_units' => 'integer',
            'total_base_units' => 'integer',
            'chain_id' => 'integer',
            'attempts' => 'integer',
            'provider_accepted_at' => 'datetime',
            'settlement_verified_at' => 'datetime',
            'student_tuition_payment_intent_id' => 'integer', 'consent_snapshot' => 'array',
            'confirmed_at' => 'datetime', 'submission_started_at' => 'datetime', 'dispatched_at' => 'datetime',
            'next_attempt_at' => 'datetime', 'leased_until' => 'datetime', 'settlement_evidence' => 'array',
            'posted_at' => 'datetime', 'investigation_owner_id' => 'integer',
        ];
    }

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $attempt): void {
            if ($attempt->student_tuition_payment_intent_id !== null && ! $attempt->hasValidConsent()) {
                throw new LogicException('Tuition attempt requires intact immutable student consent.');
            }
        });
        static::updating(function (self $attempt): void {
            if ($attempt->isDirty(['student_tuition_payment_intent_id', 'student_id', 'tuition_account_id', 'student_wallet_id',
                'request_key', 'amount_base_units', 'quoted_network_fee', 'fee_ceiling_base_units', 'available_base_units',
                'total_base_units', 'payer_address', 'recipient_address', 'chain', 'chain_id', 'consent_snapshot', 'consent_digest', 'confirmed_at'])) {
                throw new LogicException('Tuition consent and request identity cannot be rewritten.');
            }
            foreach (['submission_started_at', 'provider_handle', 'provider_reference', 'settlement_evidence_digest', 'posted_at', 'fee_guarantee'] as $field) {
                if ($attempt->getRawOriginal($field) !== null && $attempt->isDirty($field)) {
                    throw new LogicException('Tuition submission and settlement evidence are write-once.');
                }
            }
            if ($attempt->getRawOriginal('submission_started_at') !== null && $attempt->isDirty('state')
                && $attempt->state === StudentTuitionPaymentState::PREPARED->value) {
                throw new LogicException('A started tuition submission can never become dispatchable again.');
            }
            if ($attempt->getRawOriginal('settlement_evidence_digest') !== null && $attempt->isDirty(['settlement_evidence', 'actual_network_fee', 'settlement_verified_at'])) {
                throw new LogicException('Verified tuition movement proof cannot be rewritten.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Tuition attempts cannot be deleted.'));
    }

    public function hasValidConsent(): bool
    {
        $intent = StudentTuitionPaymentIntent::query()->find($this->student_tuition_payment_intent_id);
        if (! $intent instanceof StudentTuitionPaymentIntent || ! $intent->hasValidSnapshot() || ! is_array($this->consent_snapshot)
            || ! is_string($this->consent_digest) || $this->confirmed_at === null
            || ! hash_equals($this->consent_digest, PaymentIntent::digest($this->consent_snapshot))) {
            return false;
        }
        $s = $this->consent_snapshot;
        foreach (['request_key', 'student_id', 'tuition_account_id', 'student_wallet_id', 'amount_base_units',
            'quoted_network_fee', 'fee_ceiling_base_units', 'available_base_units', 'total_base_units', 'payer_address', 'recipient_address', 'chain', 'chain_id'] as $field) {
            if (($intent->snapshot[$field] ?? null) !== $this->getAttribute($field)) {
                return false;
            }
        }

        return ($s['intent_digest'] ?? null) === $intent->snapshot_digest && ($s['confirmed_by'] ?? null) === $intent->quoted_for
            && ($s['fee_ceiling_base_units'] ?? null) === $this->fee_ceiling_base_units
            && ($s['consent_text'] ?? null) === StudentTuitionPaymentIntent::CONSENT_TEXT && ($s['accepted'] ?? null) === true
            && ($s['confirmed_at'] ?? null) === $this->confirmed_at->utc()->toIso8601String()
            && $this->confirmed_at->gte(Carbon::parse($intent->snapshot['quoted_at']))
            && $this->confirmed_at->lt(Carbon::parse($intent->snapshot['expires_at']))
            && Str::isUuid($this->request_key);
    }

    public function hasValidSettlement(): bool
    {
        $s = $this->settlement_evidence;

        return $this->hasValidConsent() && is_array($s) && is_string($this->settlement_evidence_digest)
            && hash_equals($this->settlement_evidence_digest, PaymentIntent::digest($s))
            && ($s['consent_digest'] ?? null) === $this->consent_digest && ($s['tx_hash'] ?? null) === $this->provider_reference
            && ($s['verdict'] ?? null) === 'verified' && ($s['settled'] ?? null) === true
            && ($s['chain_id'] ?? null) === $this->chain_id && ($s['sender'] ?? null) === $this->payer_address
            && ($s['recipient'] ?? null) === $this->recipient_address && ($s['amount_base_units'] ?? null) === $this->amount_base_units;
    }

    public function state(): StudentTuitionPaymentState
    {
        return StudentTuitionPaymentState::from($this->state);
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<TuitionAccount, $this> */
    public function tuitionAccount(): BelongsTo
    {
        return $this->belongsTo(TuitionAccount::class);
    }

    /** @return BelongsTo<StudentWallet, $this> */
    public function studentWallet(): BelongsTo
    {
        return $this->belongsTo(StudentWallet::class);
    }

    /**
     * Whether movement was actually proven on chain.
     *
     * Deliberately narrower than "has a reference": the reference is a claim
     * until a receipt confirms it, and a rejected receipt settles nothing.
     */
    public function isVerified(): bool
    {
        return $this->settlement_verdict === 'verified'
            && $this->state === StudentTuitionPaymentState::VERIFIED->value;
    }
}
