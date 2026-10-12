<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Throwable;

/**
 * A supervisor's later view on an automatically released occurrence.
 *
 * This is the only "did the admin agree" measure that exists for the autonomous
 * lane, and §18.6 requires it to be labelled as what it is: **retrospective
 * feedback, not per-item approval and not proof of correctness.** The class
 * name, the evidence keys and the `isApproval()` helper all exist to make it
 * hard to quote this back as an authorization metric.
 *
 * It is single-valued per occurrence and append-only, so neither a repeated
 * review nor a later disagreement can quietly rewrite an earlier answer.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $mandate_occurrence_id
 * @property int $reviewed_by
 * @property string $verdict
 * @property string|null $comment
 * @property string $occurrence_digest
 * @property string $review_digest
 * @property-read MandateOccurrence|null $occurrence
 */
class MandateRetrospectiveReview extends Model
{
    protected $fillable = ['organization_id', 'mandate_occurrence_id', 'reviewed_by', 'verdict',
        'comment', 'occurrence_digest', 'review_digest'];

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $review): void {
            /** @var MandateOccurrence|null $occurrence */
            $occurrence = MandateOccurrence::query()->find($review->mandate_occurrence_id);

            if ($occurrence === null || ! $review->hasValidEvidence($occurrence)) {
                throw new LogicException('A retrospective review requires the exact released occurrence it comments on.');
            }
        });
        static::updating(function (): never {
            throw new LogicException('Retrospective reviews are append-only; an answer about a past decision is not rewritten.');
        });
        static::deleting(function (): never {
            throw new LogicException('Retrospective review evidence cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'mandate_occurrence_id' => 'integer', 'reviewed_by' => 'integer'];
    }

    public function hasValidEvidence(MandateOccurrence $occurrence): bool
    {
        try {
            return $occurrence->exists && $occurrence->hasValidEvidence()
                // Only a released occurrence has a decision worth reviewing. A
                // blocked or escalated one was not an automatic decision.
                && $occurrence->disposition === 'release'
                && $occurrence->organization_id === $this->organization_id
                && $this->reviewed_by > 0
                && in_array($this->verdict, ['agreed', 'would_have_escalated', 'would_have_declined'], true)
                && mb_strlen((string) $this->comment) <= 1000
                && hash_equals($occurrence->occurrence_digest, $this->occurrence_digest)
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
            'mandate_occurrence_id' => $this->mandate_occurrence_id,
            'reviewed_by' => $this->reviewed_by,
            'verdict' => $this->verdict,
            'comment' => $this->comment,
            'occurrence_digest' => $this->occurrence_digest,
        ];
    }

    /**
     * Is this an authorization? No, and the name is deliberately awkward.
     *
     * Nothing may treat a retrospective agreement as approving, ratifying or
     * re-authorizing the payment it looks back on. The payment was authorized
     * by the mandate, before the fact; this only records a later opinion.
     */
    public function isApproval(): bool
    {
        return false;
    }

    /** @return BelongsTo<MandateOccurrence, $this> */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(MandateOccurrence::class, 'mandate_occurrence_id');
    }

    /** @return array<string, mixed> */
    public function evidence(): array
    {
        return [
            'id' => $this->id,
            'mandate_occurrence_id' => $this->mandate_occurrence_id,
            'reviewed_by' => $this->reviewed_by,
            'verdict' => $this->verdict,
            'comment' => $this->comment,
            'review_digest' => $this->review_digest,
            // Named so it cannot be misread downstream.
            'is_retrospective_feedback' => true,
            'is_approval' => false,
            'payment_approved' => false,
            'can_execute' => false,
            'local_accounts_changed' => false,
        ];
    }
}
