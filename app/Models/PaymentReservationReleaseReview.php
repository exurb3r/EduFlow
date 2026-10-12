<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * An independent decision on a proposed capacity release.
 *
 * The reviewer must not be the proposer and must not be the staff member who
 * took the original hold, so releasing capacity is maker/checker on both
 * levels rather than one person undoing their own reservation.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $payment_reservation_release_id
 * @property int $payment_reservation_id
 * @property int $reviewed_by
 * @property string $decision
 * @property string $reason
 * @property string $proposal_digest
 * @property string $review_digest
 * @property-read PaymentReservationRelease|null $release
 */
class PaymentReservationReleaseReview extends Model
{
    protected $fillable = ['organization_id', 'payment_reservation_release_id', 'payment_reservation_id',
        'reviewed_by', 'decision', 'reason', 'proposal_digest', 'review_digest'];

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $review): void {
            /** @var PaymentReservationRelease|null $release */
            $release = PaymentReservationRelease::query()->find($review->payment_reservation_release_id);

            if ($release === null || ! $review->hasValidEvidence($release)) {
                throw new LogicException('Release review requires the exact proposed capacity release.');
            }
        });
        static::updating(function (): never {
            throw new LogicException('Release reviews are append-only; a decided release is final for this hold.');
        });
        static::deleting(function (): never {
            throw new LogicException('Release review evidence cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'payment_reservation_release_id' => 'integer',
            'payment_reservation_id' => 'integer', 'reviewed_by' => 'integer'];
    }

    public function hasValidEvidence(PaymentReservationRelease $release): bool
    {
        try {
            $reservation = PaymentReservation::query()->find($release->payment_reservation_id);

            return $release->exists && $reservation instanceof PaymentReservation && $release->hasValidEvidence($reservation)
                && $this->payment_reservation_release_id === $release->id
                && $this->payment_reservation_id === $release->payment_reservation_id
                && $this->organization_id === $release->organization_id
                && $this->reviewed_by > 0
                && $this->reviewed_by !== $release->proposed_by
                && $this->reviewed_by !== $reservation->reserved_by
                && in_array($this->decision, ['approve_release', 'reject', 'hold'], true)
                && Str::length($this->reason) > 0 && Str::length($this->reason) <= 1000
                && hash_equals($release->content_digest, $this->proposal_digest)
                && hash_equals($this->review_digest, PaymentIntent::digest($this->content()));
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
            'payment_reservation_release_id' => $this->payment_reservation_release_id,
            'payment_reservation_id' => $this->payment_reservation_id,
            'reviewed_by' => $this->reviewed_by,
            'decision' => $this->decision,
            'reason' => $this->reason,
            'proposal_digest' => $this->proposal_digest,
        ];
    }

    /** @return BelongsTo<PaymentReservationRelease, $this> */
    public function release(): BelongsTo
    {
        return $this->belongsTo(PaymentReservationRelease::class, 'payment_reservation_release_id');
    }

    /** @return array<string, mixed> */
    public function evidence(): array
    {
        return [
            'id' => $this->id,
            'decision' => $this->decision,
            'reviewed_by' => $this->reviewed_by,
            'payment_reservation_id' => $this->payment_reservation_id,
            'proposal_digest' => $this->proposal_digest,
            'review_digest' => $this->review_digest,
            'released' => $this->decision === 'approve_release',
            'funds_reserved' => $this->decision === 'approve_release',
            'payment_approved' => false,
            'external_funds_locked' => false,
            'can_execute' => false,
            'local_accounts_changed' => false,
        ];
    }
}
