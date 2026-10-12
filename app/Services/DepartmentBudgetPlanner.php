<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\CaptureBudgetSnapshot;
use App\Actions\CaptureInvoiceVersion;
use App\Models\Budget;
use App\Models\BudgetSnapshot;
use App\Models\Invoice;
use App\Models\InvoiceVersion;
use App\Models\InvoiceVersionReview;
use App\Models\PaymentIntent;
use App\Models\User;
use Brick\Math\BigInteger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Cumulative suggestion for one closed bill set, not reservations or funded payment authority. */
final readonly class DepartmentBudgetPlanner
{
    public function __construct(private InstallationInstitution $institutions, private ReviewedCollections $collections) {}

    /** @return array<string, mixed> */
    public function handle(User $actor, BudgetSnapshot $evidence): array
    {
        Gate::forUser($actor)->authorize('view', $evidence);

        return $this->build($evidence, $this->institutions->require()->id);
    }

    /** Read-only service capability: no human identity, posting or payment authority.
     * @return array<string, mixed>
     */
    public function forBackground(BudgetSnapshot $evidence): array
    {
        if (! config('eduflow.background_finance.enabled', false) || $evidence->organization_id !== $this->institutions->require()->id
            || ! in_array($evidence->snapshot['schema_version'] ?? null, [2, 3], true)) {
            throw ValidationException::withMessages(['background' => 'Background planning requires explicit enablement and current institution reviewed evidence.']);
        }

        return $this->build($evidence, $evidence->organization_id);
    }

    /** @return array<string, mixed> */
    private function build(BudgetSnapshot $evidence, int $institutionId): array
    {
        /** @var BudgetSnapshot $stored */
        $stored = BudgetSnapshot::query()->where('organization_id', $institutionId)->whereKey($evidence->id)->firstOrFail();
        if (! $stored->hasValidSnapshot() || Carbon::parse($stored->snapshot['as_of'])->isFuture()
            || Carbon::parse($stored->snapshot['valid_until'])->lte(now())) {
            throw ValidationException::withMessages(['snapshot' => 'Planning evidence is stale or failed integrity checks.']);
        }
        /** @var Budget|null $budget */
        $budget = Budget::query()->where('organization_id', $institutionId)->whereKey($stored->budget_id)->first();
        if ($budget === null || $budget->status !== 'active'
            || ($stored->snapshot['budget_fingerprint'] ?? null) !== CaptureBudgetSnapshot::budgetFingerprint($budget)) {
            throw ValidationException::withMessages(['budget' => 'Planning requires the recorded active institution budget.']);
        }
        $this->collections->requireBound($stored);
        $headroom = $stored->headroom();
        $remainingBudget = BigInteger::of($headroom['budget_minor_units']);
        $remainingCash = BigInteger::of($headroom['cash_minor_units']);
        $bound = [];
        foreach ($stored->snapshot['bills'] as $entry) {
            $bound[$entry['id']] = $entry;
        }
        /** @var Collection<int, InvoiceVersion> $bills */
        $bills = InvoiceVersion::query()->where('organization_id', $institutionId)->whereIn('id', array_keys($bound))->get();
        if ($bills->count() !== count($bound)) {
            throw ValidationException::withMessages(['bills' => 'Bound source bill evidence is missing.']);
        }
        $decisions = [];
        foreach ($bills as $bill) {
            $entry = $bound[$bill->id];
            /** @var InvoiceVersionReview|null $mapping */
            $mapping = InvoiceVersionReview::query()->where('invoice_version_id', $bill->id)->first();
            /** @var Invoice|null $invoice */
            $invoice = Invoice::query()->where('organization_id', $institutionId)->whereKey($bill->invoice_id)->first();
            $intact = $bill->hasValidSnapshot() && $bill->budget_id === $stored->budget_id && $bill->source_currency === $stored->currency
                && hash_equals($entry['digest'], $bill->snapshot_digest) && $mapping !== null && $mapping->hasValidEvidence($bill)
                && $mapping->id === $entry['review_id'] && hash_equals($entry['review_digest'], $mapping->review_digest)
                && $invoice !== null && in_array($invoice->status, ['pending', 'held', 'escalated'], true)
                && hash_equals(PaymentIntent::digest($bill->snapshot['document']), PaymentIntent::digest(CaptureInvoiceVersion::documentContext($invoice)))
                && hash_equals(PaymentIntent::digest($bill->snapshot['department']), PaymentIntent::digest($stored->snapshot['department']));
            if (! $intact) {
                throw ValidationException::withMessages(['bills' => 'A bound source, evidence review or document changed; refresh the complete closed-set plan.']);
            }
            $decisions[$bill->id] = $mapping->decision;
        }
        $bills = $bills->sortBy(fn (InvoiceVersion $bill): string => $bill->snapshot['document']['due_date'].':'.str_pad((string) $bill->id, 20, '0', STR_PAD_LEFT));
        $reviews = [];
        $valuationTotal = BigInteger::zero();
        foreach ($bills as $bill) {
            $amount = BigInteger::of($bill->source_minor_units);
            $checks = ['evidence_intact' => true, 'evidence_approved' => $decisions[$bill->id] === 'approve_evidence',
                'allocation_available' => $amount->isLessThanOrEqualTo($remainingBudget), 'realized_cash_available' => $amount->isLessThanOrEqualTo($remainingCash)];
            $eligible = ! in_array(false, $checks, true);
            if ($eligible) {
                $remainingBudget = $remainingBudget->minus($amount);
                $remainingCash = $remainingCash->minus($amount);
                $valuationTotal = $valuationTotal->plus($bill->valuation_base_units);
            }
            $reviews[] = ['invoice_version_id' => $bill->id, 'invoice_id' => $bill->invoice_id, 'source_minor_units' => (string) $bill->source_minor_units,
                'valuation_base_units' => (string) $bill->valuation_base_units, 'decision' => $eligible ? 'propose_human_review' : 'hold', 'checks' => $checks,
                'remaining_budget_minor_units' => (string) $remainingBudget, 'remaining_cash_minor_units' => (string) $remainingCash, 'can_execute' => false];
        }

        return ['snapshot_id' => $stored->id, 'snapshot_digest' => $stored->snapshot_digest, 'currency' => $stored->currency,
            'department' => $stored->snapshot['department'], 'as_of' => $stored->snapshot['as_of'], 'valid_until' => $stored->snapshot['valid_until'],
            'collection_evidence' => ['mode' => in_array($stored->snapshot['schema_version'] ?? null, [2, 3], true) ? 'reviewed_batches' : 'legacy_staff_attestation',
                'review_ids' => array_column($stored->snapshot['collections'] ?? [], 'review_id'),
                'received_minor_units' => $stored->snapshot['amounts']['realized_receipts'], 'bank_balance_verified' => false,
                'opening_funds_exclude_collections' => $stored->snapshot['opening_funds_exclude_collections'] ?? null],
            'headroom' => $headroom, 'bills' => $reviews, 'suggested_usdc_valuation_base_units' => (string) $valuationTotal,
            'remaining_budget_minor_units' => (string) $remainingBudget, 'remaining_cash_minor_units' => (string) $remainingCash,
            'can_execute' => false, 'funds_reserved' => false, 'settlement_funding_verified' => false, 'actual_accounts_changed' => false];
    }
}
