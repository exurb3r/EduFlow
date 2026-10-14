<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\Budget;
use App\Models\BudgetSnapshot;
use App\Models\FinancePolicyActivation;
use App\Models\InstitutionPolicyAssessment;
use App\Models\InstitutionPolicyPublication;
use App\Models\InstitutionPolicyVersion;
use App\Models\Invoice;
use App\Models\InvoiceVersion;
use App\Models\InvoiceVersionReview;
use App\Models\PaymentIntent;
use App\Models\User;
use Brick\Math\BigInteger;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * Turns realised receipt cash into ranked allocation proposals.
 *
 * This decides nothing and moves nothing. The split it produces is a proposal
 * an officer reviews, exactly like a department plan, and every amount is
 * bounded by arithmetic this class performs itself.
 *
 * The division of labour is deliberate:
 *
 *   - The model classifies each candidate against the institution's own
 *     published categories. That is judgement over wording, which is what a
 *     model is for.
 *   - This class decides order and amounts. Order comes from the order staff
 *     arranged their categories in the workspace, and every figure is exact
 *     base-unit arithmetic bounded by captured headroom.
 *
 * So the model cannot invent a category, a priority or an amount, and a wrong
 * classification downgrades a line to review rather than moving it.
 */
final readonly class ReceiptAllocationProposal
{
    private const int MAX_TARGETS = 25;

    public function __construct(private InstitutionPolicyWorkspace $policies, private InstitutionPolicyInterpreter $interpreter,
        private DepartmentBudgetPlanner $planner) {}

    /**
     * Build a proposal for the cash a snapshot has actually realised.
     *
     * $actor is required rather than optional: the underlying planner refuses
     * background re-derivation without an identity, and an allocation proposal
     * is always somebody asking. Naming the requester is also what makes the
     * stored plan attributable.
     *
     * @return array<string, mixed>
     */
    public function build(User $actor, BudgetSnapshot $snapshot, ?Closure $progress = null, ?string $assessmentNamespace = null): array
    {
        $publication = $this->policies->active();
        if (! $publication instanceof InstitutionPolicyPublication || $publication->organization_id !== $snapshot->organization_id) {
            throw ValidationException::withMessages(['policy' => 'No published institution policy with active controls covers this receipt.']);
        }
        if (! $snapshot->hasValidSnapshot() || $snapshot->budget->status !== 'active') {
            throw ValidationException::withMessages(['budget' => 'Allocation requires an intact active budget snapshot.']);
        }
        if ($snapshot->currency !== 'USDC') {
            throw ValidationException::withMessages(['currency' => 'Receipt allocation requires exact USDC evidence. No implicit currency conversion is performed.']);
        }
        // Re-derive rather than trust the stored plan: a proposal built from a
        // stale row would cite evidence that no longer holds.
        $plan = $this->planner->handle($actor, $snapshot);
        $headroom = $snapshot->headroom();
        $policy = $publication->version;

        $targets = $this->collectTargets($snapshot, $plan, $policy, $progress, $assessmentNamespace);
        if ($targets === []) {
            throw ValidationException::withMessages(['allocation' => 'No approved invoice or active budget line is available to propose against.']);
        }

        $progress?->__invoke('allocation_checks', 'Checking exact amounts against available budget and received cash.', count($targets), count($targets));
        $lines = [];
        $remainingBudget = BigInteger::of($headroom['budget_minor_units']);
        $reserve = BigInteger::of(FinancePolicyActivation::current($snapshot->organization_id)?->policyVersion->minimum_reserve_base_units ?? 0);
        $capturedReserve = BigInteger::of($snapshot->snapshot['amounts']['protected_reserve']);
        $additionalReserve = $reserve->isGreaterThan($capturedReserve) ? $reserve->minus($capturedReserve) : BigInteger::zero();
        $cash = BigInteger::of($headroom['cash_minor_units'])->minus($additionalReserve);
        $remainingCash = $cash->isNegative() ? BigInteger::zero() : $cash;
        $headroom['cash_minor_units'] = (string) $remainingCash;

        foreach ($targets as $target) {
            $asked = BigInteger::of($target['amount_minor_units']);
            $affordable = $asked->isLessThanOrEqualTo($remainingBudget) && $asked->isLessThanOrEqualTo($remainingCash);
            $category = $target['assessment']['category_id'] ?? null;
            $ranked = $category !== null && ($target['assessment']['needs_review'] ?? true) === false;
            $grant = $affordable && $ranked ? $asked : BigInteger::zero();

            $lines[] = [
                'target_type' => $target['target_type'],
                'target_id' => $target['target_id'],
                'target_name' => $target['target_name'],
                'invoice_version_id' => $target['invoice_version_id'],
                'budget_category' => $target['budget_category'],
                'amount_minor_units' => (string) $asked,
                'proposed_minor_units' => (string) $grant,
                'currency' => $snapshot->currency,
                'category_id' => $category,
                'priority_rank' => $target['priority_rank'],
                'citations' => $target['assessment']['citations'] ?? [],
                'explanation' => $target['assessment']['explanation'] ?? null,
                'decision' => $grant->isPositive() ? 'propose_human_review' : 'hold',
                'checks' => [
                    'policy_classified' => $ranked,
                    'allocation_available' => $asked->isLessThanOrEqualTo($remainingBudget),
                    'realized_cash_available' => $asked->isLessThanOrEqualTo($remainingCash),
                ],
                'remaining_budget_minor_units' => (string) $remainingBudget,
                'remaining_cash_minor_units' => (string) $remainingCash,
                'can_execute' => false,
            ];

            if ($grant->isPositive()) {
                $remainingBudget = $remainingBudget->minus($grant);
                $remainingCash = $remainingCash->minus($grant);
            }
        }

        $proposed = BigInteger::zero();
        foreach ($lines as $line) {
            $proposed = $proposed->plus($line['proposed_minor_units']);
        }
        $this->planner->handle($actor, $snapshot);
        foreach ($targets as $target) {
            if ($target['target_type'] !== 'budget_line') {
                continue;
            }
            $budget = Budget::query()->where('organization_id', $snapshot->organization_id)->find($target['target_id']);
            if ($budget === null || ! hash_equals($target['evidence_digest'], PaymentIntent::digest(['budget' => $budget->getRawOriginal()]))) {
                throw ValidationException::withMessages(['budget' => 'A target budget changed during classification. Request a fresh run.']);
            }
        }
        if ($this->policies->active()?->publication_digest !== $publication->publication_digest) {
            throw ValidationException::withMessages(['policy' => 'Active policy changed during allocation. Request a fresh run.']);
        }
        $reserveIntact = $proposed->isLessThanOrEqualTo(BigInteger::of($headroom['cash_minor_units']));

        return [
            'schema_version' => 1,
            'purpose' => 'receipt_allocation_proposal_only',
            'service_capability' => 'published_policy_allocation_proposal',
            'snapshot_id' => $snapshot->id,
            'snapshot_digest' => $snapshot->snapshot_digest,
            'organization_id' => $snapshot->organization_id,
            'policy_version_id' => $policy->id,
            'policy_digest' => $policy->content_digest,
            'publication_digest' => $publication->publication_digest,
            'currency' => $snapshot->currency,
            'as_of' => now()->utc()->startOfSecond()->toIso8601String(),
            'realized_receipts_minor_units' => $snapshot->snapshot['amounts']['realized_receipts'],
            'headroom' => $headroom,
            'targets_considered' => count($targets),
            'lines' => $lines,
            'proposed_minor_units' => (string) $proposed,
            'unallocated_minor_units' => (string) $remainingCash,
            'protected_reserve_minor_units' => (string) ($reserve->isGreaterThan($capturedReserve) ? $reserve : $capturedReserve),
            'reserve_intact' => $reserveIntact,
            'proposals_awaiting_human_review' => count(array_filter($lines, fn (array $line): bool => $line['decision'] === 'propose_human_review')),
            'can_execute' => false,
            'funds_reserved' => false,
            'payment_approved' => false,
            'actual_accounts_changed' => false,
        ];
    }

    /**
     * Enumerate approved invoices and active budget lines, then rank them.
     *
     * @param  array<string, mixed>  $plan
     * @return list<array<string, mixed>>
     */
    private function collectTargets(BudgetSnapshot $snapshot, array $plan, InstitutionPolicyVersion $policy, ?Closure $progress, ?string $assessmentNamespace): array
    {
        $rank = [];
        foreach ($policy->content['categories'] as $index => $category) {
            $rank[$category['id']] = $index;
        }

        $targets = [];
        $approved = [];
        foreach ($plan['bills'] as $bill) {
            $approved[$bill['invoice_version_id']] = $bill;
        }
        /** @var Collection<int, InvoiceVersion> $versions */
        $versions = InvoiceVersion::query()->where('organization_id', $snapshot->organization_id)->whereIn('id', array_keys($approved))->get();
        foreach ($versions as $version) {
            $review = InvoiceVersionReview::query()->where('invoice_version_id', $version->id)->first();
            if ($review === null) {
                continue;
            }
            if ($review->decision !== 'approve_evidence') {
                continue;
            }
            if (! $review->hasValidEvidence($version)) {
                continue;
            }
            /** @var Invoice|null $invoice */
            $invoice = Invoice::query()->where('organization_id', $snapshot->organization_id)->find($version->invoice_id);
            if ($invoice === null) {
                continue;
            }
            $targets[] = ['target_type' => 'supplier_invoice', 'target_id' => $invoice->id, 'target_name' => (string) $invoice->reference,
                'invoice_version_id' => $version->id, 'budget_category' => $invoice->category,
                'amount_minor_units' => (string) $version->valuation_base_units,
                'evidence_digest' => $version->snapshot_digest,
                'description' => 'Approved supplier invoice '.$invoice->reference.' for '.Money::formatExact($version->source_minor_units, $version->source_currency)
                    .' due '.$invoice->due_date.'. Vendor category: '.$invoice->category];
        }

        /** @var Collection<int, Budget> $budgets */
        $budgets = Budget::query()->where('organization_id', $snapshot->organization_id)->where('status', 'active')
            ->where('remaining_amount', '>', 0)->orderBy('category')->orderBy('name')->limit(self::MAX_TARGETS)->get();
        foreach ($budgets as $budget) {
            // Budget lines are offered at what the snapshot can still fund, not
            // at their nominal remaining balance, so a line never proposes more
            // than the captured headroom could cover.
            $headroom = $snapshot->headroom();
            $budgetRemaining = Money::fromDecimal((string) $budget->getRawOriginal('remaining_amount'), CurrencyCode::USDC)->minorUnits;
            $ceiling = min($budgetRemaining, (int) $headroom['cash_minor_units'], (int) $headroom['budget_minor_units']);
            if ($ceiling <= 0) {
                continue;
            }
            $targets[] = ['target_type' => 'budget_line', 'target_id' => $budget->id, 'target_name' => $budget->name,
                'invoice_version_id' => null, 'budget_category' => $budget->category,
                'amount_minor_units' => (string) $ceiling,
                'evidence_digest' => PaymentIntent::digest(['budget' => $budget->getRawOriginal()]),
                'description' => 'Active budget line "'.$budget->name.'" in category '.$budget->category
                    .' with '.$budget->getRawOriginal('remaining_amount').' remaining. Propose at most the currently fundable amount.'];
        }

        if (count($targets) > self::MAX_TARGETS) {
            $targets = array_slice($targets, 0, self::MAX_TARGETS);
        }

        $classified = [];
        $deadline = hrtime(true) + 140_000_000_000;
        $progress?->__invoke('targets_loaded', 'Loaded '.count($targets).' approved invoices and active budget lines.', 0, count($targets));
        foreach ($targets as $index => $target) {
            $remainingSeconds = intdiv($deadline - hrtime(true), 1_000_000_000);
            if ($remainingSeconds < 3) {
                throw ValidationException::withMessages(['allocation' => 'Evaluation time budget reached. No partial allocation plan accepted.']);
            }
            $progress?->__invoke('classifying', 'Checking '.$target['target_name'].' against saved policy clauses.', $index, count($targets));
            $assessment = $this->classify($policy, $snapshot, $target, $assessmentNamespace, $remainingSeconds);
            $category = $assessment['category_id'] ?? null;
            $classified[] = [...$target, 'assessment' => $assessment,
                'priority_rank' => $rank[$category] ?? PHP_INT_MAX];
            $progress?->__invoke('classified', $target['target_name'].': '.(($assessment['needs_review'] ?? true)
                ? 'needs review; no allocation authority.' : $category.'; policy citations validated.'), $index + 1, count($targets));
        }

        // Order is staff's category order first, and the model has no say in ordering
        // at all. Within one category rank an approved invoice outranks a
        // budget line, because an invoice is a specific known obligation while
        // a budget line is an open-ended destination: comparing the two type
        // strings directly would let a line classified as an invoice consume
        // the whole headroom and starve the actual invoices behind it.
        $typeOrder = ['supplier_invoice' => 0, 'budget_line' => 1];
        usort($classified, fn (array $a, array $b): int => [$a['priority_rank'], $typeOrder[$a['target_type']], $a['target_id']]
            <=> [$b['priority_rank'], $typeOrder[$b['target_type']], $b['target_id']]);

        return $classified;
    }

    /**
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    private function classify(InstitutionPolicyVersion $policy, BudgetSnapshot $snapshot, array $target, ?string $assessmentNamespace, int $remainingSeconds): array
    {
        $input = ['description' => $target['description'], 'target_type' => $target['target_type'],
            'evidence_digest' => $target['evidence_digest'], 'policy_digest' => $policy->content_digest];
        $requestKey = 'eduflow:allocation:'.$policy->content_digest.':'.$snapshot->snapshot_digest.':'.$target['target_type'].':'.$target['target_id']
            .':'.PaymentIntent::digest($input).':'.($assessmentNamespace ?? 'cached');
        $assessment = InstitutionPolicyAssessment::query()->firstOrCreate(['request_key' => Uuid::uuid5(Uuid::NAMESPACE_URL, $requestKey)->toString()],
            ['organization_id' => $policy->organization_id, 'institution_policy_version_id' => $policy->id, 'requested_by' => null,
                'kind' => 'classification', 'input' => $input, 'input_digest' => PaymentIntent::digest($input), 'policy_digest' => $policy->content_digest]);
        if ($assessment->hasValidResult()) {
            return $assessment->result;
        }
        $held = ['category_id' => null, 'needs_review' => true, 'citations' => [],
            'explanation' => 'Classification unavailable or still processing. Request a fresh evaluation; no amount is proposed.'];
        $claimed = DB::table($assessment->getTable())->where('id', $assessment->id)->where('state', 'queued')->whereNull('completed_at')->update(['state' => 'running']);
        if ($claimed !== 1) {
            return $held;
        }
        $assessment->refresh();

        try {
            $result = $this->interpreter->interpret($policy, 'classification', $input, $remainingSeconds);
        } catch (\Throwable $exception) {
            report($exception);
            $assessment->update(['state' => 'needs_review', 'completed_at' => now(),
                'last_error' => 'Allocation classification unavailable. This line is held for human review and no amount is proposed.']);

            return $held;
        }

        $assessment->update(['state' => 'completed', 'result' => $result, 'result_digest' => PaymentIntent::digest($result),
            'completed_at' => now(), 'last_error' => null]);

        return $result;
    }
}
