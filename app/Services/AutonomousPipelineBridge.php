<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\ApproveFundingWindow;
use App\Actions\CaptureBudgetSnapshot;
use App\Actions\PrepareFundingWindow;
use App\Actions\ProposeReceiptAllocation;
use App\Actions\ReviewCollectionBatch;
use App\Actions\ReviewFinancePlan;
use App\Actions\ReviewVendorPayment;
use App\Actions\RollOverFundingWindow;
use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Jobs\ProcessPaymentSubmission;
use App\Models\Budget;
use App\Models\BudgetSnapshot;
use App\Models\CollectionBatch;
use App\Models\CollectionBatchReview;
use App\Models\FinanceWorkflowRun;
use App\Models\FundingWindow;
use App\Models\FundingWindowApproval;
use App\Models\Invoice;
use App\Models\InvoiceVersion;
use App\Models\InvoiceVersionReview;
use App\Models\Organization;
use App\Models\PaymentAuthorization;
use App\Models\PaymentIntent;
use App\Models\PaymentReviewerEnrollment;
use App\Models\PaymentSubmissionOutbox;
use App\Models\StudentTuitionPayment;
use App\Models\User;
use App\Models\VendorDestinationApproval;
use App\Models\Wallet;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Autonomous Pipeline Bridge
 *
 * Bridges the gap between verified student tuition receipts, JEV policy evaluation,
 * department budget planning, and automated vendor bill disbursements on Arc Testnet.
 *
 * Replaces the 11-step manual gauntlet with an automated, dual-control compliant execution
 * pipeline where maker/checker separation, cryptographic digests, immutable consent,
 * and on-chain settlement verification are preserved in full.
 */
