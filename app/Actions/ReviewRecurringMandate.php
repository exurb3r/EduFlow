<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\PaymentIntent;
use App\Models\RecurringMandate;
use App\Models\RecurringMandateReview;
use App\Models\User;
use App\Services\InstallationInstitution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The only thing that can turn a proposed mandate into an authorized one.
 *
 * §14.7 is explicit: no agent or policy maker may self-authorize a mandate. So
 * the reviewer must be a human other than the preparer, and a service account is
 * not a human — admitting one here would be the whole failure the constraint
 * exists to prevent. Exactly one terminal decision is allowed per mandate, and
 * it is append-only, so "authorized" cannot drift after the fact.
 *
 * Approving authorizes a **class** of payment. It authorizes no payment: a
 * released occurrence still needs its own funding window, reservation and outbox
 * entry, and if the approved ceiling is zero it releases nothing at all.
 */
final readonly class ReviewRecurringMandate
{
    public function __construct(private InstallationInstitution $institutions) {}

    public function handle(User $reviewer, RecurringMandate $mandate, string $expectedDigest, string $decision, string $reason): RecurringMandateReview
    {
        Gate::forUser($reviewer)->authorize('approve', $mandate);
        $institution = $this->institutions->require();

        if (! in_array($decision, ['approve_mandate', 'reject', 'hold', 'revoke'], true)) {
            throw ValidationException::withMessages(['decision' => 'Unknown mandate decision.']);
        }

        if (trim($reason) === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => 'A decision on a standing mandate requires a substantive independent review reference.']);
        }

        return DB::transaction(function () use ($reviewer, $institution, $mandate, $expectedDigest, $decision, $reason): RecurringMandateReview {
            /** @var RecurringMandate $stored */
            $stored = RecurringMandate::query()->where('organization_id', $institution->id)->whereKey($mandate->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($reviewer)->authorize('approve', $stored);

            if (! $stored->hasValidSnapshot() || ! hash_equals($stored->snapshot_digest, $expectedDigest)) {
                throw ValidationException::withMessages(['expected_digest' => 'Exact intact mandate scope digest required.']);
            }

            // One decision of each kind, decided once. Approval and revocation
            // are both single acts, but they are different acts, and a lane
            // that cannot be revoked is a lane nobody can switch off.
            if (RecurringMandateReview::query()->where('recurring_mandate_id', $stored->id)
                ->where('decision', $decision)->exists()) {
                throw ValidationException::withMessages(['mandate' => 'This decision has already been made on this mandate; a decided mandate is final.']);
            }

            // The separator, and the reason the autonomous lane is not a
            // persona-swap risk: the approver is a different person, verified
            // as such by identity rather than by a flag a caller can pass.
            if ($stored->prepared_by === $reviewer->id) {
                throw ValidationException::withMessages(['mandate' => 'A mandate may not be authorized by the person who wrote it.']);
            }

            $review = new RecurringMandateReview([
                'organization_id' => $institution->id,
                'recurring_mandate_id' => $stored->id,
                'reviewed_by' => $reviewer->id,
                'decision' => $decision,
                'reason' => $reason,
                'mandate_digest' => $stored->snapshot_digest,
            ]);
            // The digest covers the decision, so it can only be stamped once
            // the decision exists — which is why this is built and stamped
            // rather than created in one pass.
            $review->review_digest = PaymentIntent::digest($review->content());
            $review->save();

            // A `hold` leaves the mandate a draft; nothing is authorized.
            $next = match ($decision) {
                'approve_mandate' => 'approved',
                default => $stored->state === 'approved' ? 'revoked' : 'draft',
            };
            $stored->state = $next;
            $stored->save();

            activity('finance')->causedBy($reviewer)->performedOn($review)->event('recurring_mandate_'.$decision)
                ->withProperties([
                    'recurring_mandate_id' => $stored->id,
                    'mandate_digest' => $stored->snapshot_digest,
                    'review_digest' => $review->review_digest,
                    'per_occurrence_ceiling_base_units' => (string) $stored->per_occurrence_ceiling_base_units,
                    // Authorizing a class is never authorizing an execution.
                    'can_execute' => false,
                ])
                ->log($decision === 'approve_mandate'
                    ? 'Recurring mandate class independently authorized; each occurrence is separately admitted by the deterministic evaluator'
                    : 'Mandate decision recorded; no automatic payments authorized');

            return $review;
        }, 3);
    }
}
