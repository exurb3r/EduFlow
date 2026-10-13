<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\PrepareVendorPayment;
use App\Actions\ReserveVendorPayment;
use App\Actions\VerifyVendorPaymentDraft;
use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\FinancePlanReview;
use App\Models\FinanceWorkflowRun;
use App\Models\FundingWindow;
use App\Models\FundingWindowApproval;
use App\Models\Invoice;
use App\Models\PaymentIntent;
use App\Models\PaymentReservation;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/** Staff handoff from accepted proposal to existing payment controls, never signing authority. */
final readonly class AcceptedPlanPayments
{
    public function __construct(
        private DepartmentBudgetPlanner $planner,
        private PrepareVendorPayment $prepare,
        private VerifyVendorPaymentDraft $verify,
        private ReserveVendorPayment $reserve,
    ) {}

    public function prepareBill(User $actor, FinanceWorkflowRun $run, int $invoiceVersionId,
        FundingWindowApproval $approval, string $expectedPlanDigest, string $expectedApprovalDigest,
        Money $maxFee): PaymentReservation
    {
        Gate::forUser($actor)->authorize('view', $run);
        $stored = FinanceWorkflowRun::query()->findOrFail($run->id);
        Gate::forUser($actor)->authorize('view', $stored);
        if ($maxFee->currency !== CurrencyCode::USDC || $maxFee->minorUnits < 0) {
            throw ValidationException::withMessages(['max_fee' => 'Exact non-negative USDC fee ceiling required.']);
        }
        $review = FinancePlanReview::query()->where('finance_workflow_run_id', $stored->id)->first();
        $budget = $stored->budgetSnapshot;

        if ($budget === null || $review === null || $review->decision !== 'accept_plan'
            || ! $review->hasValidEvidence($stored) || $stored->result_digest !== $expectedPlanDigest
            || ! hash_equals($expectedPlanDigest, PaymentIntent::digest($this->planner->handle($actor, $budget)))) {
            throw ValidationException::withMessages(['plan' => 'Current exact independently accepted plan required before preparing payment review.']);
        }

        $bill = collect($stored->result['bills'] ?? [])->firstWhere('invoice_version_id', $invoiceVersionId);
        $window = FundingWindow::query()->where('organization_id', $stored->organization_id)
            ->findOrFail($approval->funding_window_id);
        if (! is_array($bill) || ($bill['decision'] ?? null) !== 'propose_human_review'
            || $window->budget_snapshot_id !== $budget->id || $approval->organization_id !== $stored->organization_id
            || ! $approval->hasValidEvidence($window) || $approval->approval_digest !== $expectedApprovalDigest) {
            throw ValidationException::withMessages(['funding' => 'Eligible bill and reviewed funding window must bind this exact accepted plan.']);
        }

        $invoice = Invoice::query()->where('organization_id', $stored->organization_id)->findOrFail($bill['invoice_id']);
        $wallet = Wallet::query()->where('organization_id', $stored->organization_id)->findOrFail($window->wallet_id);
        Gate::forUser($actor)->authorize('create', PaymentIntent::class);
        $draft = PaymentIntent::query()->where('organization_id', $stored->organization_id)
            ->where('invoice_id', $invoice->id)->where('invoice_version_id', $invoiceVersionId)->latest('revision')->first();

        if ($draft === null) {
            $key = Uuid::uuid5(Uuid::NAMESPACE_URL, 'eduflow:plan-payment:'.$stored->id.':'.$expectedPlanDigest.':'.$invoiceVersionId)->toString();
            $draft = $this->prepare->handle($actor, $invoice, $wallet, $maxFee, $key);
        } else {
            $draft = $this->verify->handle($actor, $draft);
        }
        if ($draft->wallet_id !== $wallet->id || $draft->max_fee_base_units !== $maxFee->minorUnits
            || $draft->invoice_version_id !== $invoiceVersionId || (string) $draft->amount_base_units !== $bill['valuation_base_units']) {
            throw ValidationException::withMessages(['payment' => 'Exact accepted bill version, valuation, wallet and fee ceiling must match the payment draft; review corrections separately.']);
        }

        $key = Uuid::uuid5(Uuid::NAMESPACE_URL, 'eduflow:plan-reservation:'.$stored->id.':'.$invoiceVersionId.':'.$approval->approval_digest)->toString();

        return $this->reserve->handle($actor, $draft, $approval, $key, $draft->snapshot_digest, $expectedApprovalDigest);
    }

    /** @return list<array<string, mixed>> */
    public function progress(User $actor, FinanceWorkflowRun $run): array
    {
        Gate::forUser($actor)->authorize('view', $run);
        $rows = [];
        $drafts = PaymentIntent::query()->where('organization_id', $run->organization_id)
            ->whereIn('invoice_version_id', array_column($run->result['bills'] ?? [], 'invoice_version_id'))
            ->with(['authorization', 'reservation', 'submission'])->orderByDesc('revision')->get();
        foreach ($run->result['bills'] ?? [] as $bill) {
            $draft = $drafts->first(fn (PaymentIntent $intent): bool => $intent->invoice_id === $bill['invoice_id']
                && $intent->invoice_version_id === $bill['invoice_version_id']);
            $entry = $draft?->submission;
            $state = $entry->state ?? ($draft?->authorization->decision ?? ($draft?->reservation === null ? 'not_prepared' : 'awaiting_payment_review'));
            $rows[] = ['invoice_id' => $bill['invoice_id'], 'invoice_version_id' => $bill['invoice_version_id'],
                'planning_decision' => $bill['decision'], 'payment_intent_id' => $draft?->id,
                'submission_id' => $entry?->id, 'state' => $state, 'stage' => $entry?->stage,
                'provider_handle' => $entry?->provider_handle, 'tx_hash' => $entry?->result['provider_reference'] ?? null,
                'settled' => $entry?->state === 'completed' && ($entry?->result['settled'] ?? false) === true];
        }

        return $rows;
    }
}
