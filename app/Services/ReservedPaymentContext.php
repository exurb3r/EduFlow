<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\VerifyVendorPaymentDraft;
use App\Models\FundingWindow;
use App\Models\FundingWindowApproval;
use App\Models\PaymentIntent;
use App\Models\PaymentReservation;
use App\Models\User;
use App\Models\Wallet;
use Brick\Math\BigInteger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final readonly class ReservedPaymentContext
{
    public function __construct(private VerifyVendorPaymentDraft $drafts, private ArcBalanceObservation $balances,
        private FundingWindowContext $funding) {}

    /** @return array{window: FundingWindow, balance_observation: array<string, mixed>} */
    public function requireCurrent(User $actor, PaymentIntent $intent, PaymentReservation $reservation): array
    {
        $this->drafts->handle($actor, $intent);
        /** @var FundingWindowApproval $approval */
        $approval = FundingWindowApproval::query()->where('organization_id', $intent->organization_id)
            ->whereKey($reservation->funding_window_approval_id)->firstOrFail();
        /** @var FundingWindow $window */
        $window = FundingWindow::query()->where('organization_id', $intent->organization_id)->whereKey($approval->funding_window_id)->firstOrFail();
        if (! $approval->hasValidEvidence($window) || ! $reservation->hasValidEvidence($intent, $approval)
            || $intent->wallet_id !== $window->wallet_id || $intent->budget_id !== $window->budget_id) {
            throw ValidationException::withMessages(['payment' => 'Exact independently reviewed funding and matching reservation are required.']);
        }
        /** @var Wallet $wallet */
        $wallet = Wallet::query()->where('organization_id', $intent->organization_id)->whereKey($intent->wallet_id)->firstOrFail();
        $balance = $this->balances->capture($wallet);
        $current = $this->funding->requireCurrent($actor, $window, $balance);
        $bound = false;
        foreach ($current['budget']->snapshot['bills'] as $bill) {
            if ($bill['id'] === $intent->invoice_version_id && $bill['review_id'] === $intent->invoice_version_review_id
                && $bill['digest'] === ($intent->snapshot['invoice_evidence']['snapshot_digest'] ?? null)) {
                $bound = true;
            }
        }
        if (! $bound) {
            throw ValidationException::withMessages(['payment' => 'Reserved payment is not in the funding window closed bill set.']);
        }
        /** @var Collection<int, PaymentReservation> $holds */
        $holds = PaymentReservation::query()->where('organization_id', $intent->organization_id)->orderBy('id')->limit(10_001)->get();
        if ($holds->count() > 10_000) {
            throw ValidationException::withMessages(['payment' => 'Reservation history exceeds reviewed bounds.']);
        }
        /** @var Collection<int, PaymentIntent> $intents */
        $intents = PaymentIntent::query()->where('organization_id', $intent->organization_id)
            ->whereIn('id', $holds->pluck('payment_intent_id'))->get()->keyBy('id');
        /** @var Collection<int, FundingWindowApproval> $approvals */
        $approvals = FundingWindowApproval::query()->where('organization_id', $intent->organization_id)
            ->whereIn('id', $holds->pluck('funding_window_approval_id')->filter()->unique())
            ->get()->keyBy('id');
        $total = BigInteger::zero();
        $consuming = BigInteger::zero();
        $found = false;
        foreach ($holds as $hold) {
            $heldIntent = $intents->get($hold->payment_intent_id);

            // Every hold is judged against the approval it was actually taken
            // under, and against the capacity of the window that approval
            // belongs to. A hold whose approval was later retired is still
            // evidence of what a reviewer approved, and history has to keep
            // reproducing it; it simply grants no new room. Checking history
            // against the *current* approval instead makes every released hold
            // read as corrupt the moment a successor window is approved.
            //
            // There is deliberately no requirement that an older hold's
            // approval still be the live one. A hold under a retired approval
            // that was never released is already stranded — it cannot be
            // released, because an authorization was made under it — and
            // demanding it match the current window as well would make that
            // hold permanently unresolvable, blocking every later payment on
            // the institution.
            $heldApproval = $approvals->get($hold->funding_window_approval_id);
            $heldWindow = $heldApproval === null
                ? null
                : FundingWindow::query()->find($heldApproval->funding_window_id);
            $heldCapacity = is_array($heldWindow?->snapshot['capacity'] ?? null)
                ? $heldWindow->snapshot['capacity']
                : null;

            if (! $heldIntent instanceof PaymentIntent || ! $heldApproval instanceof FundingWindowApproval
                || ! $heldCapacity || ! $hold->hasValidEvidence($heldIntent, $heldApproval)
                || ($hold->snapshot['prior_reserved_base_units'] ?? null) !== (string) $total) {
                throw ValidationException::withMessages(['payment' => 'Held capacity evidence is incomplete; uncertain commitments cannot become spending room.']);
            }

            // Mirror of `ReservationCapacity::verify()`: history reproduces on
            // the cumulative total, but the ceilings bound what is actually
            // unavailable. A released hold's money came back, so charging it
            // against the live limits would count the same capacity twice and
            // would never recover, because the chain total never decreases.
            $consumed = $hold->isReleased()
                ? $consuming
                : $consuming->plus($hold->amount_base_units)->plus($hold->max_fee_base_units);

            $total = $total->plus($hold->amount_base_units)->plus($hold->max_fee_base_units);

            if ($consumed->isGreaterThan($heldCapacity['budget_base_units'])
                || $consumed->isGreaterThan($heldCapacity['cash_base_units'])
                || ($hold->snapshot['remaining_budget_base_units'] ?? null)
                    !== (string) BigInteger::of($heldCapacity['budget_base_units'])->minus($total)) {
                throw ValidationException::withMessages(['payment' => 'Held capacity sequence or approved limits changed.']);
            }

            $consuming = $consumed;
            $found = $found || $hold->id === $reservation->id;
        }

        // What must actually be coverable right now is the consuming total.
        if (! $found || $consuming->isGreaterThan($current['available_cash'])) {
            throw ValidationException::withMessages(['payment' => 'Current unprotected Arc cash cannot cover every held bill and fee cap.']);
        }

        return ['window' => $window, 'balance_observation' => $balance];
    }
}
