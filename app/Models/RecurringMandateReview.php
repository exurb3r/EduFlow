<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Throwable;

/**
 * An independent reviewer's decision on a proposed recurring mandate.
 *
 * The whole safety of the autonomous lane rests here. §14.7 is explicit: no
 * agent or policy maker may self-authorize a mandate, so the reviewer must be a
 * different human from the person who prepared it, and must never be a service
 * account. Append-only by construction — one terminal decision per mandate,
 * decided once.
 *
 * `approve_mandate` authorizes a *class* of payment. It authorizes nothing on
 * its own: a still-approved mandate with a zero per-occurrence ceiling releases
 * nothing at all, and every occurrence is separately admitted by
 * `MandateReleaseEvaluator` against live evidence.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $recurring_mandate_id
 * @property int $reviewed_by
 * @property string $decision
 * @property string $reason
 * @property string $mandate_digest
 * @property string $review_digest
 * @property-read RecurringMandate|null $mandate
 */
class RecurringMandateReview extends Model
{
    protected $fillable = ['organization_id', 'recurring_mandate_id', 'reviewed_by', 'decision',
        'reason', 'mandate_digest', 'review_digest'];

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $review): void {
            /** @var RecurringMandate|null $mandate */
            $mandate = RecurringMandate::query()->find($review->recurring_mandate_id);

            if ($mandate === null || ! $review->hasValidEvidence($mandate)) {
                throw new LogicException('Recurring mandate review requires the exact proposed mandate scope.');
            }
        });
        static::updating(function (): never {
            throw new LogicException('Recurring mandate reviews are append-only; a decided mandate is final.');
        });
        static::deleting(function (): never {
            throw new LogicException('Recurring mandate review evidence cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'recurring_mandate_id' => 'integer', 'reviewed_by' => 'integer'];
    }

    public function hasValidEvidence(RecurringMandate $mandate): bool
    {
        try {
            return $mandate->exists && $mandate->hasValidSnapshot()
                && $this->recurring_mandate_id === $mandate->id
                && $this->organization_id === $mandate->organization_id
                && $this->reviewed_by > 0
                // A reviewer cannot be the person who wrote the mandate: this
                // is the separation that makes "authorized" mean something.
                && $this->reviewed_by !== $mandate->prepared_by
                && in_array($this->decision, ['approve_mandate', 'reject', 'hold', 'revoke'], true)
                && mb_strlen($this->reason) > 0 && mb_strlen($this->reason) <= 1000
                && hash_equals($mandate->snapshot_digest, $this->mandate_digest)
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
            'recurring_mandate_id' => $this->recurring_mandate_id,
            'reviewed_by' => $this->reviewed_by,
            'decision' => $this->decision,
            'reason' => $this->reason,
            'mandate_digest' => $this->mandate_digest,
        ];
    }

    public function approves(): bool
    {
        return $this->decision === 'approve_mandate';
    }

    /** @return BelongsTo<RecurringMandate, $this> */
    public function mandate(): BelongsTo
    {
        return $this->belongsTo(RecurringMandate::class, 'recurring_mandate_id');
    }

    /** @return array<string, mixed> */
    public function evidence(): array
    {
        return [
            'id' => $this->id,
            'decision' => $this->decision,
            'reviewed_by' => $this->reviewed_by,
            'recurring_mandate_id' => $this->recurring_mandate_id,
            'mandate_digest' => $this->mandate_digest,
            'review_digest' => $this->review_digest,
            'mandate_authorized' => $this->approves(),
            // Authorization of a class is still not authorization of a
            // payment, and never of an execution.
            'payment_approved' => false,
            'funds_reserved' => false,
            'external_funds_locked' => false,
            'can_execute' => false,
            'local_accounts_changed' => false,
        ];
    }
}
