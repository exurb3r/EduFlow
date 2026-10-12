<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\Budget;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\PaymentIntentChange;
use App\Models\PaymentIntentChangeReview;
use App\Models\PaymentReservation;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Wallet;
use App\Services\InstallationInstitution;
use App\Services\PaymentIntentLifecycle;
use App\Services\VendorPaymentSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class ReviewPaymentIntentChange
{
    public function __construct(
        private InstallationInstitution $institutions,
        private PaymentIntentLifecycle $lifecycle,
        private VendorPaymentSnapshot $snapshots,
    ) {}

    public function handle(User $reviewer, PaymentIntentChange $change, string $expectedDigest, string $decision, string $reason): PaymentIntentChangeReview
    {
        Gate::forUser($reviewer)->authorize('review', $change);
        if (! $change->exists || ! in_array($decision, ['approve_change', 'reject'], true) || trim($reason) === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['decision' => 'An explicit recovery decision and review reason are required.']);
        }
        $institution = $this->institutions->require();

        return DB::transaction(function () use ($reviewer, $change, $expectedDigest, $decision, $reason, $institution): PaymentIntentChangeReview {
            Organization::query()->whereKey($institution->id)->lockForUpdate()->firstOrFail();
            /** @var PaymentIntentChange $stored */
            $stored = PaymentIntentChange::query()->where('organization_id', $institution->id)->whereKey($change->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($reviewer)->authorize('review', $stored);
            /** @var PaymentIntent $source */
            $source = PaymentIntent::query()->where('organization_id', $institution->id)->whereKey($stored->payment_intent_id)->lockForUpdate()->firstOrFail();
            if (! $stored->hasValidEvidence($source) || ! hash_equals($stored->content_digest, $expectedDigest)) {
                throw ValidationException::withMessages(['expected_digest' => 'Intact proposal and exact expected digest are required.']);
            }
            $this->lifecycle->resolve($institution->id, $source->invoice_id);
            /** @var PaymentIntentChangeReview|null $existing */
            $existing = PaymentIntentChangeReview::query()->where('payment_intent_change_id', $stored->id)->first();
            if ($existing !== null) {
                if (! $existing->hasValidEvidence($stored, $source) || $existing->reviewed_by !== $reviewer->id
                    || $existing->decision !== $decision || $existing->reason !== $reason) {
                    throw ValidationException::withMessages(['decision' => 'Recovery review already recorded with different evidence or feedback.']);
                }

                return $existing;
            }
            $this->lifecycle->requireActive($source);

            // A *released* hold holds no capacity -- that is what releasing it
            // means -- and the release route exists precisely so a stale draft
            // can be recovered. Testing row existence instead of live capacity
            // made that unreachable: the proposal above correctly clears on a
            // live-hold test, and this sibling then refused the approval it had
            // just been cleared to obtain, leaving the bill permanently
            // un-recoverable. The same predicate is used in both places.
            $liveHold = PaymentReservation::query()
                ->where('invoice_id', $source->invoice_id)
                ->get()
                ->reject(fn (PaymentReservation $hold): bool => $hold->isReleased())
                ->isNotEmpty();

            if ($decision === 'approve_change' && $liveHold) {
                throw ValidationException::withMessages(['payment' => 'Bill has held capacity; reviewed reservation release is required before draft recovery.']);
            }
            if ($decision === 'approve_change' && $stored->kind === 'replace') {
                $this->verifyReplacement($institution, $source, $stored);
            }
            $review = new PaymentIntentChangeReview(['organization_id' => $institution->id, 'invoice_id' => $source->invoice_id,
                'payment_intent_change_id' => $stored->id, 'payment_intent_id' => $source->id,
                'retired_payment_intent_id' => $decision === 'approve_change' ? $source->id : null,
                'reviewed_by' => $reviewer->id, 'decision' => $decision, 'reason' => $reason, 'proposal_digest' => $stored->content_digest]);
            $review->review_digest = PaymentIntent::digest($review->content());
            $review->save();
            if ($decision === 'approve_change' && $stored->kind === 'replace' && $stored->replacement_snapshot !== null) {
                $successor = PaymentIntent::fromSnapshot($stored->replacement_snapshot, $review->id);
                $successor->save();
                activity('finance')->causedBy($reviewer)->performedOn($successor)->event('vendor_payment_replacement_prepared')
                    ->withProperties(['predecessor_id' => $source->id, 'change_review_id' => $review->id,
                        'payment_approved' => false, 'payments_submitted' => 0])
                    ->log('Reviewed replacement created as a fresh non-executable draft');
            }
            $this->lifecycle->resolve($institution->id, $source->invoice_id);
            activity('finance')->causedBy($reviewer)->performedOn($review)->event('payment_intent_change_reviewed')
                ->withProperties(['payment_intent_id' => $source->id, 'change_id' => $stored->id, 'decision' => $decision,
                    'review_digest' => $review->review_digest, 'payment_approved' => false, 'payments_submitted' => 0])
                ->log('Draft recovery reviewed; no payment approval, reservation or transfer');

            return $review;
        }, 3);
    }

    private function verifyReplacement(Organization $institution, PaymentIntent $source, PaymentIntentChange $change): void
    {
        if ($change->replacement_snapshot === null) {
            throw ValidationException::withMessages(['change' => 'Exact replacement evidence is missing.']);
        }
        $replacement = PaymentIntent::fromSnapshot($change->replacement_snapshot);
        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('organization_id', $institution->id)->whereKey($source->invoice_id)->lockForUpdate()->firstOrFail();
        /** @var Wallet $wallet */
        $wallet = Wallet::query()->where('organization_id', $institution->id)->whereKey($replacement->wallet_id)->lockForUpdate()->firstOrFail();
        $invoice->setRelation('vendor', Vendor::query()->whereKey($invoice->vendor_id)->lockForUpdate()->firstOrFail());
        if ($invoice->budget_id !== null) {
            $invoice->setRelation('budget', Budget::query()->whereKey($invoice->budget_id)->lockForUpdate()->firstOrFail());
        }
        $snapshot = $this->snapshots->build($institution, $invoice, $wallet, new Money($replacement->max_fee_base_units, CurrencyCode::USDC));
        $snapshot['intent_key'] = $replacement->intent_key;
        $snapshot['prepared_by'] = $replacement->prepared_by;
        $snapshot['recovery'] = $replacement->snapshot['recovery'];
        if (! hash_equals($replacement->snapshot_digest, PaymentIntent::digest($snapshot))
            || PaymentIntent::query()->where('intent_key', $replacement->intent_key)->exists()) {
            throw ValidationException::withMessages(['change' => 'Replacement evidence changed or identity was used; a fresh reviewed proposal is required.']);
        }
    }
}
