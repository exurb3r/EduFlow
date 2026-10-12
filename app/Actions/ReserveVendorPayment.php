<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\FundingWindow;
use App\Models\FundingWindowApproval;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\PaymentReservation;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ArcBalanceObservation;
use App\Services\FundingWindowContext;
use App\Services\InstallationInstitution;
use App\Services\ReservationCapacity;
use Brick\Math\BigInteger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class ReserveVendorPayment
{
    public function __construct(private InstallationInstitution $institutions, private ArcBalanceObservation $balances,
        private FundingWindowContext $context, private VerifyVendorPaymentDraft $verify, private ReservationCapacity $capacity) {}

    public function handle(User $actor, PaymentIntent $intent, FundingWindowApproval $approval, string $key, string $intentDigest, string $approvalDigest): PaymentReservation
    {
        Gate::forUser($actor)->authorize('reserve', $intent);
        if (! Str::isUuid($key)) {
            throw ValidationException::withMessages(['reservation_key' => 'A stable UUID reservation identity is required.']);
        }
        $key = Str::lower($key);
        $institution = $this->institutions->require();
        /** @var PaymentIntent $initial */
        $initial = PaymentIntent::query()->where('organization_id', $institution->id)->whereKey($intent->id)->firstOrFail();
        /** @var Wallet $wallet */
        $wallet = Wallet::query()->where('organization_id', $institution->id)->whereKey($initial->wallet_id)->firstOrFail();
        // A fresh balance observation is only pointless when the caller is replaying an
        // existing hold, because a hold validly taken stays validly taken. Testing
        // row existence here conflated that with "this bill has ever been reserved",
        // so a bill whose hold was reviewed and released could never be reserved again:
        // the observation was skipped, and the missing observation was then refused as
        // stale state. Released holds hold nothing and grant no reason to skip.
        $replayed = PaymentReservation::query()
            ->where('organization_id', $initial->organization_id)
            ->where('reservation_key', $key)
            ->exists();

        $liveHold = PaymentReservation::query()
            ->where('organization_id', $initial->organization_id)
            ->where('invoice_id', $initial->invoice_id)
            ->get()
            ->reject(fn (PaymentReservation $hold): bool => $hold->isReleased())
            ->isNotEmpty();

        $balance = ($replayed || $liveHold) ? null : $this->balances->capture($wallet);

        return DB::transaction(function () use ($actor, $initial, $approval, $key, $intentDigest, $approvalDigest, $institution, $balance): PaymentReservation {
            Organization::query()->whereKey($institution->id)->lockForUpdate()->firstOrFail();
            /** @var PaymentIntent $stored */
            $stored = PaymentIntent::query()->where('organization_id', $institution->id)->whereKey($initial->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('reserve', $stored);
            /** @var FundingWindowApproval $review */
            $review = FundingWindowApproval::query()->where('organization_id', $institution->id)->whereKey($approval->id)->lockForUpdate()->firstOrFail();
            /** @var FundingWindow $window */
            $window = FundingWindow::query()->where('organization_id', $institution->id)->whereKey($review->funding_window_id)->firstOrFail();
            if (! $review->hasValidEvidence($window) || ! hash_equals($stored->snapshot_digest, $intentDigest)
                || ! hash_equals($review->approval_digest, $approvalDigest) || $stored->wallet_id !== $window->wallet_id || $stored->budget_id !== $window->budget_id) {
                throw ValidationException::withMessages(['reservation' => 'Exact current draft, funding review and matching treasury/budget are required.']);
            }

            /** @var Collection<int, PaymentReservation> $holds */
            $holds = PaymentReservation::query()->where('organization_id', $institution->id)->orderBy('id')->limit(10_001)->get();
            if ($holds->count() > 10_000) {
                throw ValidationException::withMessages(['reservation' => 'Reservation history exceeds reviewed bounds; no new hold permitted.']);
            }
            /**
             * The chain total is historical and reproduces exactly; an approved
             * release does not erase a hold, it stops it consuming capacity.
             * Admission is therefore judged on the consuming total.
             */
            $chain = $this->capacity->verify($institution->id);
            $total = $chain['total'];
            $consuming = $chain['consuming'];
            $released = $chain['released'];
            $same = null;
            foreach ($holds as $hold) {
                // Only a hold that still consumes capacity can conflict with a
                // new one. A released hold holds nothing -- returning capacity
                // is precisely what release means -- so matching on it would
                // make a bill permanently un-reservable after a correct
                // release, and would disagree with the capacity arithmetic
                // below, which already discounts released holds.
                if ($hold->isReleased()) {
                    continue;
                }

                if ($hold->invoice_id === $stored->invoice_id || $hold->reservation_key === $key) {
                    $same = $hold;
                }
            }
            if ($same !== null) {
                if ($same->payment_intent_id !== $stored->id || $same->reservation_key !== $key || $same->reserved_by !== $actor->id) {
                    throw ValidationException::withMessages(['reservation_key' => 'Existing bill hold conflicts with this identity or actor.']);
                }

                return $same;
            }
            if (PaymentReservation::query()->where('reservation_key', $key)->exists()) {
                throw ValidationException::withMessages(['reservation_key' => 'Reservation identity belongs to another document.']);
            }

            // Only from here is a *new* hold being admitted, so only from here
            // does it matter that the approval is live. Replaying a recorded
            // reservation returns the existing hold above, because a hold that
            // was validly taken stays validly taken even after its window has
            // expired or been rolled over. A retired approval is still valid
            // evidence of what a reviewer approved; it simply grants no new
            // room.
            if ($review->isSuperseded() || $window->isExpired()) {
                throw ValidationException::withMessages(['reservation' => 'This funding approval belongs to an expired or superseded window; review a reviewed rollover before holding further capacity.']);
            }

            if ($balance === null) {
                throw ValidationException::withMessages(['reservation' => 'Reservation state changed; fresh observation required.']);
            }
            $this->verify->handle($actor, $stored);
            $current = $this->context->requireCurrent($actor, $window, $balance);
            $bound = false;
            foreach ($current['budget']->snapshot['bills'] as $bill) {
                if ($bill['id'] === $stored->invoice_version_id && ($stored->snapshot['invoice_evidence']['snapshot_digest'] ?? null) === $bill['digest']
                    && $stored->invoice_version_review_id === $bill['review_id']) {
                    $bound = true;
                }
            }
            if (! $bound) {
                throw ValidationException::withMessages(['reservation' => 'Draft must bind an independently approved exact USDC bill from the reviewed closed set.']);
            }
            $cost = BigInteger::of($stored->amount_base_units)->plus($stored->max_fee_base_units);
            $after = $consuming->plus($cost);
            if ($after->isGreaterThan($window->snapshot['capacity']['budget_base_units']) || $after->isGreaterThan($current['available_cash'])) {
                throw ValidationException::withMessages(['reservation' => 'Cumulative bill and fee holds exceed allocation or observed unprotected Arc cash.']);
            }
            $snapshot = ['schema_version' => 1, 'reservation_key' => $key, 'institution_id' => $institution->id,
                'payment_intent_id' => $stored->id, 'invoice_id' => $stored->invoice_id, 'funding_window_approval_id' => $review->id,
                'reserved_by' => $actor->id, 'intent_digest' => $stored->snapshot_digest, 'approval_digest' => $review->approval_digest,
                'amount_base_units' => (string) $stored->amount_base_units, 'max_fee_base_units' => (string) $stored->max_fee_base_units,
                'total_base_units' => (string) $cost, 'prior_reserved_base_units' => (string) $total,
                'remaining_budget_base_units' => (string) BigInteger::of($window->snapshot['capacity']['budget_base_units'])->minus($total->plus($cost)),
                'remaining_cash_base_units' => (string) BigInteger::of($current['available_cash'])->minus($after), 'balance_observation' => $balance,
                /**
                 * Release-adjusted figures, recorded alongside the historical
                 * chain above. The remaining_* keys stay a pure record of the
                 * running total at this point in history and are re-verified
                 * forever; these name what capacity was actually unavailable.
                 */
                'released_reserved_base_units' => (string) $released,
                'consuming_reserved_base_units' => (string) $consuming,
                'consuming_after_base_units' => (string) $after];
            /** @var PaymentReservation $reservation */
            $reservation = PaymentReservation::query()->create(['reservation_key' => $key, 'organization_id' => $institution->id,
                'payment_intent_id' => $stored->id, 'invoice_id' => $stored->invoice_id, 'funding_window_approval_id' => $review->id,
                'reserved_by' => $actor->id, 'amount_base_units' => $stored->amount_base_units, 'max_fee_base_units' => $stored->max_fee_base_units,
                'snapshot' => $snapshot, 'snapshot_digest' => PaymentIntent::digest($snapshot)]);
            activity('finance')->causedBy($actor)->performedOn($reservation)->event('vendor_payment_reserved')
                ->withProperties(['payment_intent_id' => $stored->id, 'reservation_key' => $key,
                    'prior_reserved_base_units' => (string) $total, 'consuming_after_base_units' => (string) $after,
                    'released_reserved_base_units' => (string) $released, 'can_execute' => false, 'payments_submitted' => 0])
                ->log('Bill and fee capacity held atomically; no payment authority or transfer');

            return $reservation;
        }, 3);
    }
}
