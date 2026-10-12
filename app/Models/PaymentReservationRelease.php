<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * A proposed return of held capacity to the department budget.
 *
 * A release never edits the reservation or its cumulative chain. The chain in
 * `PaymentReservation::snapshot['prior_reserved_base_units']` is the durable
 * record of what was held, in order, and stays intact and verifiable forever.
 * Releasing is a separate append-only fact that reduces capacity *consumed*,
 * which is derived at read time, so an auditor can still see that the hold
 * happened and who ended it.
 *
 * @property int $id
 * @property string $request_key
 * @property int $organization_id
 * @property int $payment_reservation_id
 * @property int $proposed_by
 * @property string $reason
 * @property string $source_digest
 * @property string $content_digest
 * @property array<string, mixed> $snapshot
 * @property string $snapshot_digest
 * @property-read PaymentReservation|null $reservation
 * @property-read PaymentReservationReleaseReview|null $review
 */
class PaymentReservationRelease extends Model
{
    protected $fillable = ['request_key', 'organization_id', 'payment_reservation_id', 'proposed_by',
        'reason', 'source_digest', 'content_digest', 'snapshot', 'snapshot_digest'];

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $release): void {
            /** @var PaymentReservation|null $reservation */
            $reservation = PaymentReservation::query()->find($release->payment_reservation_id);

            if ($reservation === null || ! $release->hasValidEvidence($reservation)) {
                throw new LogicException('Reservation release requires an exact, still-held capacity record.');
            }
        });
        static::updating(function (): never {
            throw new LogicException('Reservation releases are append-only; a decided release is final for this hold.');
        });
        static::deleting(function (): never {
            throw new LogicException('Reservation release evidence cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'payment_reservation_id' => 'integer', 'proposed_by' => 'integer',
            'snapshot' => 'array'];
    }

    public function hasValidEvidence(PaymentReservation $reservation): bool
    {
        try {
            $snapshot = $this->getAttribute('snapshot');
            $intent = PaymentIntent::query()->find($reservation->payment_intent_id);

            return is_array($snapshot) && $reservation->exists && $intent instanceof PaymentIntent
                && $intent->hasValidSnapshot()
                && $reservation->id === $this->payment_reservation_id
                && $reservation->organization_id === $this->organization_id
                && $this->proposed_by > 0 && $this->proposed_by !== $reservation->reserved_by
                && Str::isUuid($this->request_key) && $this->request_key === strtolower($this->request_key)
                && hash_equals($reservation->snapshot_digest, $this->source_digest)
                && ($snapshot['schema_version'] ?? null) === 1
                && ($snapshot['institution_id'] ?? null) === $this->organization_id
                && ($snapshot['payment_reservation_id'] ?? null) === $reservation->id
                && ($snapshot['payment_intent_id'] ?? null) === $reservation->payment_intent_id
                && ($snapshot['invoice_id'] ?? null) === $reservation->invoice_id
                && ($snapshot['proposed_by'] ?? null) === $this->proposed_by
                && ($snapshot['request_key'] ?? null) === $this->request_key
                && ($snapshot['reservation_digest'] ?? null) === $reservation->snapshot_digest
                && ($snapshot['released_base_units'] ?? null) === (string) ($reservation->amount_base_units + $reservation->max_fee_base_units)
                && ($snapshot['funding_window_approval_id'] ?? null) === $reservation->funding_window_approval_id
                && ($snapshot['reason'] ?? null) === $this->reason
                && hash_equals($this->snapshot_digest, PaymentIntent::digest($snapshot))
                && hash_equals($this->content_digest, PaymentIntent::digest($this->content()));
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function content(): array
    {
        return [
            'schema_version' => 1,
            'institution_id' => $this->organization_id,
            'payment_reservation_id' => $this->payment_reservation_id,
            'payment_intent_id' => $this->snapshot['payment_intent_id'] ?? null,
            'proposed_by' => $this->proposed_by,
            'request_key' => $this->request_key,
            'source_digest' => $this->source_digest,
            'released_base_units' => $this->snapshot['released_base_units'] ?? null,
            'reason' => $this->reason,
        ];
    }

    /** @return BelongsTo<PaymentReservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(PaymentReservation::class, 'payment_reservation_id');
    }

    /** @return HasOne<PaymentReservationReleaseReview, $this> */
    public function review(): HasOne
    {
        return $this->hasOne(PaymentReservationReleaseReview::class);
    }

    /** @return array<string, mixed> */
    public function evidence(): array
    {
        $review = $this->review;
        $released = $review?->decision === 'approve_release';

        return [
            'id' => $this->id,
            'request_key' => $this->request_key,
            'payment_reservation_id' => $this->payment_reservation_id,
            'content' => $this->content(),
            'content_digest' => $this->content_digest,
            'content_valid' => $this->reservation instanceof PaymentReservation && $this->hasValidEvidence($this->reservation),
            'review' => $review?->decision,
            'decided' => $review !== null,
            'released' => $released,
            // Capacity stays held until a reviewer releases it. A proposal
            // that nobody has decided must never look like free money.
            'funds_reserved' => ! $released,
            'payment_approved' => false,
            'external_funds_locked' => false,
            'can_execute' => false,
            'local_accounts_changed' => false,
        ];
    }
}
