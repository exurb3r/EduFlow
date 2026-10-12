<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FundingWindow;
use App\Models\FundingWindowApproval;
use App\Models\PaymentIntent;
use App\Models\PaymentReservation;
use App\Models\PaymentReservationReleaseReview;
use Brick\Math\BigInteger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Two different totals over the same holds, and confusing them would corrupt
 * either the audit trail or the capacity arithmetic.
 *
 * - `chainTotal` is the **historical** cumulative sum in reservation id order.
 *   It is what each hold recorded as `prior_reserved_base_units`, and it must
 *   reproduce exactly, forever. It never decreases, because a released hold
 *   still happened.
 * - `consumingTotal` is what capacity is actually unavailable right now: the
 *   chain total less the exact totals of independently approved releases.
 *
 * A release therefore does not rewrite the chain. It adds a fact, and the
 * admission check for a new hold reads `consumingTotal`. Anyone auditing can
 * still see the hold, its position, who took it and who released it.
 */
final readonly class ReservationCapacity
{
    /** Bounds an institution's hold history so the walk stays reviewable. */
    private const int HISTORY_BOUND = 10_000;

    /**
     * Walk the institution's hold chain and verify it end to end.
     *
     * Each hold is checked against **the window it was taken under**, not
     * against whichever window happens to be current. That distinction is what
     * makes reviewed rollover safe: a later window has a fresh balance
     * observation and may therefore show less cash, and judging an old hold by
     * that newer figure would report validly-taken capacity as corrupt the
     * moment a treasury balance moved. Admission for a *new* hold is judged
     * against the current window separately, by `ReserveVendorPayment`.
     *
     * @return array{total: BigInteger, consuming: BigInteger, released: BigInteger, holds: Collection<int, PaymentReservation>}
     */
    public function verify(int $institutionId): array
    {
        /** @var Collection<int, PaymentReservation> $holds */
        $holds = PaymentReservation::query()->where('organization_id', $institutionId)->orderBy('id')->limit(self::HISTORY_BOUND + 1)->get();

        if ($holds->count() > self::HISTORY_BOUND) {
            throw ValidationException::withMessages(['reservation' => 'Reservation history exceeds reviewed bounds; no new hold permitted.']);
        }

        $released = $this->releasedByReservation($holds);
        $capacityByApproval = $this->capacityByApproval($holds);
        $total = BigInteger::zero();
        $consuming = BigInteger::zero();

        foreach ($holds as $hold) {
            /** @var PaymentIntent|null $heldIntent */
            $heldIntent = PaymentIntent::query()->find($hold->payment_intent_id);
            $capacity = $capacityByApproval[$hold->funding_window_approval_id] ?? null;

            // A released hold drops out of what is consumed, but it stays in
            // the chain and stays verifiable.
            $consumed = $released[$hold->id] ?? false;

            if ($heldIntent === null || $capacity === null
                || ($hold->snapshot['prior_reserved_base_units'] ?? null) !== (string) $total
                || ($hold->snapshot['total_base_units'] ?? null) !== (string) ($hold->amount_base_units + $hold->max_fee_base_units)) {
                throw ValidationException::withMessages(['reservation' => 'Held capacity evidence is incomplete or corrupt; it cannot become spending room.']);
            }

            $total = $total->plus($hold->amount_base_units)->plus($hold->max_fee_base_units);

            if (! $consumed) {
                $consuming = $consuming->plus($hold->amount_base_units)->plus($hold->max_fee_base_units);
            }

            // Bounds bind on what is *actually unavailable*, not on the historical
            // cumulative total. `consuming` is that figure. A released hold
            // still happened and must keep reproducing in
            // `prior_reserved_base_units` above -- that check is the real
            // anti-tamper anchor -- but its money came back, so counting it
            // against the ceiling would charge for capacity twice and, because
            // the chain total never decreases, would eventually freeze the
            // institution permanently no matter how much cash was released.
            // History integrity and admission are deliberately separate
            // questions here.
            if ($consuming->isGreaterThan($capacity['budget_base_units'])
                || $consuming->isGreaterThan($capacity['cash_base_units'])
                || ($hold->snapshot['remaining_budget_base_units'] ?? null)
                    !== (string) BigInteger::of($capacity['budget_base_units'])->minus($total)) {
                throw ValidationException::withMessages(['reservation' => 'Reservation sequence or capacity bounds changed; independent investigation required.']);
            }
        }

        return ['total' => $total, 'consuming' => $consuming,
            'released' => $total->minus($consuming), 'holds' => $holds];
    }

    /**
     * The capacity each hold was actually admitted against.
     *
     * Resolved from the hold's own approval and window, both of which are
     * immutable and undeletable, so this stays stable for the life of the
     * record. A window whose evidence no longer validates frees nothing and
     * admits nothing; it is simply absent, and the hold then fails closed.
     *
     * @param  Collection<int, PaymentReservation>  $holds
     * @return array<int, array{budget_base_units: int, cash_base_units: int}>
     */
    private function capacityByApproval(Collection $holds): array
    {
        if ($holds->isEmpty()) {
            return [];
        }

        $approvals = FundingWindowApproval::query()
            ->whereIn('id', $holds->pluck('funding_window_approval_id')->unique()->filter()->values())
            ->get()
            ->keyBy('id');

        $windows = FundingWindow::query()
            ->whereIn('id', $approvals->pluck('funding_window_id')->filter()->values())
            ->get()
            ->keyBy('id');

        $capacities = [];

        foreach ($approvals as $approval) {
            $window = $windows->get($approval->funding_window_id);
            if (! $window instanceof FundingWindow) {
                continue;
            }
            if (! $window->hasValidSnapshot()) {
                continue;
            }
            if (! $approval->hasValidEvidence($window)) {
                continue;
            }

            $capacities[$approval->id] = [
                'budget_base_units' => (int) $window->snapshot['capacity']['budget_base_units'],
                'cash_base_units' => (int) $window->snapshot['capacity']['cash_base_units'],
            ];
        }

        return $capacities;
    }

    /**
     * Holds with an independently approved release.
     *
     * Only a review that still validates against its proposal and its hold
     * counts, so a corrupt or tampered review frees nothing.
     *
     * @param  Collection<int, PaymentReservation>  $holds
     * @return array<int, true>
     */
    private function releasedByReservation(Collection $holds): array
    {
        if ($holds->isEmpty()) {
            return [];
        }

        $released = [];

        $reviews = PaymentReservationReleaseReview::query()
            ->where('decision', 'approve_release')
            ->whereIn('payment_reservation_id', $holds->modelKeys())
            ->with('release')
            ->get();

        foreach ($reviews as $review) {
            $release = $review->release;

            if ($release === null) {
                continue;
            }

            $reservation = PaymentReservation::query()->find($release->payment_reservation_id);
            if ($reservation === null) {
                continue;
            }
            if (! $release->hasValidEvidence($reservation)) {
                continue;
            }
            if (! $review->hasValidEvidence($release)) {
                continue;
            }

            $released[$reservation->id] = true;
        }

        return $released;
    }

    public function isReleased(PaymentReservation $reservation): bool
    {
        return isset($this->releasedByReservation(
            PaymentReservation::query()->whereKey($reservation->id)->get()
        )[$reservation->id]);
    }
}
