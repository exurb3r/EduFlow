<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MandateOccurrence;
use App\Models\MandateRetrospectiveReview;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\RecurringMandate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Records a supervisor's later view on an automatically released occurrence.
 *
 * This is the honest answer to "how often did the admin agree with the
 * system?", and the honesty is the point. §18.6 is explicit that it is
 * **retrospective feedback, not per-item approval and not proof of
 * correctness**, so:
 *
 * - it may only be recorded against an occurrence that was actually released;
 * - it is one value per occurrence, so repeated reviews cannot inflate a rate;
 * - it approves nothing, and `isApproval()` returns false by design.
 *
 * There is deliberately no "approval-as-is rate" for this lane. That metric is
 * defined over proposals a human reviewed and resolved, and an automatic
 * release had no such proposal; computing one here would invent a denominator.
 */
final readonly class RecordRetrospectiveAgreement
{
    public function __construct(private InstallationInstitution $institutions) {}

    public function handle(User $reviewer, MandateOccurrence $occurrence, string $verdict, ?string $comment = null): MandateRetrospectiveReview
    {
        $institution = $this->institutions->require();

        // The mandate is resolved explicitly rather than through the relation,
        // because the reviewer must be authorized against the mandate itself --
        // an institution scope check needs something real to check.
        /** @var RecurringMandate|null $mandate */
        $mandate = $occurrence->mandate ?? RecurringMandate::query()
            ->where('organization_id', $institution->id)->whereKey($occurrence->recurring_mandate_id)->first();

        if ($mandate === null) {
            throw ValidationException::withMessages(['occurrence' => 'The occurrence is not attached to a standing mandate.']);
        }

        Gate::forUser($reviewer)->authorize('view', $mandate);

        if (! in_array($verdict, ['agreed', 'would_have_escalated', 'would_have_declined'], true)) {
            throw ValidationException::withMessages(['verdict' => 'Unknown retrospective verdict.']);
        }

        if ($comment !== null && mb_strlen($comment) > 1000) {
            throw ValidationException::withMessages(['comment' => 'A retrospective comment is limited to 1000 characters.']);
        }

        return DB::transaction(function () use ($reviewer, $institution, $occurrence, $verdict, $comment): MandateRetrospectiveReview {
            /** @var MandateOccurrence $stored */
            $stored = MandateOccurrence::query()->where('organization_id', $institution->id)
                ->whereKey($occurrence->id)->lockForUpdate()->firstOrFail();

            if ($stored->disposition !== 'release' || ! $stored->hasValidEvidence()) {
                throw ValidationException::withMessages([
                    'occurrence' => 'Only a validly released occurrence has an automatic decision to review afterwards.',
                ]);
            }

            if (MandateRetrospectiveReview::query()->where('mandate_occurrence_id', $stored->id)->exists()) {
                throw ValidationException::withMessages([
                    'occurrence' => 'This occurrence has already been reviewed retrospectively; an answer about a past decision is not rewritten.',
                ]);
            }

            $review = new MandateRetrospectiveReview([
                'organization_id' => $institution->id,
                'mandate_occurrence_id' => $stored->id,
                'reviewed_by' => $reviewer->id,
                'verdict' => $verdict,
                'comment' => $comment,
                'occurrence_digest' => $stored->occurrence_digest,
            ]);
            $review->review_digest = PaymentIntent::digest($review->content());
            $review->save();

            activity('finance')->causedBy($reviewer)->performedOn($review)->event('mandate_retrospective_'.$verdict)
                ->withProperties([
                    'mandate_occurrence_id' => $stored->id,
                    'recurring_mandate_id' => $stored->recurring_mandate_id,
                    'verdict' => $verdict,
                    // Labelled at the point of record so it cannot be lost in
                    // translation to a dashboard or an export.
                    'is_retrospective_feedback' => true,
                    'is_approval' => false,
                    'can_execute' => false,
                ])
                ->log('Retrospective supervisor feedback recorded. This is feedback about a past decision, not approval of it.');

            return $review;
        }, 3);
    }

    /**
     * The retrospective agreement figure, over reviewed released occurrences.
     *
     * Reported only over occurrences a supervisor actually looked at. The
     * sampled and unreviewed counts are returned beside it, because a rate over
     * a self-selected sample is not a rate over the lane's output, and hiding
     * that denominator is how such a number gets quoted as something it is not.
     *
     * @return array{reviewed:int, agreed:int, escalated_would_be:int, declined_would_be:int,
     *               released_total:int, unreviewed:int, agreement_rate:?float, is_sample:bool, caveat:string}
     */
    public function rate(Organization $institution): array
    {
        $released = MandateOccurrence::query()->where('organization_id', $institution->id)
            ->where('disposition', 'release')->get();

        $reviews = MandateRetrospectiveReview::query()
            ->where('organization_id', $institution->id)->get()->keyBy('mandate_occurrence_id');

        $valid = $reviews->filter(fn (MandateRetrospectiveReview $review): bool => $review->hasValidEvidence(
            MandateOccurrence::query()->findOrFail($review->mandate_occurrence_id)
        ));

        $agreed = $valid->filter(fn (MandateRetrospectiveReview $review): bool => $review->verdict === 'agreed')->count();
        $reviewed = $valid->count();

        return [
            'reviewed' => $reviewed,
            'agreed' => $agreed,
            'escalated_would_be' => $valid->filter(fn (MandateRetrospectiveReview $r): bool => $r->verdict === 'would_have_escalated')->count(),
            'declined_would_be' => $valid->filter(fn (MandateRetrospectiveReview $r): bool => $r->verdict === 'would_have_declined')->count(),
            'released_total' => $released->count(),
            'unreviewed' => max(0, $released->count() - $reviewed),
            // Null rather than zero when nothing has been reviewed: an empty
            // sample must never render as "the admin agreed with none of them".
            'agreement_rate' => $reviewed === 0 ? null : round($agreed / $reviewed, 4),
            'is_sample' => $reviewed < $released->count(),
            'caveat' => 'Retrospective supervisor feedback, not per-item approval and not proof of correctness. Over a sample of released occurrences.',
        ];
    }

    /**
     * A sample of released occurrences to show a supervisor afterwards.
     *
     * Deterministic and capped, so the same sample can be offered again rather
     * than reshuffling the denominator under a reviewer who is partway through.
     *
     * @return array<int, MandateOccurrence>
     */
    public function sample(Organization $institution, int $limit = 10): array
    {
        return MandateOccurrence::query()
            ->where('organization_id', $institution->id)
            ->where('disposition', 'release')
            ->whereNotIn('id', MandateRetrospectiveReview::query()->where('organization_id', $institution->id)
                ->select('mandate_occurrence_id'))
            ->orderBy('id')
            ->limit(max(1, min(50, $limit)))
            ->get()
            ->all();
    }
}
