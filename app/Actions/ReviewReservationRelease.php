<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\FundingWindow;
use App\Models\FundingWindowApproval;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\PaymentReservation;
use App\Models\PaymentReservationRelease;
use App\Models\PaymentReservationReleaseReview;
use App\Models\User;
use App\Services\InstallationInstitution;
use App\Services\ReservationCapacity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Independently decide a proposed capacity release.
 *
 * Approving returns capacity to the department's consumable total. It does not
 * touch the reservation, the cumulative chain, the invoice or the wallet, and
 * it is not a payment approval: `can_execute` stays false and no submission
 * identity exists. The whole hold history remains reproducible afterwards.
 */
final readonly class ReviewReservationRelease
{
    /** @var list<string> */
    public const array DECISIONS = ['approve_release', 'reject', 'hold'];

    public function __construct(private InstallationInstitution $institutions, private ReservationCapacity $capacity) {}

    public function handle(User $reviewer, PaymentReservationRelease $release, string $expectedDigest, string $decision, string $reason): PaymentReservationReleaseReview
    {
        Gate::forUser($reviewer)->authorize('review', $release);

        if (! hash_equals($release->content_digest, $expectedDigest)
            || ! in_array($decision, self::DECISIONS, true)
            || trim($reason) === '' || Str::length($reason) > 1000) {
            throw ValidationException::withMessages(['review' => 'The exact proposal digest, an explicit decision and a reason are required.']);
        }

        $institution = $this->institutions->require();

        return DB::transaction(function () use ($reviewer, $release, $expectedDigest, $decision, $reason, $institution): PaymentReservationReleaseReview {
            Organization::query()->whereKey($institution->id)->lockForUpdate()->firstOrFail();

            /** @var PaymentReservationRelease $stored */
            $stored = PaymentReservationRelease::query()
                ->where('organization_id', $institution->id)
                ->whereKey($release->id)
                ->lockForUpdate()
                ->firstOrFail();
            Gate::forUser($reviewer)->authorize('review', $stored);

            /** @var PaymentReservation|null $reservation */
            $reservation = PaymentReservation::query()
                ->where('organization_id', $institution->id)
                ->whereKey($stored->payment_reservation_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($reservation === null || ! $stored->hasValidEvidence($reservation)) {
                throw ValidationException::withMessages(['review' => 'Held capacity evidence must be intact before a release can be decided.']);
            }

            if (! hash_equals($stored->content_digest, $expectedDigest)) {
                throw ValidationException::withMessages(['expected_digest' => 'The exact stored proposal digest is required.']);
            }

            /** @var PaymentReservationReleaseReview|null $existing */
            $existing = PaymentReservationReleaseReview::query()
                ->where('payment_reservation_release_id', $stored->id)
                ->first();

            if ($existing !== null) {
                // Same-key retry returns the recorded evidence rather than
                // deciding twice or silently changing the outcome.
                if ($existing->reviewed_by !== $reviewer->id || $existing->decision !== $decision
                    || $existing->reason !== $reason || ! $existing->hasValidEvidence($stored)) {
                    throw ValidationException::withMessages(['release' => 'This release was already decided; a changed decision requires a new hold, not a rewritten review.']);
                }

                return $existing;
            }

            if ($reviewer->id === $stored->proposed_by || $reviewer->id === $reservation->reserved_by) {
                throw ValidationException::withMessages(['review' => 'Releasing capacity requires a reviewer who neither proposed the release nor took the original hold.']);
            }

            /** @var PaymentIntent|null $intent */
            $intent = PaymentIntent::query()->find($reservation->payment_intent_id);

            if ($intent === null) {
                throw ValidationException::withMessages(['review' => 'The held draft no longer exists; independent investigation required.']);
            }

            $review = new PaymentReservationReleaseReview([
                'organization_id' => $institution->id,
                'payment_reservation_release_id' => $stored->id,
                'payment_reservation_id' => $reservation->id,
                'reviewed_by' => $reviewer->id,
                'decision' => $decision,
                'reason' => $reason,
                'proposal_digest' => $stored->content_digest,
            ]);
            $review->review_digest = PaymentIntent::digest($review->content());
            $review->save();

            if ($decision === 'approve_release') {
                // Re-verify the whole chain after the fact. A release that
                // would leave the history unreproducible is refused here
                // rather than discovered later.
                $window = FundingWindow::query()
                    ->where('organization_id', $institution->id)
                    ->findOrFail(FundingWindowApproval::query()->findOrFail($reservation->funding_window_approval_id)->funding_window_id);

                $this->capacity->verify($institution->id);
            }

            activity('finance')->causedBy($reviewer)->performedOn($review)->event('reservation_release_reviewed')
                ->withProperties([
                    'payment_reservation_id' => $reservation->id,
                    'decision' => $decision,
                    'released_base_units' => (string) ($reservation->amount_base_units + $reservation->max_fee_base_units),
                    'review_digest' => $review->review_digest,
                    'can_execute' => false,
                    'payments_submitted' => 0,
                ])
                ->log('Capacity release decided independently; no payment authority, no funds moved');

            return $review;
        }, 3);
    }
}
