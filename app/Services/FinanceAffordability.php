<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\CaptureInvoiceVersion;
use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\BudgetSnapshot;
use App\Models\CollectionBatch;
use App\Models\FinancePolicyActivation;
use App\Models\FundingWindowApproval;
use App\Models\InstitutionPolicyPublication;
use App\Models\InstitutionPolicyVersion;
use App\Models\Invoice;
use App\Models\InvoiceVersion;
use App\Models\InvoiceVersionReview;
use App\Models\PaymentIntent;
use App\Models\PaymentSubmissionOutbox;
use App\Models\StudentTuitionPaymentAttempt;
use App\Models\User;
use App\Models\Wallet;
use App\Settings\AiSettings;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\Gate;
use Throwable;

/** Operational comparison, never a payment authorization or a new funding source. */
final readonly class FinanceAffordability
{
    public function __construct(private InstallationInstitution $institutions, private TreasuryBalanceObservation $balances,
        private InstitutionPolicyWorkspace $policies, private ReservationCapacity $capacity, private AiSettings $aiSettings) {}

    /** @return array<string, mixed> */
    public function summarize(User $actor, bool $fresh = false): array
    {
        Gate::forUser($actor)->authorize('viewAny', InstitutionPolicyVersion::class);
        $institution = $this->institutions->require();
        $wallet = $institution->primaryWallet();
        $observation = $this->balances->read($wallet, $fresh);
        $blockers = [];
        if (! $observation['available']) {
            $blockers[] = $observation['error'];
        }
        $publication = null;
        $reserve = null;
        try {
            $publication = $this->policies->active();
            $activation = FinancePolicyActivation::current($institution->id);
            $policy = $activation?->policyVersion;
            if ($activation instanceof FinancePolicyActivation && $policy !== null && $activation->hasValidEvidence($policy) && $activation->hasValidHistory()) {
                $reserve = (string) $policy->minimum_reserve_base_units;
            }
        } catch (Throwable $exception) {
            report($exception);
            $blockers[] = 'Active policy integrity needs investigation.';
        }
        if (! $publication instanceof InstitutionPolicyPublication || $reserve === null) {
            $blockers[] = 'No independently published institution policy with active financial controls.';
        }
        $held = null;
        $committedByInvoice = [];
        try {
            $capacity = $this->capacity->verify($institution->id);
            foreach ($capacity['holds'] as $hold) {
                $approval = FundingWindowApproval::query()->find($hold->funding_window_approval_id);
                $intent = $hold->intent;
                if ($approval === null || $intent === null || ! $hold->hasValidEvidence($intent, $approval)) {
                    throw new \RuntimeException('Committed payment evidence failed integrity checks.');
                }
                if (! $this->capacity->isReleased($hold)) {
                    $committedByInvoice[$hold->invoice_id] = (string) BigInteger::of($committedByInvoice[$hold->invoice_id] ?? '0')->plus($hold->amount_base_units);
                }
            }
            $held = (string) $capacity['consuming'];
        } catch (Throwable $exception) {
            report($exception);
            $committedByInvoice = [];
            $blockers[] = 'Committed payment capacity cannot be verified.';
        }
        $restricted = BigInteger::zero();
        $reviewed = BigInteger::zero();
        $unreviewed = 0;
        $collections = CollectionBatch::query()->where('organization_id', $institution->id)->where('currency', 'USDC')->with('reviews')->get();
        foreach ($collections as $batch) {
            if (! $batch->hasValidSnapshot()) {
                $blockers[] = 'Collection evidence is damaged; available balance is not known.';
                $restricted = null;
                break;
            }
            $restricted = $restricted->plus($batch->restricted_minor_units);
            $review = $batch->reviews->first();
            if ($review !== null && $review->decision === 'approve_receipts' && $review->hasValidEvidence($batch)) {
                $reviewed = $reviewed->plus($batch->received_minor_units);
            } else {
                $unreviewed++;
            }
        }
        $cash = $observation['balance_base_units'];
        $ledger = null;
        $drift = null;
        try {
            $ledger = $wallet instanceof Wallet ? (string) Money::fromDecimal((string) $wallet->getRawOriginal('balance'), CurrencyCode::USDC)->minorUnits : null;
            $drift = $cash !== null && $ledger !== null ? (string) BigInteger::of($ledger)->minus($cash) : null;
        } catch (Throwable) {
            $blockers[] = 'Ledger comparison is not an exact USDC amount.';
        }
        if ($drift !== null && $drift !== '0') {
            $blockers[] = 'Ledger and observed wallet balance differ. Ledger is not available cash; investigate before payment.';
        }
        $available = $publication instanceof InstitutionPolicyPublication && $cash !== null && $reserve !== null && $held !== null && $restricted instanceof BigInteger
            ? $this->nonNegative(BigInteger::of($cash)->minus($reserve)->minus($held)->minus($restricted)) : null;
        $query = Invoice::query()->where('organization_id', $institution->id)->whereIn('status', ['pending', 'held', 'escalated']);
        $invoices = $query->with(['vendor', 'budget'])->orderBy('due_date')->orderBy('id')->limit(501)->get();
        if ($invoices->count() > 500) {
            $blockers[] = 'More than 500 unpaid bills; narrow scope before comparing totals.';
        }
        $versions = InvoiceVersion::query()->where('organization_id', $institution->id)->whereIn('invoice_id', $invoices->modelKeys())
            ->orderByDesc('id')->get()->unique('invoice_id')->keyBy('invoice_id');
        $reviews = InvoiceVersionReview::query()->whereIn('invoice_version_id', $versions->modelKeys())->get()->keyBy('invoice_version_id');
        $rows = [];
        $total = BigInteger::zero();
        $committedUnpaid = BigInteger::zero();
        $unknown = 0;
        $overdue = 0;
        foreach ($invoices as $invoice) {
            $version = $versions->get($invoice->id);
            $review = $version === null ? null : $reviews->get($version->id);
            $intact = $version !== null && $version->hasValidSnapshot()
                && hash_equals(PaymentIntent::digest($version->snapshot['document']), PaymentIntent::digest(CaptureInvoiceVersion::documentContext($invoice)));
            $approved = $intact && $review !== null && $review->decision === 'approve_evidence' && $review->hasValidEvidence($version);
            try {
                $units = $version !== null ? ($intact ? (string) $version->valuation_base_units : null)
                    : ($institution->currency === 'USDC' ? (string) Money::fromDecimal((string) $invoice->getRawOriginal('amount'), CurrencyCode::USDC)->minorUnits : null);
            } catch (Throwable) {
                $units = null;
            }
            if ($units !== null && BigInteger::of($units)->isLessThanOrEqualTo(0)) {
                $units = null;
            }
            if ($units === null) {
                $unknown++;
            }
            if ($units !== null) {
                $total = $total->plus($units);
                $committedPrincipal = BigInteger::of($committedByInvoice[$invoice->id] ?? '0');
                $committedUnpaid = $committedUnpaid->plus($committedPrincipal->isGreaterThan($units) ? $units : $committedPrincipal);
            }
            $isOverdue = $invoice->due_date->lt(today());
            $overdue += (int) $isOverdue;
            $budgetUnits = null;
            try {
                $budgetUnits = $institution->currency === 'USDC' && $invoice->budget !== null && $invoice->budget->status === 'active'
                    ? (string) Money::fromDecimal((string) $invoice->budget->getRawOriginal('remaining_amount'), CurrencyCode::USDC)->minorUnits : null;
            } catch (Throwable) {
                $budgetUnits = null;
            }
            $label = match (true) {
                $units === null => 'Amount needs verification',
                isset($committedByInvoice[$invoice->id]) => 'Already committed; inspect payment',
                ! $approved => 'Evidence review needed',
                ! $available instanceof BigInteger => 'Funding unknown',
                BigInteger::of($units)->isGreaterThan($available) => 'Cash shortfall',
                $budgetUnits === null || BigInteger::of($units)->isGreaterThan($budgetUnits) => 'Budget shortfall',
                default => 'Fits individually; approval needed',
            };
            $rows[$invoice->id] = ['id' => $invoice->id, 'reference' => $invoice->reference, 'amount_base_units' => $units,
                'evidence_approved' => $approved, 'funding_label' => $label, 'overdue' => $isOverdue,
                'cash_gap_base_units' => $units !== null && $available instanceof BigInteger ? (string) $this->nonNegative(BigInteger::of($units)->minus($available)) : null];
        }
        $totalKnown = $unknown === 0 && $invoices->count() <= 500;
        $uncommittedNeed = $this->nonNegative($total->minus($committedUnpaid));
        $gap = $available instanceof BigInteger && $totalKnown ? (string) $this->nonNegative($uncommittedNeed->minus($available)) : null;
        $rawGap = $cash !== null && $totalKnown ? (string) $this->nonNegative($total->minus($cash)) : null;
        $coverage = $available instanceof BigInteger && $totalKnown && $uncommittedNeed->isPositive()
            ? (int) (string) ($available->isGreaterThan($uncommittedNeed) ? $uncommittedNeed : $available)
                ->multipliedBy(100)->quotient($uncommittedNeed) : null;
        $snapshot = BudgetSnapshot::query()->where('organization_id', $institution->id)->latest('id')->first();
        $invalidTuitionAttempts = 0;
        $openTuition = StudentTuitionPaymentAttempt::query()->whereNull('posted_at')->where('state', 'verified')->limit(101)->get();
        foreach ($openTuition as $attempt) {
            if (! $attempt->hasValidConsent() || ! $attempt->hasValidSettlement()) {
                $invalidTuitionAttempts++;
            }
        }
        if ($invalidTuitionAttempts > 0) {
            $blockers[] = $invalidTuitionAttempts.' tuition attempt(s) claim verification without intact consent or settlement proof. Investigate; do not submit replacement payments.';
        }

        return ['institution_name' => $institution->name, 'observation' => $observation,
            'ledger_balance' => $wallet?->getRawOriginal('balance'), 'ledger_base_units' => $ledger, 'ledger_drift_base_units' => $drift,
            'raw_funding_gap_base_units' => $rawGap, 'invalid_tuition_attempts' => $invalidTuitionAttempts, 'protected_reserve_base_units' => $reserve,
            'cash_coverage_percent' => $coverage,
            'committed_unpaid_base_units' => $held === null ? null : (string) $committedUnpaid,
            'uncommitted_bill_need_base_units' => $held !== null && $totalKnown ? (string) $uncommittedNeed : null,
            'committed_base_units' => $held, 'restricted_base_units' => $restricted instanceof BigInteger ? (string) $restricted : null,
            'available_base_units' => $available instanceof BigInteger ? (string) $available : null, 'reviewed_receipts_base_units' => (string) $reviewed,
            'unreviewed_collections' => $unreviewed, 'unpaid_base_units' => $totalKnown ? (string) $total : null,
            'unpaid_count' => $invoices->count(), 'overdue_count' => $overdue, 'funding_gap_base_units' => $gap,
            'bills' => $rows, 'blockers' => array_values(array_unique($blockers)),
            'policy_title' => $publication?->version->title, 'policy_revision' => $publication?->institution_policy_version_id,
            'snapshot_id' => $snapshot?->id, 'pending_submissions' => PaymentSubmissionOutbox::query()->where('organization_id', $institution->id)
                ->whereIn('state', PaymentSubmissionOutbox::OPEN_STATES)->count(),
            'classification_engine' => config('eduflow.institution_policy.classification_engine') === 'jev' ? 'JEV' : 'Policy analyst',
            'ai_advisory_enabled' => $this->aiSettings->mayCallProvider(),
            'can_execute' => false, 'funding_approved' => false];
    }

    private function nonNegative(BigInteger $value): BigInteger
    {
        return $value->isNegative() ? BigInteger::zero() : $value;
    }
}