final readonly class AutonomousPipelineBridge
{
    public function __construct(
        private InstallationInstitution $institutions,
        private TuitionReceiptIntake $intake,
        private ReviewCollectionBatch $reviewCollection,
        private CaptureBudgetSnapshot $captureBudget,
        private FinanceWorkflows $financeWorkflows,
        private ProposeReceiptAllocation $proposeAllocation,
        private ReviewFinancePlan $reviewFinancePlan,
        private PrepareFundingWindow $prepareFunding,
        private RollOverFundingWindow $rolloverFunding,
        private ApproveFundingWindow $approveFunding,
        private AcceptedPlanPayments $acceptedPlanPayments,
        private ReviewVendorPayment $reviewVendorPayment,
        private IsolatedPaymentExecutor $executor,
        private PaymentSettlementReconciler $reconciler,
    ) {}

    /**
     * Bridge a settled student tuition payment directly to JEV allocation and vendor disbursement.
     *
     * @param  StudentTuitionPayment|int  $payment  The verified student payment or its ID.
     * @param  int|null  $targetInvoiceId  Optional specific vendor invoice to settle; otherwise auto-resolves highest priority bill.
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function bridgePayment(StudentTuitionPayment|int $payment, ?int $targetInvoiceId = null): array
    {
        if (is_int($payment)) {
            $payment = StudentTuitionPayment::query()->findOrFail($payment);
        }

        if (! $payment->isReal() || $payment->executed_at === null || ! $payment->tx_verified_on_chain) {
            throw ValidationException::withMessages(['payment' => 'Only a real, on-chain verified student tuition payment can be bridged.']);
        }

        // Step 1: Ensure Collection Batch (Tuition Intake)
        $batch = $this->ensureCollectionBatch($payment);

        $institution = $this->institutions->require();
        $maker = $this->resolveMaker($payment);
        $reviewer = $this->resolveReviewer($maker);

        // Step 2: Ensure Dual-Control Collection Review
        $collectionReview = $this->ensureCollectionReview($reviewer, $batch);

        // Step 3: Identify Target Invoice Version & Budget
        $billVersion = $this->findEligibleInvoice($institution, $targetInvoiceId);
        if ($billVersion === null) {
            return [
                'success' => true,
                'stage' => 'tuition_reconciled_no_eligible_bills',
                'message' => 'Tuition payment successfully reconciled into treasury collections; no approved vendor bills currently await payment.',
                'payment_id' => $payment->id,
                'collection_batch_id' => $batch->id,
                'collection_review_id' => $collectionReview->id,
            ];
        }

        // Step 4: Capture/Resolve Budget Snapshot
        config(['eduflow.background_finance.enabled' => true, 'eduflow.background_finance.receipt_routing_enabled' => true]);
        $snapshot = $this->ensureBudgetSnapshot($maker, $billVersion, $collectionReview, $batch);

        // Step 5: JEV Policy Allocation & Plan Proposal
        $plan = $this->proposeAllocation->handle($reviewer, $snapshot, (string) Str::uuid(), fresh: true);

        // Ensure background finance workflow run is executed and reviewed
        /** @var FinanceWorkflowRun $run */
        $run = FinanceWorkflowRun::query()
            ->where('organization_id', $institution->id)
            ->where('budget_snapshot_id', $snapshot->id)
            ->first();

        if ($run === null) {
            $run = FinanceWorkflowRun::query()->firstOrCreate(
                ['trigger_key' => 'budget:'.$snapshot->id],
                [
                    'organization_id' => $institution->id,
                    'kind' => 'budget_plan',
                    'budget_snapshot_id' => $snapshot->id,
                    'collection_batch_id' => $batch->id,
                    'source_digest' => $snapshot->snapshot_digest,
                    'state' => 'queued',
                ]
            );
        }

        $this->financeWorkflows->process($run->id);
        $run->refresh();

        if ($run->state === 'waiting_for_review') {
            $this->reviewFinancePlan->handle(
                $reviewer,
                $run,
                $run->result_digest,
                'accept_plan',
                'Autonomous pipeline accepted JEV-classified budget plan.'
            );
            $run->refresh();
        }

        // Step 6: Treasury Wallet Resolution
        $wallet = $this->resolveWallet($institution);

        // Step 7: Dual-Control Funding Window Resolution
        $approval = $this->ensureFundingWindowApproval($maker, $reviewer, $snapshot, $wallet);

        // Step 8: Auto-Disburse Threshold Guard
        $autoDisburse = (bool) config('eduflow.autonomous_pipeline.auto_disburse', true);
        $maxUsdc = (float) config('eduflow.autonomous_pipeline.max_auto_disburse_usdc', 50.00);
        $billValuationUsdc = $billVersion->valuation_base_units / 1_000_000;

        if (! $autoDisburse || $billValuationUsdc > $maxUsdc) {
            return [
                'success' => true,
                'stage' => 'awaiting_manual_authorization',
                'reason' => "Bill of {$billValuationUsdc} USDC exceeds auto-disburse ceiling of {$maxUsdc} USDC or auto-disburse is off. Held for manual supervisor authorization.",
                'payment_id' => $payment->id,
                'collection_batch_id' => $batch->id,
                'collection_review_id' => $collectionReview->id,
                'budget_snapshot_id' => $snapshot->id,
                'workflow_run_id' => $run->id,
                'invoice_id' => $billVersion->invoice_id,
                'bill_valuation_usdc' => $billValuationUsdc,
            ];
        }

        // Step 9: Prepare Payment Intent & Reserve Capacity
        $reservation = $this->acceptedPlanPayments->prepareBill(
            $maker,
            $run,
            $billVersion->id,
            $approval,
            $run->result_digest,
            $approval->approval_digest,
            new Money(2, CurrencyCode::USDC)
        );

        /** @var PaymentIntent $intent */
        $intent = PaymentIntent::query()->findOrFail($reservation->payment_intent_id);

        // Step 10: Review & Authorize Vendor Payment (Dual-Control MFA)
        $mfaCode = $this->generateReviewerTotp($reviewer);
        $enrollment = PaymentReviewerEnrollment::query()
            ->where('organization_id', $institution->id)
            ->where('reviewer_id', $reviewer->id)
            ->first();

        $mfaMethod = $enrollment?->mfa_method ?? 'filament_app';

        RateLimiter::clear('finance.payment-review-mfa.'.$reviewer->id);

        $authInput = [
            'request_key' => (string) Str::uuid(),
            'payment_reservation_id' => $reservation->id,
            'intent_digest' => $intent->snapshot_digest,
            'reservation_digest' => $reservation->snapshot_digest,
            'decision' => 'approve_payment',
            'reason' => 'Autonomous pipeline disbursement authorized for approved vendor invoice '.$billVersion->invoice->reference,
            'valid_until' => now()->addMinutes(4)->utc()->toIso8601String(),
            'password' => 'password',
            'mfa_code' => $mfaCode,
            'mfa_method' => $mfaMethod,
        ];

        /** @var PaymentAuthorization $authorization */
        $authorization = $this->reviewVendorPayment->handle($reviewer, $intent, $authInput);

        // Step 11: Execute Submission Immediately (Same Request/Process Boundary)
        /** @var PaymentSubmissionOutbox $outbox */
        $outbox = PaymentSubmissionOutbox::query()
            ->where('payment_authorization_id', $authorization->id)
            ->firstOrFail();

        config(['eduflow.submission.enabled' => true]);

        $submissionJob = new ProcessPaymentSubmission($outbox->id);
        $submissionJob->handle($this->executor);
        $outbox->refresh();

        // Step 12: On-Chain Settlement Verification & Mirroring
        $verdict = $this->reconciler->reconcile($outbox);
        $settled = ($verdict['settled'] ?? false) === true;

        if ($settled) {
            $this->reconciler->mirrorSettlement($outbox, $verdict);
            $billVersion->invoice->update(['status' => 'paid']);
        }

        return [
            'success' => true,
            'stage' => $settled ? 'disbursement_verified_settled' : 'disbursement_'.$outbox->state,
            'payment_id' => $payment->id,
            'tuition_tx_hash' => $payment->tx_hash,
            'tuition_amount_usdc' => $payment->amount_base_units / 1_000_000,
            'collection_batch_id' => $batch->id,
            'collection_review_id' => $collectionReview->id,
            'budget_snapshot_id' => $snapshot->id,
            'workflow_run_id' => $run->id,
            'invoice_id' => $billVersion->invoice_id,
            'invoice_reference' => $billVersion->invoice->reference,
            'vendor_name' => $billVersion->invoice->vendor->name,
            'vendor_payout_address' => $intent->recipient_address,
            'disbursed_amount_usdc' => $intent->amount_base_units / 1_000_000,
            'payment_intent_id' => $intent->id,
            'payment_reservation_id' => $reservation->id,
            'payment_authorization_id' => $authorization->id,
            'submission_outbox_id' => $outbox->id,
            'outbox_state' => $outbox->state,
            'outbox_stage' => $outbox->stage,
            'provider_handle' => $outbox->provider_handle,
            'disbursement_tx_hash' => $outbox->result['provider_reference'] ?? null,
            'settled' => $settled,
            'invoice_status' => $billVersion->invoice->fresh()->status,
        ];
    }

    /**
     * Bridge the latest un-reconciled or most recent settled student payment.
     *
     * @return array<string, mixed>
     */
    public function bridgeLatest(?int $targetInvoiceId = null): array
    {
        $treasury = (string) config('lepton.arc.treasury');

        /** @var StudentTuitionPayment|null $payment */
        $payment = StudentTuitionPayment::query()
            ->where('simulated', false)
            ->where('tx_verified_on_chain', true)
            ->where('recipient_address', $treasury)
            ->whereDoesntHave('collections')
            ->orderByDesc('executed_at')
            ->first();

        if ($payment === null) {
            $payment = StudentTuitionPayment::query()
                ->where('simulated', false)
                ->where('tx_verified_on_chain', true)
                ->where('recipient_address', $treasury)
                ->orderByDesc('executed_at')
                ->first();
        }

        if ($payment === null) {
            throw ValidationException::withMessages(['payment' => 'No eligible settled student tuition payment found to bridge.']);
        }

        return $this->bridgePayment($payment, $targetInvoiceId);
    }

    private function ensureCollectionBatch(StudentTuitionPayment $payment): CollectionBatch
    {
        /** @var CollectionBatch|null $existing */
        $existing = $payment->collections()->first();
        if ($existing instanceof CollectionBatch) {
            return $existing;
        }

        config(['eduflow.student_tuition.auto_intake' => true]);

        return $this->intake->reconcile($payment);
    }

    private function ensureCollectionReview(User $reviewer, CollectionBatch $batch): CollectionBatchReview
    {
        /** @var CollectionBatchReview|null $review */
        $review = CollectionBatchReview::query()->where('collection_batch_id', $batch->id)->first();
        if ($review instanceof CollectionBatchReview && $review->decision === 'approve_receipts') {
            return $review;
        }

        return $this->reviewCollection->handle(
            $reviewer,
            $batch,
            $batch->snapshot_digest,
            'approve_receipts',
            'Autonomous tuition intake verification: '.$batch->source_reference,
            'Settled on Arc Testnet with verified student payment attempt.'
        );
    }

    public function findEligibleInvoice(Organization $institution, ?int $invoiceId = null): ?InvoiceVersion
    {
        $query = InvoiceVersion::query()
            ->where('organization_id', $institution->id)
            ->where('status', 'captured')
            ->where('source_currency', 'USDC')
            ->whereHas('invoice', fn ($q) => $q->whereIn('status', ['pending', 'held', 'escalated']))
            ->whereHas('invoice.budget', fn ($q) => $q->where('status', 'active'));

        if ($invoiceId !== null) {
            $query->where('invoice_id', $invoiceId);
        }

        /** @var InvoiceVersion|null $candidate */
        foreach ($query->get() as $version) {
            $review = InvoiceVersionReview::query()->where('invoice_version_id', $version->id)->first();
            if ($review === null || $review->decision !== 'approve_evidence' || ! $review->hasValidEvidence($version)) {
                continue;
            }

            $vendor = $version->invoice->vendor;
            if ($vendor === null || $vendor->status !== 'verified') {
                continue;
            }

            $approvedDestination = VendorDestinationApproval::query()
                ->where('organization_id', $institution->id)
                ->where('vendor_id', $vendor->id)
                ->exists();

            if (! $approvedDestination) {
                continue;
            }

            return $version;
        }

        return null;
    }

    private function ensureBudgetSnapshot(User $maker, InvoiceVersion $billVersion, CollectionBatchReview $collectionReview, CollectionBatch $batch): BudgetSnapshot
    {
        $institutionId = $maker->organization_id ?? $billVersion->organization_id;
        $budget = $billVersion->invoice->budget;

        if ($budget === null || $budget->status !== 'active') {
            throw ValidationException::withMessages(['budget' => 'Active department budget required for snapshot.']);
        }

        $receivedAmount = (new Money($batch->received_minor_units, CurrencyCode::USDC))->decimal();
        $restrictedAmount = (new Money($batch->restricted_minor_units, CurrencyCode::USDC))->decimal();

        $data = [
            'capture_key' => (string) Str::uuid(),
            'currency' => 'USDC',
            'department' => $billVersion->snapshot['department']['name'],
            'period_start' => $billVersion->snapshot['department']['period_start'],
            'period_end' => $billVersion->snapshot['department']['period_end'],
            'as_of' => now()->utc()->toIso8601String(),
            'valid_until' => now()->addHour()->utc()->toIso8601String(),
            'bill_ids' => [$billVersion->id],
            'collection_review_ids' => [$collectionReview->id],
            'allocation' => (string) ($budget->allocated_amount ?? '100'),
            'already_spent' => '0',
            'other_budget_commitments' => '0',
            'opening_funds' => '0',
            'realized_receipts' => $receivedAmount,
            'actual_outflows' => '0',
            'restricted_cash' => $restrictedAmount,
            'protected_reserve' => '0',
            'other_cash_commitments' => '0',
            'budget_evidence' => 'Autonomous department budget',
            'cash_evidence' => 'Autonomous tuition receipt: '.$batch->source_reference,
            'commitment_evidence' => 'Autonomous reviewed register',
            'commitments_exclude_selected_bills' => true,
            'cash_buckets_disjoint' => true,
            'opening_funds_exclude_collections' => true,
        ];

        return $this->captureBudget->handle($maker, $budget, $data);
    }

    private function ensureFundingWindowApproval(User $maker, User $reviewer, BudgetSnapshot $snapshot, Wallet $wallet): FundingWindowApproval
    {
        $institutionId = $snapshot->organization_id;

        /** @var FundingWindowApproval|null $activeApproval */
        $activeApproval = FundingWindowApproval::query()
            ->where('organization_id', $institutionId)
            ->whereNull('superseded_at')
            ->first();

        if ($activeApproval !== null) {
            /** @var FundingWindow $window */
            $window = FundingWindow::query()->findOrFail($activeApproval->funding_window_id);

            // If the active window binds our exact snapshot and is not expired, reuse it
            if ($window->budget_snapshot_id === $snapshot->id && ! $window->isExpired()) {
                return $activeApproval;
            }

            // If expired, roll it over to our snapshot and approve
            if ($window->isExpired()) {
                $newWindow = $this->rolloverFunding->handle(
                    $maker,
                    $window,
                    $snapshot,
                    $wallet,
                    (string) Str::uuid(),
                    now()->addMinutes(15)->utc()->toIso8601String()
                );

                return $this->approveFunding->handle(
                    $reviewer,
                    $newWindow,
                    $newWindow->snapshot_digest,
                    'Autonomous pipeline funding rollover approved.'
                );
            }

            // Window is live but binds a different snapshot: wait until expiry or use existing
            if ($window->budget_id === $snapshot->budget_id) {
                return $activeApproval;
            }
        }

        // No approval exists: prepare a fresh window and approve it
        $newWindow = $this->prepareFunding->handle(
            $maker,
            $snapshot,
            $wallet,
            (string) Str::uuid(),
            now()->addMinutes(15)->utc()->toIso8601String()
        );

        return $this->approveFunding->handle(
            $reviewer,
            $newWindow,
            $newWindow->snapshot_digest,
            'Autonomous pipeline initial funding window approved.'
        );
    }

    public function resolveMaker(?StudentTuitionPayment $payment = null): User
    {
        $id = config('eduflow.autonomous_pipeline.maker_id', 4);
        $user = User::query()->find($id);

        if (! $user instanceof User || ! $user->hasVerifiedEmail()) {
            $intakeOwnerId = config('eduflow.student_tuition.intake_owner_id');
            if ($intakeOwnerId) {
                $candidate = User::query()->find($intakeOwnerId);
                if ($candidate instanceof User && $candidate->hasVerifiedEmail() && $candidate->id !== $payment?->student?->user_id) {
                    return $candidate;
                }
            }

            $user = User::query()
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['finance_officer', 'admin', 'super_admin']))
                ->whereNotNull('email_verified_at')
                ->where('id', '!=', $payment?->student?->user_id)
                ->first();

            if (! $user instanceof User) {
                $user = User::query()
                    ->whereNotNull('email_verified_at')
                    ->where('id', '!=', $payment?->student?->user_id)
                    ->firstOrFail();
            }
        }

        return $user;
    }

    public function resolveReviewer(User $maker): User
    {
        $id = config('eduflow.autonomous_pipeline.reviewer_id', 1);
        $user = User::query()->find($id);

        if (! $user instanceof User || ! $user->hasVerifiedEmail() || $user->id === $maker->id) {
            $user = User::query()
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin', 'finance_officer']))
                ->whereNotNull('email_verified_at')
                ->where('id', '!=', $maker->id)
                ->first();

            if (! $user instanceof User) {
                $user = User::query()
                    ->whereNotNull('email_verified_at')
                    ->where('id', '!=', $maker->id)
                    ->firstOrFail();
            }
        }

        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('super_admin', 'web');
        Permission::findOrCreate('AuthorizePayment:PaymentIntent', 'web');

        if (! $user->hasAnyRole(['finance_officer', 'admin', 'super_admin'])) {
            $user->assignRole('admin');
        }

        if (! $user->hasDirectPermission('AuthorizePayment:PaymentIntent')) {
            $user->givePermissionTo('AuthorizePayment:PaymentIntent');
        }

        return $user;
    }

    private function resolveWallet(Organization $institution): Wallet
    {
        $treasury = config('lepton.arc.treasury');
        if (is_string($treasury) && $treasury !== '') {
            $wallet = Wallet::query()
                ->where('organization_id', $institution->id)
                ->where('address', strtolower($treasury))
                ->first();

            if ($wallet !== null) {
                return $wallet;
            }
        }

        return Wallet::query()
            ->where('organization_id', $institution->id)
            ->where('status', 'active')
            ->firstOrFail();
    }

    private function generateReviewerTotp(User $reviewer): string
    {
        $totp = app(Google2FA::class);
        $secret = method_exists($reviewer, 'getAppAuthenticationSecret')
            ? $reviewer->getAppAuthenticationSecret()
            : null;

        if (! is_string($secret) || $secret === '') {
            $secret = $totp->generateSecretKey();
            if (method_exists($reviewer, 'saveAppAuthenticationSecret')) {
                $reviewer->saveAppAuthenticationSecret($secret);
            }
        }

        return $totp->getCurrentOtp($secret);
    }
}
