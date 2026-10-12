<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;
use Throwable;

/**
 * One recorded interaction with a rail.
 *
 * An attempt exists whether or not it produced a provider reference. A timeout
 * or null response is recorded as `unknown` and keeps capacity held, because
 * the payment may still have been accepted — that is the case that must be
 * reconciled rather than blindly retried.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $payment_submission_outbox_id
 * @property int $attempt_number
 * @property string $provider_idempotency_key
 * @property string $outcome
 * @property string|null $provider_reference
 * @property array<string, mixed> $request_snapshot
 * @property array<string, mixed>|null $response_snapshot
 * @property Carbon $observed_at
 * @property-read PaymentSubmissionOutbox|null $outbox
 */
class PaymentSubmissionAttempt extends Model
{
    protected $fillable = ['organization_id', 'payment_submission_outbox_id', 'attempt_number',
        'provider_idempotency_key', 'outcome', 'provider_reference', 'request_snapshot',
        'response_snapshot', 'observed_at'];

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $attempt): void {
            /** @var PaymentSubmissionOutbox|null $outbox */
            $outbox = PaymentSubmissionOutbox::query()->find($attempt->payment_submission_outbox_id);

            if ($outbox === null || ! $attempt->hasValidEvidence($outbox)) {
                throw new LogicException('Submission attempt requires an exact durable submission record.');
            }
        });
        static::updating(function (): never {
            throw new LogicException('Submission attempts are append-only; an uncertain outcome is reconciled, not rewritten.');
        });
        static::deleting(function (): never {
            throw new LogicException('Submission attempt evidence cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'payment_submission_outbox_id' => 'integer',
            'attempt_number' => 'integer', 'request_snapshot' => 'array', 'response_snapshot' => 'array',
            'observed_at' => 'datetime'];
    }

    public function hasValidEvidence(PaymentSubmissionOutbox $outbox): bool
    {
        try {
            $request = $this->getAttribute('request_snapshot');

            return is_array($request) && $outbox->exists && $outbox->hasValidSnapshot()
                && $this->payment_submission_outbox_id === $outbox->id
                && $this->organization_id === $outbox->organization_id
                // Dense numbering: the attempt is the (n+1)th of a known entry.
                && $this->attempt_number === $outbox->attempts
                && $this->provider_idempotency_key === $outbox->provider_idempotency_key
                && in_array($this->outcome, ['accepted', 'submitted', 'unknown', 'failed', 'blocked'], true)
                // An accepted attempt has no on-chain reference, and inventing
                // one would be the exact lie this table exists to prevent. What
                // it must carry instead is the rail's own handle, bound to the
                // entry's, so the transfer can be traced and observed later.
                && ($this->outcome !== 'accepted'
                    || (($this->getAttribute('response_snapshot')['detail']['provider_handle'] ?? null) === $outbox->provider_handle
                        && is_string($outbox->provider_handle) && $outbox->provider_handle !== ''))
                && ($request['request_key'] ?? null) === $outbox->request_key
                && ($request['payment_intent_id'] ?? null) === $outbox->payment_intent_id
                && ($request['amount_base_units'] ?? null) === ($outbox->snapshot['amount_base_units'] ?? null)
                && ($request['chain'] ?? null) === ($outbox->snapshot['chain'] ?? null)
                && ($request['recipient_address'] ?? null) === ($outbox->snapshot['recipient_address'] ?? null);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return BelongsTo<PaymentSubmissionOutbox, $this> */
    public function outbox(): BelongsTo
    {
        return $this->belongsTo(PaymentSubmissionOutbox::class, 'payment_submission_outbox_id');
    }

    /** @return array<string, mixed> */
    public function evidence(): array
    {
        return [
            'id' => $this->id,
            'attempt_number' => $this->attempt_number,
            'outcome' => $this->outcome,
            'provider_reference' => $this->provider_reference,
            'observed_at' => $this->observed_at->toIso8601String(),
            // An unknown outcome is explicitly not a failure and not a success.
            'settled' => false,
            'can_execute' => false,
            'payments_submitted' => 0,
        ];
    }
}
