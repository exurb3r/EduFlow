<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\Budget;
use App\Models\BudgetSnapshot;
use App\Models\InvoiceVersion;
use App\Models\InvoiceVersionReview;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Services\FinanceWorkflowDispatcher;
use App\Services\FinanceWorkflows;
use App\Services\InstallationInstitution;
use App\Services\ReviewedCollections;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class CaptureBudgetSnapshot
{
    public function __construct(
        private InstallationInstitution $institutions,
        private ReviewedCollections $collections,
        private FinanceWorkflows $workflows,
        private FinanceWorkflowDispatcher $dispatcher,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, Budget $budget, array $data): BudgetSnapshot
    {
        Gate::forUser($actor)->authorize('create', BudgetSnapshot::class);
        $data = Validator::make($data, self::inputRules())->validate();
        $currency = CurrencyCode::from($data['currency']);
        $amounts = [];
        foreach (BudgetSnapshot::AMOUNT_FIELDS as $field) {
            try {
                $money = Money::fromDecimal($data[$field], $currency);
                if ($money->minorUnits < 0) {
                    throw new \InvalidArgumentException('Negative input.');
                }
                $amounts[$field] = (string) $money->minorUnits;
            } catch (Throwable) {
                throw ValidationException::withMessages([$field => 'An exact non-negative local-currency decimal string is required.']);
            }
        }
        $institution = $this->institutions->require();

        return DB::transaction(function () use ($institution, $actor, $budget, $data, $currency, $amounts): BudgetSnapshot {
            Organization::query()->whereKey($institution->id)->lockForUpdate()->firstOrFail();
            /** @var Budget $storedBudget */
            $storedBudget = Budget::query()->where('organization_id', $institution->id)->whereKey($budget->id)->lockForUpdate()->firstOrFail();
            if ($storedBudget->status !== 'active') {
                throw ValidationException::withMessages(['budget' => 'An active institution department budget is required.']);
            }
            Gate::forUser($actor)->authorize('create', BudgetSnapshot::class);
            $reviewIds = array_map(intval(...), $data['collection_review_ids'] ?? []);
            $collections = $this->collections->capture($institution->id, $currency->value, $reviewIds, $data['as_of'],
                $amounts['realized_receipts'], $amounts['restricted_cash']);

            $movementReceipts = BigInteger::zero();
            foreach ($collections as $entry) {
                $movementReceipts = $movementReceipts->plus($entry['movement_receipt_minor_units']);
            }

            if (! $movementReceipts->isZero() && $amounts['opening_funds'] !== '0') {
                throw ValidationException::withMessages(['opening_funds' => 'This plan already counts receipts whose money is in the treasury balance, so a typed opening balance could count it a second time. Start from receipts, or exclude those receipts from this plan.']);
            }
            $ids = array_map(intval(...), $data['bill_ids']);
            sort($ids, SORT_NUMERIC);
            $bills = [];
            $department = ['name' => $data['department'], 'period_start' => $data['period_start'], 'period_end' => $data['period_end']];
            foreach ($ids as $id) {
                /** @var InvoiceVersion $bill */
                $bill = InvoiceVersion::query()->where('organization_id', $institution->id)->where('budget_id', $storedBudget->id)->whereKey($id)->lockForUpdate()->firstOrFail();
                /** @var InvoiceVersionReview|null $review */
                $review = InvoiceVersionReview::query()->where('invoice_version_id', $bill->id)->first();
                if (! $bill->hasValidSnapshot() || $bill->source_currency !== $currency->value
                    || ! hash_equals(PaymentIntent::digest($bill->snapshot['department']), PaymentIntent::digest($department))
                    || $review === null || ! $review->hasValidEvidence($bill)) {
                    throw ValidationException::withMessages(['bill_ids' => 'Selected bills need intact reviewed mapping evidence in the same department, period and local currency.']);
                }
                $bills[] = ['id' => $bill->id, 'digest' => $bill->snapshot_digest, 'review_id' => $review->id, 'review_digest' => $review->review_digest];
            }
            $snapshot = [
                'schema_version' => 3, 'purpose' => 'department_budget_planning', 'capture_key' => Str::lower($data['capture_key']),
                'institution_id' => $institution->id, 'budget_id' => $storedBudget->id, 'prepared_by' => $actor->id,
                'budget_fingerprint' => self::budgetFingerprint($storedBudget),
                'currency' => $currency->value, 'department' => $department, 'as_of' => $data['as_of'], 'valid_until' => $data['valid_until'],
                'amounts' => $amounts, 'bills' => $bills,
                'evidence' => ['budget' => $data['budget_evidence'], 'cash' => $data['cash_evidence'], 'commitments' => $data['commitment_evidence']],
                'commitments_exclude_selected_bills' => true, 'cash_buckets_disjoint' => true,
                'opening_funds_exclude_collections' => true, 'movement_receipts_minor_units' => (string) $movementReceipts,
                'collections' => $collections,
            ];
            /** @var BudgetSnapshot|null $existing */
            $existing = BudgetSnapshot::query()->where('capture_key', $snapshot['capture_key'])->first();
            if ($existing !== null) {
                if (($existing->snapshot['schema_version'] ?? null) === 2 && $movementReceipts->isZero()) {
                    $snapshot['schema_version'] = 2;
                    unset($snapshot['movement_receipts_minor_units']);
                    $snapshot['collections'] = array_map(static function (array $entry): array {
                        unset($entry['movement_receipt_minor_units']);

                        return $entry;
                    }, $snapshot['collections']);
                }
                $snapshot['prepared_by'] = $existing->prepared_by;
                if ($existing->organization_id !== $institution->id || ! $existing->hasValidSnapshot()
                    || ! hash_equals($existing->snapshot_digest, PaymentIntent::digest($snapshot))) {
                    throw ValidationException::withMessages(['capture_key' => 'Capture identity conflicts with previously recorded budget/cash evidence.']);
                }

                $this->collections->requireBound($existing);
                $this->dispatcher->wakeAfterCommit('budget:'.$existing->id);

                return $existing;
            }
            /** @var BudgetSnapshot $record */
            $record = BudgetSnapshot::query()->create(['capture_key' => $snapshot['capture_key'], 'organization_id' => $institution->id,
                'budget_id' => $storedBudget->id, 'prepared_by' => $actor->id, 'currency' => $currency->value,
                'snapshot' => $snapshot, 'snapshot_digest' => PaymentIntent::digest($snapshot)]);
            $record->collectionReviews()->attach(array_column($collections, 'review_id'));
            $this->collections->requireBound($record);
            $this->workflows->budgetCaptured($record);

            $this->dispatcher->wakeAfterCommit('budget:'.$record->id);

            activity('finance')->causedBy($actor)->performedOn($record)->event('budget_snapshot_captured')
                ->withProperties(['budget_id' => $storedBudget->id, 'snapshot_digest' => $record->snapshot_digest, 'can_execute' => false])
                ->log('Exact departmental allocation and realized local cash captured for closed-set department planning');

            return $record;
        }, 3);
    }

    public static function budgetFingerprint(Budget $budget): string
    {
        $content = [];
        foreach (['organization_id', 'name', 'category', 'allocated_amount', 'spent_amount', 'remaining_amount', 'status'] as $field) {
            $content[$field] = $budget->getRawOriginal($field);
        }

        return PaymentIntent::digest($content);
    }

    /** @return array<string, mixed> */
    public static function inputRules(): array
    {
        $rules = [
            'capture_key' => ['required', 'uuid'], 'currency' => ['required', Rule::in(CurrencyCode::values())],
            'department' => ['required', 'string', 'min:2', 'max:120'], 'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'as_of' => ['required', 'date_format:Y-m-d\TH:i:sP', 'before_or_equal:now'],
            'valid_until' => ['required', 'date_format:Y-m-d\TH:i:sP', 'after:as_of', 'after:now'],
            'bill_ids' => ['required', 'array', 'list', 'min:1', 'max:100'], 'bill_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'budget_evidence' => ['required', 'string', 'min:3', 'max:255'], 'cash_evidence' => ['required', 'string', 'min:3', 'max:255'],
            'commitment_evidence' => ['required', 'string', 'min:3', 'max:255'],
            'commitments_exclude_selected_bills' => ['required', 'accepted'], 'cash_buckets_disjoint' => ['required', 'accepted'],
            'opening_funds_exclude_collections' => ['required', 'accepted'],
            'collection_review_ids' => ['sometimes', 'array', 'list', 'max:100'],
            'collection_review_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
        foreach (BudgetSnapshot::AMOUNT_FIELDS as $field) {
            $rules[$field] = ['required', 'string', 'max:30'];
        }

        return $rules;
    }
}
