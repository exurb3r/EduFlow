<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * A durable, exactly-once record that an authorized payment is owed to a rail.
 *
 * Written in the same transaction as the authorization, so an approved payment
 * can never exist without a durable obligation to submit it, and a crash
 * between the two is impossible by construction rather than by retry.
 *
 * Identity is immutable; only operational state moves. This mirrors
 * `FinanceWorkflowRun`: what was authorized and what is bound never change,
 * while queue progress is allowed to advance.
 *
 * @property int $id
 * @property string $request_key
 * @property int $organization_id
 * @property int $payment_authorization_id
 * @property int $payment_reservation_id
 * @property int $payment_intent_id
 * @property int $invoice_id
 * @property string $provider_idempotency_key
 * @property string|null $provider_handle
 * @property string $state
 * @property string|null $stage
 * @property int $attempts
 * @property int $max_attempts
 * @property string|null $last_error
 * @property Carbon|null $heartbeat_at
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $dispatched_at
 * @property array<string, mixed>|null $result
 * @property string|null $result_digest
 * @property array<string, mixed> $snapshot
 * @property string $snapshot_digest
 * @property-read PaymentAuthorization|null $authorization
 * @property-read PaymentReservation|null $reservation
 * @property-read PaymentIntent|null $intent
 */
class PaymentSubmissionOutbox extends Model
{
    /** @var list<string> States that mean work is still owed. */
    public const array OPEN_STATES = ['queued', 'running', 'unknown', 'accepted', 'submitted'];

    /**
     * States a submission worker may pick up.
     *
     * Narrower than OPEN_STATES on purpose. A `submitted` entry is still owed
     * work — its settlement is unproven and its capacity stays held — but it
     * has already been sent to the rail. Handing it to a worker again would be
     * a second submission, so only reconciliation may touch it.
     *
     * Running and unknown entries are also excluded: the first claim fences a
     * possible signer call, even when its response was lost. Only observation
     * or independent investigation may resolve them.
     *
     * `accepted` is excluded for the same reason and one more: on an
     * asynchronous wallet the rail has already taken the transfer and issued a
     * transaction id, but no on-chain hash exists yet. That is an in-flight
     * payment, not work still to do.
     *
     * @var list<string>
     */
    public const array DISPATCHABLE_STATES = ['queued'];

    /**
     * States in which the rail already owns a transfer and only observation is
     * left. Neither may ever be handed to a submitter.
     *
     * @var list<string>
     */
    public const array IN_FLIGHT_STATES = ['accepted', 'submitted'];

    /** @var list<string> States that are terminal for this entry. */
    public const array TERMINAL_STATES = ['blocked', 'failed', 'completed', 'paused'];

