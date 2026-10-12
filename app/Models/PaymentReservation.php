<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * @property int $id
 * @property string $reservation_key
 * @property int $organization_id
 * @property int $payment_intent_id
 * @property int $invoice_id
 * @property int $funding_window_approval_id
 * @property int $reserved_by
 * @property int $amount_base_units
 * @property int $max_fee_base_units
 * @property array<string, mixed> $snapshot
 * @property string $snapshot_digest
 * @property Carbon $created_at
 * @property-read PaymentIntent|null $intent
 * @property-read PaymentAuthorization|null $authorization
 * @property-read PaymentReservationRelease|null $release
 */
class PaymentReservation extends Model
{
    protected $fillable = ['reservation_key', 'organization_id', 'payment_intent_id', 'invoice_id', 'funding_window_approval_id',
        'reserved_by', 'amount_base_units', 'max_fee_base_units', 'snapshot', 'snapshot_digest'];

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $reservation): void {
            /** @var PaymentIntent|null $intent */
            $intent = PaymentIntent::query()->find($reservation->payment_intent_id);
            /** @var FundingWindowApproval|null $approval */
            $approval = FundingWindowApproval::query()->find($reservation->funding_window_approval_id);
            if ($intent === null || $approval === null || ! $reservation->hasValidEvidence($intent, $approval)) {
                throw new LogicException('Reservation requires exact draft and independently reviewed capacity evidence.');
            }
        });
        static::updating(function (): never {
            throw new LogicException('Payment reservations are immutable; release requires reviewed evidence.');
        });
        static::deleting(function (): never {
            throw new LogicException('Reservation evidence cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'payment_intent_id' => 'integer', 'invoice_id' => 'integer',
            'funding_window_approval_id' => 'integer', 'reserved_by' => 'integer', 'amount_base_units' => 'integer',
            'max_fee_base_units' => 'integer', 'snapshot' => 'array'];
    }

    public function hasValidEvidence(PaymentIntent $intent, FundingWindowApproval $approval): bool
    {
        try {
            $s = $this->getAttribute('snapshot');

            return is_array($s) && $intent->exists && $intent->hasValidSnapshot() && $intent->id === $this->payment_intent_id
                && $intent->invoice_id === $this->invoice_id && $intent->organization_id === $this->organization_id
                && $approval->exists && $approval->id === $this->funding_window_approval_id && $approval->organization_id === $this->organization_id
                && $this->reserved_by > 0 && Str::isUuid($this->reservation_key) && $this->reservation_key === strtolower($this->reservation_key)
                && $this->amount_base_units > 0 && $this->amount_base_units === $intent->amount_base_units
                && $this->max_fee_base_units >= 0 && $this->max_fee_base_units === $intent->max_fee_base_units
                && $this->amount_base_units <= PHP_INT_MAX - $this->max_fee_base_units
                && ($s['schema_version'] ?? null) === 1 && ($s['reservation_key'] ?? null) === $this->reservation_key
                && ($s['institution_id'] ?? null) === $this->organization_id && ($s['payment_intent_id'] ?? null) === $this->payment_intent_id
                && ($s['invoice_id'] ?? null) === $this->invoice_id && ($s['funding_window_approval_id'] ?? null) === $approval->id
                && ($s['reserved_by'] ?? null) === $this->reserved_by && ($s['intent_digest'] ?? null) === $intent->snapshot_digest
                && ($s['approval_digest'] ?? null) === $approval->approval_digest
                && ($s['amount_base_units'] ?? null) === (string) $this->amount_base_units
                && ($s['max_fee_base_units'] ?? null) === (string) $this->max_fee_base_units
                && ($s['total_base_units'] ?? null) === (string) ($this->amount_base_units + $this->max_fee_base_units)
                && hash_equals($this->snapshot_digest, PaymentIntent::digest($s));
        } catch (Throwable) {
            return false;
        }
    }

    /** @return BelongsTo<PaymentIntent, $this> */
    public function intent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class, 'payment_intent_id');
    }

    /** @return HasOne<PaymentAuthorization, $this> */
    public function authorization(): HasOne
    {
        return $this->hasOne(PaymentAuthorization::class);
    }

    /** @return HasOne<PaymentReservationRelease, $this> */
    public function release(): HasOne
    {
        return $this->hasOne(PaymentReservationRelease::class);
    }

    /**
     * Whether an independent reviewer returned this hold's capacity.
     *
     * Deliberately not a column: release is an append-only fact, so the
     * reservation row is never updated to say it stopped consuming.
     */
    public function isReleased(): bool
    {
        return PaymentReservationReleaseReview::query()
            ->where('payment_reservation_id', $this->id)
            ->where('decision', 'approve_release')
            ->exists();
    }

    /** @return array<string, mixed> */
    public function evidence(): array
    {
        $released = $this->isReleased();

        return ['id' => $this->id, 'reservation_key' => $this->reservation_key, 'payment_intent_id' => $this->payment_intent_id,
            'snapshot' => $this->snapshot, 'snapshot_digest' => $this->snapshot_digest,
            /**
             * The hold still happened and stays in the chain. Releasing it
             * returns capacity; it does not un-take the reservation.
             */
            'state' => $released ? 'released' : 'held',
            'release_decision' => $this->release?->review?->decision,
            'funds_reserved' => ! $released, 'reservation_scope' => 'application_capacity_only', 'payment_approved' => false,
            'can_execute' => false, 'external_funds_locked' => false, 'local_accounts_changed' => false,
            'is_fake' => $this->snapshot['balance_observation']['is_fake'] ?? null];
    }
}