    protected $fillable = ['request_key', 'organization_id', 'payment_authorization_id', 'payment_reservation_id',
        'payment_intent_id', 'invoice_id', 'provider_idempotency_key', 'provider_handle', 'state', 'stage', 'attempts', 'max_attempts',
        'last_error', 'heartbeat_at', 'next_attempt_at', 'dispatched_at', 'result', 'result_digest',
        'snapshot', 'snapshot_digest'];

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            if (! $entry->hasValidSnapshot() || ! $entry->bindsCurrentAuthorization()) {
                throw new LogicException('Submission outbox requires an approved, unexpired authorization on exact bound evidence.');
            }
        });

        static::updating(function (self $entry): void {
            // Identity and bound evidence never move. Only progress may.
            foreach (['request_key', 'organization_id', 'payment_authorization_id', 'payment_reservation_id',
                'payment_intent_id', 'invoice_id', 'provider_idempotency_key', 'max_attempts',
                'snapshot', 'snapshot_digest'] as $immutable) {
                if ($entry->getAttribute($immutable) !== $entry->getOriginal($immutable)) {
                    throw new LogicException('Submission outbox identity and bound evidence are immutable.');
                }
            }

            // A concluded entry is final: it may not be reopened, retried
            // under a new identity, or quietly turned back into open work.
            if (in_array($entry->getOriginal('state'), self::TERMINAL_STATES, true)
                && $entry->state !== $entry->getOriginal('state')) {
                throw new LogicException('A concluded submission cannot be re-decided or reopened.');
            }

            if ($entry->state === 'queued' && $entry->getOriginal('state') !== 'queued') {
                throw new LogicException('A claimed submission cannot become dispatchable again; reconcile the existing attempt.');
            }

            if (! in_array($entry->state, array_merge(self::OPEN_STATES, self::TERMINAL_STATES), true)) {
                throw new LogicException('Unknown submission state.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Submission evidence cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'payment_authorization_id' => 'integer',
            'payment_reservation_id' => 'integer', 'payment_intent_id' => 'integer', 'invoice_id' => 'integer',
            'attempts' => 'integer', 'max_attempts' => 'integer', 'snapshot' => 'array', 'result' => 'array',
            'heartbeat_at' => 'datetime', 'next_attempt_at' => 'datetime', 'dispatched_at' => 'datetime'];
    }

    public function hasValidSnapshot(): bool
    {
        try {
            $snapshot = $this->getAttribute('snapshot');

            return is_array($snapshot)
                && ($snapshot['schema_version'] ?? null) === 1
                && ($snapshot['institution_id'] ?? null) === $this->organization_id
                && ($snapshot['request_key'] ?? null) === $this->request_key
                && ($snapshot['payment_authorization_id'] ?? null) === $this->payment_authorization_id
                && ($snapshot['payment_reservation_id'] ?? null) === $this->payment_reservation_id
                && ($snapshot['payment_intent_id'] ?? null) === $this->payment_intent_id
                && ($snapshot['invoice_id'] ?? null) === $this->invoice_id
                && ($snapshot['provider_idempotency_key'] ?? null) === $this->provider_idempotency_key
                && Str::isUuid($this->request_key)
                && hash_equals($this->snapshot_digest, PaymentIntent::digest($snapshot));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Re-read the authorization rather than trusting what was true when the
     * entry was written.
     *
     * A queued entry whose approval has since expired is still owed work, but
     * it is no longer an authorized payment and must not be submitted on the
     * strength of a stale record.
     */
    public function bindsCurrentAuthorization(): bool
    {
        $authorization = $this->authorization;

        return $authorization instanceof PaymentAuthorization
            && $authorization->hasValidEvidence(
                $this->intent instanceof PaymentIntent ? $this->intent : PaymentIntent::query()->findOrFail($this->payment_intent_id),
                $this->reservation instanceof PaymentReservation ? $this->reservation : PaymentReservation::query()->findOrFail($this->payment_reservation_id),
            )
            && $authorization->decision === 'approve_payment';
    }

    public function isOpen(): bool
    {
        return in_array($this->state, self::OPEN_STATES, true);
    }

    /** @return BelongsTo<PaymentAuthorization, $this> */
    public function authorization(): BelongsTo
    {
        return $this->belongsTo(PaymentAuthorization::class, 'payment_authorization_id');
    }

    /** @return BelongsTo<PaymentReservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(PaymentReservation::class, 'payment_reservation_id');
    }

    /** @return BelongsTo<PaymentIntent, $this> */
    public function intent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class, 'payment_intent_id');
    }

    /** @return HasMany<PaymentSubmissionAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentSubmissionAttempt::class);
    }

    /**
     * What is durably owed.
     *
     * `can_execute` is deliberately false: an entry existing is not authority
     * to transfer. Execution belongs to a separately enabled background worker;
     * a provider handle or hash alone is never settlement proof.
     */
    public function evidence(): array
    {
        $authorization = $this->authorization?->evidence();

        return [
            'id' => $this->id,
            'request_key' => $this->request_key,
            'provider_idempotency_key' => $this->provider_idempotency_key,
            'state' => $this->state,
            'stage' => $this->stage,
            'attempts' => $this->attempts,
            'max_attempts' => $this->max_attempts,
            'attempts_recorded' => $this->attempts,
            'last_error' => $this->last_error,
            'dispatched' => $this->dispatched_at !== null,
            'payment_approved' => (bool) ($authorization['payment_approved'] ?? false),
            'authorization_unexpired' => (bool) ($authorization['authorization_unexpired'] ?? false),
            'amount_base_units' => $this->snapshot['amount_base_units'] ?? null,
            'max_fee_base_units' => $this->snapshot['max_fee_base_units'] ?? null,
            'currency' => $this->snapshot['currency'] ?? null,
            'chain' => $this->snapshot['chain'] ?? null,
            'chain_id' => $this->snapshot['chain_id'] ?? null,
            'recipient_address' => $this->snapshot['recipient_address'] ?? null,
            'is_fake' => $this->snapshot['is_fake'] ?? null,
            'funds_reserved' => true,
            'external_funds_locked' => false,
            'can_execute' => false,
            'payments_submitted' => match (true) {
                $this->provider_handle !== null || ($this->result['provider_reference'] ?? null) !== null => 1,
                in_array($this->state, ['running', 'unknown'], true) => null,
                default => 0,
            },
            'local_accounts_changed' => false,
        ];
    }
}
