<?php

declare(strict_types=1);

use App\Actions\ActivateFinancePolicy;
use App\Actions\CaptureBudgetSnapshot;
use App\Actions\CaptureCollectionBatch;
use App\Actions\CaptureInvoiceVersion;
use App\Actions\CreateFinancePolicyVersion;
use App\Actions\ImportTuitionPaymentAsCollection;
use App\Actions\ReviewCollectionBatch;
use App\Actions\ReviewInvoiceVersion;
use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Jobs\ProcessFinanceWorkflow;
use App\Models\AcademicTerm;
use App\Models\Budget;
use App\Models\BudgetSnapshot;
use App\Models\CollectionBatch;
use App\Models\CollectionBatchReview;
use App\Models\FinanceWorkflowRun;
use App\Models\Invoice;
use App\Models\InvoiceVersion;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\Student;
use App\Models\StudentTuitionPayment;
use App\Models\StudentTuitionPaymentAttempt;
use App\Models\StudentWallet;
use App\Models\Transaction;
use App\Models\TuitionAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Wallet;
use App\Services\DepartmentBudgetPlanner;
use App\Services\FinanceReviewAlerts;
use App\Services\FinanceWorkflowDispatcher;
use App\Services\FinanceWorkflows;
use App\Services\FundingWindowContext;
use App\Services\ReviewedCollections;
use Illuminate\Contracts\Bus\QueueingDispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;

const WAKE_TREASURY = '0x2629de3b67042842ab2a2e33a1bd81ca583d975c';

/**
 * B2 background coordination.
 *
 * Tests the captured closed-set plan wake, not automatic selection of a budget
 * on receipt review. Queue delivery may retry; proposal identity stays unique.
 *
 * @return array{actor: User, reviewer: User, budget: Budget, bill: InvoiceVersion, batch: CollectionBatch, institution: Organization}
 */
function wakeContext(): array
{
    config(['eduflow.institution_id' => null, 'eduflow.background_finance.enabled' => true,
        'eduflow.background_finance.queue_connection' => 'database',
        'lepton.default' => 'fake', 'lepton.arc.chain' => 'ARC-TESTNET', 'lepton.arc.treasury' => WAKE_TREASURY]);
    app()->instance(ArcNetworkGateway::class, Mockery::mock(ArcNetworkGateway::class));
    app()->instance(WalletGateway::class, Mockery::mock(WalletGateway::class));

    /** @var Organization $institution */
    $institution = Organization::factory()->create(['currency' => 'PHP']);

    Wallet::factory()->create(['organization_id' => $institution->id, 'address' => WAKE_TREASURY,
        'balance' => 5.00, 'status' => 'active']);
    /** @var User $actor */
    $actor = User::factory()->create();
    $actor->assignRole(Role::findOrCreate('finance_officer', 'web'));
    /** @var User $reviewer */
    $reviewer = User::factory()->create();
    $reviewer->assignRole(Role::findOrCreate('admin', 'web'));

    /** @var Budget $budget */
    $budget = Budget::query()->create(['organization_id' => $institution->id, 'name' => 'Teaching', 'category' => 'software',
        'allocated_amount' => '100', 'spent_amount' => '0', 'remaining_amount' => '100', 'status' => 'active']);
    /** @var Vendor $vendor */
    $vendor = Vendor::query()->create(['organization_id' => $institution->id, 'name' => 'Teaching alias',
        'wallet_address' => '0x'.str_repeat('2', 40), 'status' => 'verified', 'risk_level' => 'low']);
    /** @var Invoice $invoice */
    $invoice = Invoice::query()->create(['organization_id' => $institution->id, 'vendor_id' => $vendor->id, 'budget_id' => $budget->id,
        'reference' => 'LMS-'.Str::uuid(), 'amount' => '25', 'due_date' => now()->addDay(), 'category' => 'software', 'status' => 'pending']);
    $bill = app(CaptureInvoiceVersion::class)->handle($actor, $invoice, ['capture_key' => (string) Str::uuid(), 'source_amount' => '25',
        'source_currency' => 'PHP', 'source_evidence' => 'synthetic-bill', 'business_approval_reference' => 'synthetic-approval',
        'department' => 'Teaching', 'period_start' => now()->toDateString(), 'period_end' => now()->addDays(14)->toDateString(),
        'source_per_usdc' => '50', 'rate_source' => 'staff-reference', 'rate_observed_at' => now()->subMinute()->toIso8601String(), 'rounding' => 'down']);
    app(ReviewInvoiceVersion::class)->handle($reviewer, $bill, $bill->snapshot_digest, 'approve_evidence', 'Checked.');

    $batch = app(CaptureCollectionBatch::class)->handle($actor, ['capture_key' => (string) Str::uuid(), 'source_stream' => 'cashier',
        'source_reference' => 'report-wake', 'source_document_digest' => hash('sha256', 'wake-report'), 'currency' => 'PHP',
        'received_amount' => '100', 'restricted_amount' => '10', 'collected_from' => now()->subDays(2)->toIso8601String(),
        'collected_until' => now()->subDay()->toIso8601String(), 'cash_evidence_reference' => 'synthetic-receipts',
        'source_stream_disjoint' => true, 'received_not_forecast' => true]);

    return ['actor' => $actor, 'reviewer' => $reviewer, 'budget' => $budget, 'bill' => $bill, 'batch' => $batch,
        'institution' => $institution];
}

/**
 * A reviewed bill denominated in USDC.
 *
 * A plan may only count bills in its own currency, so exercising the USDC
 * opening-funds rule needs a USDC bill rather than reusing the PHP one.
 *
 * @param  array{actor: User, reviewer: User, budget: Budget, bill: InvoiceVersion, batch: CollectionBatch, institution: Organization}  $c
 */
function usdcBill(array $c): InvoiceVersion
{
    /** @var Vendor $vendor */
    $vendor = Vendor::query()->create(['organization_id' => $c['institution']->id, 'name' => 'USDC teaching alias',
        'wallet_address' => '0x'.str_repeat('3', 40), 'status' => 'verified', 'risk_level' => 'low']);
    /** @var Invoice $invoice */
    $invoice = Invoice::query()->create(['organization_id' => $c['institution']->id, 'vendor_id' => $vendor->id,
        'budget_id' => $c['budget']->id, 'reference' => 'USDC-'.Str::uuid(), 'amount' => '25', 'due_date' => now()->addDay(),
        'category' => 'software', 'status' => 'pending']);
    $bill = app(CaptureInvoiceVersion::class)->handle($c['actor'], $invoice, ['capture_key' => (string) Str::uuid(),
        'source_amount' => '25', 'source_currency' => 'USDC', 'source_evidence' => 'synthetic-bill',
        'business_approval_reference' => 'synthetic-approval', 'department' => 'Teaching',
        'period_start' => now()->toDateString(), 'period_end' => now()->addDays(14)->toDateString(), 'source_per_usdc' => '1',
        'rate_source' => 'staff-reference', 'rate_observed_at' => now()->subMinute()->toIso8601String(), 'rounding' => 'down']);
    app(ReviewInvoiceVersion::class)->handle($c['reviewer'], $bill, $bill->snapshot_digest, 'approve_evidence', 'Checked.');

    return $bill;
}

/**
 * @param  array{actor: User, reviewer: User, budget: Budget, bill: InvoiceVersion, batch: CollectionBatch, institution: Organization}  $c
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function wakePlanInput(array $c, CollectionBatchReview $review, array $overrides = []): array
{
    return [...[
        'capture_key' => (string) Str::uuid(), 'currency' => 'PHP', 'department' => 'Teaching',
        'period_start' => now()->toDateString(), 'period_end' => now()->addDays(14)->toDateString(),
        'as_of' => now()->subMinute()->toIso8601String(), 'valid_until' => now()->addHour()->toIso8601String(),
        'bill_ids' => [$c['bill']->id], 'allocation' => '100', 'already_spent' => '0', 'other_budget_commitments' => '0',
        'opening_funds' => '0', 'realized_receipts' => '100', 'actual_outflows' => '0', 'restricted_cash' => '10',
        'protected_reserve' => '10', 'other_cash_commitments' => '0', 'budget_evidence' => 'synthetic-budget',
        'cash_evidence' => 'synthetic-cash', 'commitment_evidence' => 'synthetic-commitments',
        'commitments_exclude_selected_bills' => true, 'cash_buckets_disjoint' => true,
        'opening_funds_exclude_collections' => true, 'collection_review_ids' => [$review->id],
    ], ...$overrides];
}

/** @param array{actor: User, reviewer: User, budget: Budget, bill: InvoiceVersion, batch: CollectionBatch, institution: Organization} $c */
function approveWakeReceipt(array $c): CollectionBatchReview
{
    return app(ReviewCollectionBatch::class)->handle($c['reviewer'], $c['batch'], $c['batch']->snapshot_digest,
        'approve_receipts', 'independent-check', 'Source and restrictions checked.');
}

// ---------------------------------------------------------------------------
// The wake, not the sweep
// ---------------------------------------------------------------------------

test('capturing a plan that counts a reviewed receipt dispatches its proposal without a prompt', function (): void {
    Bus::fake();
    $c = wakeContext();
    $review = approveWakeReceipt($c);

    $before = Bus::dispatched(ProcessFinanceWorkflow::class)->count();

    $snapshot = app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], wakePlanInput($c, $review));

    $run = FinanceWorkflowRun::query()->where('kind', 'budget_plan')->firstOrFail();

    // Nobody asked for this: the capture itself dispatched the one job that
    // turns the reviewed receipt into a proposal, on top of the earlier
    // dispatch that resolved the receipt's own bookkeeping run.
    expect(Bus::dispatched(ProcessFinanceWorkflow::class)->count())->toBe($before + 1)
        ->and(Bus::dispatched(ProcessFinanceWorkflow::class)
            ->contains(fn (ProcessFinanceWorkflow $job): bool => $job->runId === $run->id))
        ->toBeTrue();

    expect($snapshot)->not->toBeNull();
});

test('the dispatched proposal is non-executable and authorizes nothing', function (): void {
    $c = wakeContext();
    $review = approveWakeReceipt($c);
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], wakePlanInput($c, $review));

    $run = FinanceWorkflowRun::query()->where('kind', 'budget_plan')->firstOrFail();
    expect($run->budget_snapshot_id)->toBe($snapshot->id);

    $proposal = app(DepartmentBudgetPlanner::class)->forBackground($snapshot);

    (new ProcessFinanceWorkflow($run->id))->handle(app(FinanceWorkflows::class), app(FinanceReviewAlerts::class));
    $run->refresh();

    expect($run->state)->toBe('waiting_for_review')
        ->and($run->hasValidResult())->toBeTrue()
        // A proposal is advice to a supervisor, not a decision.
        ->and($run->result['can_execute'])->toBeFalse()
        ->and($run->result['bills'])->toBe($proposal['bills'])
        ->and(array_column($run->result['bills'], 'decision'))->toContain('propose_human_review')
        ->and(Transaction::query()->count())->toBe(0)
        ->and($snapshot->fresh()->hasValidSnapshot())->toBeTrue();
});

test('review wakes an already waiting collection run without a sweep or a prompt', function (): void {
    Bus::fake();
    $c = wakeContext();

    $run = FinanceWorkflowRun::query()->where('kind', 'collection_review')->firstOrFail();
    app(FinanceWorkflowDispatcher::class)->dispatchRun($run->id);
    (new ProcessFinanceWorkflow($run->id))->handle(app(FinanceWorkflows::class), app(FinanceReviewAlerts::class));
    expect($run->fresh()->state)->toBe('waiting_for_review');
    $originalResult = $run->fresh()->result_digest;
    Bus::fake();

    approveWakeReceipt($c);

    // The run existed only to wait for this review, so the approval resolves it
    // rather than leaving the ledger stale until the next sweep.
    Bus::assertDispatched(ProcessFinanceWorkflow::class, 1);

    (new ProcessFinanceWorkflow($run->id))->handle(app(FinanceWorkflows::class), app(FinanceReviewAlerts::class));

    expect($run->fresh()->state)->toBe('completed')
        ->and($run->fresh()->result['can_execute'])->toBeFalse()
        ->and($run->fresh()->result_digest)->toBe($originalResult);

    approveWakeReceipt($c);
    expect(Bus::dispatched(ProcessFinanceWorkflow::class))->toHaveCount(1)
        ->and(FinanceWorkflowRun::query()->where('kind', 'budget_plan')->count())->toBe(0);
});

test('a held receipt supplies no cash and wakes no proposal', function (): void {
    Bus::fake();
    $c = wakeContext();

    $held = app(ReviewCollectionBatch::class)->handle($c['reviewer'], $c['batch'], $c['batch']->snapshot_digest,
        'hold', 'needs-clarification', 'Payee name is unclear.');

    // Its own run resolves, but nothing planned from it.
    expect(fn () => app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'],
        wakePlanInput($c, $held)))->toThrow(ValidationException::class, 'Intact independently approved receipts');

    expect(FinanceWorkflowRun::query()->where('kind', 'budget_plan')->count())->toBe(0);
});

test('capture replay and scheduler share one proposal identity and dispatch lease', function (): void {
    Bus::fake();
    $c = wakeContext();
    $review = approveWakeReceipt($c);

    $input = wakePlanInput($c, $review);
    $first = app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], $input);

    $run = FinanceWorkflowRun::query()->where('kind', 'budget_plan')->firstOrFail();

    $replay = app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], $input);
    approveWakeReceipt($c);
    app(FinanceWorkflowDispatcher::class)->dispatch();
    app(FinanceWorkflowDispatcher::class)->dispatch();

    expect(FinanceWorkflowRun::query()->where('kind', 'budget_plan')->count())->toBe(1)
        ->and($replay->id)->toBe($first->id)
        ->and(app(FinanceWorkflowDispatcher::class)->dispatchRun($run->id))->toBeFalse()
        ->and(FinanceWorkflowRun::query()->where('kind', 'budget_plan')->count())->toBe(1);
    Bus::assertDispatched(ProcessFinanceWorkflow::class, fn (ProcessFinanceWorkflow $job): bool => $job->runId === $run->id);
    expect(Bus::dispatched(ProcessFinanceWorkflow::class)->filter(fn (ProcessFinanceWorkflow $job): bool => $job->runId === $run->id))->toHaveCount(1);
});

test('the wake does nothing when background finance is disabled', function (): void {
    $c = wakeContext();

    // The receipt's own run already exists from capture; what must not happen
    // is any dispatch, and no planning run for the captured plan.
    Bus::fake();
    config(['eduflow.background_finance.enabled' => false]);

    $review = approveWakeReceipt($c);
    app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], wakePlanInput($c, $review));

    Bus::assertNothingDispatched();
    expect(FinanceWorkflowRun::query()->where('kind', 'budget_plan')->count())->toBe(0);
});

test('rolling back a captured plan discards its wake and durable run', function (): void {
    Bus::fake();
    $c = wakeContext();
    $review = approveWakeReceipt($c);
    $before = Bus::dispatched(ProcessFinanceWorkflow::class)->count();
    $input = wakePlanInput($c, $review);

    DB::beginTransaction();
    try {
        app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], $input);
        expect(Bus::dispatched(ProcessFinanceWorkflow::class))->toHaveCount($before)
            ->and(FinanceWorkflowRun::query()->where('kind', 'budget_plan')->count())->toBe(1);
    } finally {
        DB::rollBack();
    }

    expect(BudgetSnapshot::query()->where('capture_key', $input['capture_key'])->exists())->toBeFalse()
        ->and(FinanceWorkflowRun::query()->where('kind', 'budget_plan')->count())->toBe(0)
        ->and(Bus::dispatched(ProcessFinanceWorkflow::class))->toHaveCount($before);
});

test('direct wake refuses unsafe queues without invalidating a committed capture', function (string $connection, int $retryAfter): void {
    Bus::fake();
    Exceptions::fake();
    $c = wakeContext();
    config(['eduflow.background_finance.queue_connection' => $connection, 'queue.connections.'.$connection.'.retry_after' => $retryAfter]);
    $review = approveWakeReceipt($c);
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], wakePlanInput($c, $review));
    $run = FinanceWorkflowRun::query()->where('kind', 'budget_plan')->firstOrFail();

    expect($snapshot->hasValidSnapshot())->toBeTrue()
        ->and($run->state)->toBe('queued')
        ->and($run->dispatched_at)->toBeNull();
    Bus::assertNothingDispatched();
    Exceptions::assertReported(RuntimeException::class);
    expect(fn () => app(FinanceWorkflowDispatcher::class)->dispatchRun($run->id))->toThrow(ValidationException::class);
})->with(['synchronous' => ['sync', 90], 'short durable retry lease' => ['database', 30]]);

test('enqueue failure retains committed evidence and scheduler recovers after lease', function (): void {
    $c = wakeContext();
    $review = approveWakeReceipt($c);
    $collectionRun = FinanceWorkflowRun::query()->where('kind', 'collection_review')->firstOrFail();
    (new ProcessFinanceWorkflow($collectionRun->id))->handle(app(FinanceWorkflows::class), app(FinanceReviewAlerts::class));
    Exceptions::fake();
    $bus = Mockery::mock(QueueingDispatcher::class);
    mockExpectation($bus, 'dispatch')->once()->andThrow(new RuntimeException('Queue unavailable'));
    Bus::swap($bus);

    $snapshot = app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], wakePlanInput($c, $review));
    $run = FinanceWorkflowRun::query()->where('kind', 'budget_plan')->firstOrFail();
    Exceptions::assertReported(RuntimeException::class);
    expect($snapshot->hasValidSnapshot())->toBeTrue()
        ->and($run->state)->toBe('queued')
        ->and($run->last_error)->toContain('durable work retained');

    Bus::fake();
    expect(app(FinanceWorkflowDispatcher::class)->dispatch())->toBe(0);
    $this->travel(121)->seconds();
    expect(app(FinanceWorkflowDispatcher::class)->dispatch())->toBe(1)
        ->and(app(FinanceWorkflowDispatcher::class)->dispatch())->toBe(0);
    Bus::assertDispatched(ProcessFinanceWorkflow::class, fn (ProcessFinanceWorkflow $job): bool => $job->runId === $run->id);
});

test('sweeper recovers schema v3 evidence captured while background finance was off', function (): void {
    Bus::fake();
    $c = wakeContext();
    config(['eduflow.background_finance.enabled' => false]);
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], wakePlanInput($c, approveWakeReceipt($c)));
    expect($snapshot->snapshot['schema_version'])->toBe(3)
        ->and($snapshot->workflowRuns()->count())->toBe(0);

    config(['eduflow.background_finance.enabled' => true]);
    app(FinanceWorkflowDispatcher::class)->dispatch();
    $run = FinanceWorkflowRun::query()->where('trigger_key', 'budget:'.$snapshot->id)->firstOrFail();
    Bus::assertDispatched(ProcessFinanceWorkflow::class, fn (ProcessFinanceWorkflow $job): bool => $job->runId === $run->id);
    expect($run->hasValidSource())->toBeTrue();
});

test('legacy v2 staff-attested snapshots remain bound and replay without rewriting', function (): void {
    $c = wakeContext();
    $review = approveWakeReceipt($c);
    $input = wakePlanInput($c, $review);
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], $input);
    $content = $snapshot->snapshot;
    $content['schema_version'] = 2;
    unset($content['movement_receipts_minor_units']);
    $content['collections'] = array_map(static function (array $entry): array {
        unset($entry['movement_receipt_minor_units']);

        return $entry;
    }, $content['collections']);
    $digest = PaymentIntent::digest($content);
    DB::table((new BudgetSnapshot)->getTable())->where('id', $snapshot->id)->update([
        'snapshot' => json_encode($content, JSON_THROW_ON_ERROR), 'snapshot_digest' => $digest,
    ]);
    $snapshot->refresh();

    app(ReviewedCollections::class)->requireBound($snapshot);
    $replay = app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], $input);
    expect($replay->id)->toBe($snapshot->id)
        ->and($replay->snapshot['schema_version'])->toBe(2)
        ->and($replay->snapshot_digest)->toBe($digest);
});

// ---------------------------------------------------------------------------
// Cash counted once: plans counting movements must exclude typed opening funds.
// ---------------------------------------------------------------------------
/**
 * A settled student payment, ready to reconcile.
 */
function reconcileContextPayment(int $amountBaseUnits = 5_000000, int $overpaymentBaseUnits = 0, bool $withVerifiedAttempt = true): StudentTuitionPayment
{
    $student = Student::factory()->withoutPayoutAddress()->create();
    $wallet = StudentWallet::factory()->create(['student_id' => $student->id,
        'address' => '0x'.str_pad(dechex(crc32((string) Str::uuid())), 40, '0', STR_PAD_LEFT)]);
    $account = TuitionAccount::factory()->create(['student_id' => $student->id,
        'academic_term_id' => AcademicTerm::factory()->create()->id, 'total_amount' => 300_000000, 'paid_amount' => 0]);

    $payment = StudentTuitionPayment::query()->create([
        'request_key' => (string) Str::uuid(), 'student_id' => $student->id, 'tuition_account_id' => $account->id,
        'student_wallet_id' => $wallet->id, 'tx_hash' => '0x'.str_repeat('a', 64), 'amount_base_units' => $amountBaseUnits,
        'applied_base_units' => $amountBaseUnits - $overpaymentBaseUnits, 'overpayment_base_units' => $overpaymentBaseUnits, 'network_fee' => '0.02945113436514256',
        'payer_address' => $wallet->address, 'recipient_address' => WAKE_TREASURY, 'chain' => 'ARC-TESTNET',
        'tx_verified_on_chain' => true, 'simulated' => false, 'executed_at' => now()->subMinute(),
    ]);

    if ($withVerifiedAttempt) {
        StudentTuitionPaymentAttempt::query()->create([
            'request_key' => $payment->request_key, 'student_id' => $student->id, 'tuition_account_id' => $account->id,
            'student_wallet_id' => $wallet->id, 'amount_base_units' => $amountBaseUnits,
            'quoted_network_fee' => $payment->network_fee, 'fee_ceiling_base_units' => 100000,
            'available_base_units' => $amountBaseUnits, 'total_base_units' => $amountBaseUnits,
            'payer_address' => $payment->payer_address, 'recipient_address' => WAKE_TREASURY,
            'chain' => 'ARC-TESTNET', 'chain_id' => 5042002, 'state' => 'verified',
            'provider_reference' => $payment->tx_hash, 'settlement_verdict' => 'verified',
            'settlement_verified_at' => now()->subMinute(),
        ]);
    }

    return $payment;
}

/** @param array<string, mixed> $c */
function reconcileMovement(array $c, StudentTuitionPayment $payment): CollectionBatch
{
    return app(ImportTuitionPaymentAsCollection::class)->handle($c['actor'], $payment, (string) Str::uuid());
}

test('a plan counting chain movements may not also carry a typed opening balance', function (): void {
    $c = wakeContext();
    $batch = reconcileMovement($c, reconcileContextPayment());
    $review = app(ReviewCollectionBatch::class)->handle($c['reviewer'], $batch, $batch->snapshot_digest,
        'approve_receipts', 'independent-accounting-check', 'Movement, payer and restrictions checked.');
    $bill = usdcBill($c);

    // The money behind this receipt is already in the treasury balance. A figure
    // typed into opening funds could contain it, and one number cannot be
    // decomposed to prove otherwise.
    expect(fn () => app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], wakePlanInput($c, $review, [
        'currency' => 'USDC', 'bill_ids' => [$bill->id], 'opening_funds' => '5',
        'realized_receipts' => '5', 'restricted_cash' => '0', 'collection_review_ids' => [$review->id],
    ])))->toThrow(ValidationException::class, 'could count it a second time');

    expect(BudgetSnapshot::query()->where('currency', 'USDC')->count())->toBe(0);
});

test('a plan counting chain movements may start from receipts with no opening balance', function (): void {
    $c = wakeContext();
    $batch = reconcileMovement($c, reconcileContextPayment());
    $review = app(ReviewCollectionBatch::class)->handle($c['reviewer'], $batch, $batch->snapshot_digest,
        'approve_receipts', 'independent-accounting-check', 'Movement, payer and restrictions checked.');
    $bill = usdcBill($c);

    $snapshot = app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], wakePlanInput($c, $review, [
        'currency' => 'USDC', 'bill_ids' => [$bill->id], 'opening_funds' => '0',
        'realized_receipts' => '5', 'restricted_cash' => '0', 'collection_review_ids' => [$review->id],
    ]));

    expect($snapshot->hasValidSnapshot())->toBeTrue()
        ->and($snapshot->currency)->toBe('USDC')
        ->and($snapshot->snapshot['movement_receipts_minor_units'])->toBe('5000000');
});

test('reviewed movement plan excludes overpayment and never adds observed balance to receipts', function (): void {
    Bus::fake();
    $c = wakeContext();
    $batch = reconcileMovement($c, reconcileContextPayment(40_000000, 10_000000));
    $review = app(ReviewCollectionBatch::class)->handle($c['reviewer'], $batch, $batch->snapshot_digest,
        'approve_receipts', 'independent-accounting-check', 'Pilot receipt and student liability checked.');
    $bill = usdcBill($c);
    $input = wakePlanInput($c, $review, [
        'currency' => 'USDC', 'bill_ids' => [$bill->id], 'opening_funds' => '0',
        'realized_receipts' => '40', 'restricted_cash' => '10', 'protected_reserve' => '0',
    ]);
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], $input);
    $plan = app(DepartmentBudgetPlanner::class)->forBackground($snapshot);

    expect($snapshot->headroom()['cash_minor_units'])->toBe('30000000')
        ->and($plan['collection_evidence']['mode'])->toBe('reviewed_batches')
        ->and($plan['collection_evidence']['review_ids'])->toBe([$review->id])
        ->and($plan['bills'][0]['decision'])->toBe('propose_human_review')
        ->and($plan['remaining_cash_minor_units'])->toBe('5000000')
        ->and($plan['can_execute'])->toBeFalse()
        ->and(Transaction::query()->count())->toBe(0);

    expect(fn () => app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], [
        ...$input, 'capture_key' => (string) Str::uuid(), 'restricted_cash' => '0',
    ]))->toThrow(ValidationException::class, 'restricted cash must include');
});

test('schema v3 funding capacity takes minimum of reviewed cash and observed balance', function (): void {
    $c = wakeContext();
    config(['lepton.arc.chain_id' => 5042002]);
    $batch = reconcileMovement($c, reconcileContextPayment(40_000000, 10_000000));
    $review = app(ReviewCollectionBatch::class)->handle($c['reviewer'], $batch, $batch->snapshot_digest,
        'approve_receipts', 'independent-accounting-check', 'Pilot source and student liability checked.');
    $bill = usdcBill($c);
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], wakePlanInput($c, $review, [
        'currency' => 'USDC', 'bill_ids' => [$bill->id], 'opening_funds' => '0',
        'realized_receipts' => '40', 'restricted_cash' => '10', 'protected_reserve' => '0',
    ]));
    $policy = app(CreateFinancePolicyVersion::class)->handle($c['actor'], 'movement-v1', new Money(0, CurrencyCode::USDC),
        new Money(0, CurrencyCode::USDC), new Money(0, CurrencyCode::USDC), new Money(1_000000, CurrencyCode::USDC));
    app(ActivateFinancePolicy::class)->handle($c['reviewer'], $policy, null);
    $wallet = Wallet::query()->where('address', WAKE_TREASURY)->firstOrFail();
    $wallet->update(['provider' => 'circle', 'network' => 'arc-testnet']);
    $balance = ['chain' => 'ARC-TESTNET', 'chain_id' => 5042002, 'address' => WAKE_TREASURY,
        'usdc_base_units' => '40000000', 'observed_at' => now()->toIso8601String(), 'is_fake' => true];
    $window = app(FundingWindowContext::class)->build($c['actor'], $snapshot, $wallet, $balance,
        (string) Str::uuid(), now()->addMinutes(10)->toIso8601String());

    expect($window['capacity']['cash_base_units'])->toBe('30000000')
        ->and($window['capacity']['protected_base_units'])->toBe('10000000');
    $balance['usdc_base_units'] = '20000000';
    $shortWindow = app(FundingWindowContext::class)->build($c['actor'], $snapshot, $wallet, $balance,
        (string) Str::uuid(), now()->addMinutes(10)->toIso8601String());
    expect($shortWindow['capacity']['cash_base_units'])->toBe('10000000')
        ->and(Transaction::query()->count())->toBe(0);
});

test('movement plans sum more than PHP_INT_MAX without losing precision', function (): void {
    $c = wakeContext();
    $batch = reconcileMovement($c, reconcileContextPayment(PHP_INT_MAX));
    $review = app(ReviewCollectionBatch::class)->handle($c['reviewer'], $batch, $batch->snapshot_digest,
        'approve_receipts', 'independent-accounting-check', 'Exact synthetic boundary fixture checked.');
    $secondPayment = reconcileContextPayment(1);
    $secondPayment->update(['tx_hash' => '0x'.str_repeat('b', 64)]);
    StudentTuitionPaymentAttempt::query()->where('request_key', $secondPayment->request_key)
        ->update(['provider_reference' => $secondPayment->tx_hash]);
    $secondBatch = reconcileMovement($c, $secondPayment);
    $secondReview = app(ReviewCollectionBatch::class)->handle($c['reviewer'], $secondBatch, $secondBatch->snapshot_digest,
        'approve_receipts', 'independent-accounting-check', 'Second exact synthetic boundary fixture checked.');
    $bill = usdcBill($c);

    expect(fn () => app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], wakePlanInput($c, $review, [
        'currency' => 'USDC', 'bill_ids' => [$bill->id], 'opening_funds' => '0',
        'realized_receipts' => '9223372036854.775807', 'restricted_cash' => '0',
        'collection_review_ids' => [$review->id, $secondReview->id],
    ])))->toThrow(ValidationException::class, 'Realized receipts must equal');
});

test('changing a movement entry and total together still fails source rebinding', function (): void {
    $c = wakeContext();
    $batch = reconcileMovement($c, reconcileContextPayment());
    $review = app(ReviewCollectionBatch::class)->handle($c['reviewer'], $batch, $batch->snapshot_digest,
        'approve_receipts', 'independent-accounting-check', 'Checked.');
    $bill = usdcBill($c);
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], wakePlanInput($c, $review, [
        'currency' => 'USDC', 'bill_ids' => [$bill->id], 'opening_funds' => '0',
        'realized_receipts' => '5', 'restricted_cash' => '0',
    ]));
    $content = $snapshot->snapshot;
    $content['collections'][0]['movement_receipt_minor_units'] = 0;
    $content['movement_receipts_minor_units'] = '0';
    $content['amounts']['opening_funds'] = '5000000';
    DB::table((new BudgetSnapshot)->getTable())->where('id', $snapshot->id)->update([
        'snapshot' => json_encode($content, JSON_THROW_ON_ERROR), 'snapshot_digest' => PaymentIntent::digest($content),
    ]);

    expect(fn () => app(DepartmentBudgetPlanner::class)->forBackground($snapshot->refresh()))
        ->toThrow(ValidationException::class, 'Bound collection or independent review changed');
});

test('allocation changes invalidate the imported receipt rather than widening cash', function (): void {
    $c = wakeContext();
    $payment = reconcileContextPayment(40_000000, 10_000000);
    $batch = reconcileMovement($c, $payment);
    $review = app(ReviewCollectionBatch::class)->handle($c['reviewer'], $batch, $batch->snapshot_digest,
        'approve_receipts', 'independent-accounting-check', 'Pilot receipt and liability checked.');
    $bill = usdcBill($c);
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], wakePlanInput($c, $review, [
        'currency' => 'USDC', 'bill_ids' => [$bill->id], 'opening_funds' => '0',
        'realized_receipts' => '40', 'restricted_cash' => '10', 'protected_reserve' => '0',
    ]));

    $payment->update(['applied_base_units' => 40_000000, 'overpayment_base_units' => 0]);
    expect($batch->fresh()->hasValidSnapshot())->toBeFalse()
        ->and(fn () => app(DepartmentBudgetPlanner::class)->forBackground($snapshot))
        ->toThrow(ValidationException::class, 'Intact independently approved receipts');
});

test('legacy v2 movement plans require fresh exclusion evidence without rewriting history', function (): void {
    $c = wakeContext();
    $batch = reconcileMovement($c, reconcileContextPayment());
    $review = app(ReviewCollectionBatch::class)->handle($c['reviewer'], $batch, $batch->snapshot_digest,
        'approve_receipts', 'independent-accounting-check', 'Checked.');
    $bill = usdcBill($c);
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], wakePlanInput($c, $review, [
        'currency' => 'USDC', 'bill_ids' => [$bill->id], 'opening_funds' => '0',
        'realized_receipts' => '5', 'restricted_cash' => '0',
    ]));
    $content = $snapshot->snapshot;
    $content['schema_version'] = 2;
    unset($content['movement_receipts_minor_units']);
    unset($content['collections'][0]['movement_receipt_minor_units']);
    $digest = PaymentIntent::digest($content);
    DB::table((new BudgetSnapshot)->getTable())->where('id', $snapshot->id)->update([
        'snapshot' => json_encode($content, JSON_THROW_ON_ERROR), 'snapshot_digest' => $digest,
    ]);

    expect(fn () => app(DepartmentBudgetPlanner::class)->forBackground($snapshot->refresh()))
        ->toThrow(ValidationException::class, 'Movement receipts require a fresh schema v3 plan');
    expect($snapshot->fresh()->snapshot_digest)->toBe($digest);
});

test('a historical verified flag without matching attempt cannot supply planning cash', function (string $defect): void {
    $c = wakeContext();
    $payment = reconcileContextPayment(withVerifiedAttempt: $defect !== 'missing_attempt');
    if ($defect === 'wrong_chain') {
        StudentTuitionPaymentAttempt::query()->where('request_key', $payment->request_key)->update(['chain_id' => 5042]);
    } elseif ($defect === 'different_amount') {
        StudentTuitionPaymentAttempt::query()->where('request_key', $payment->request_key)->update(['amount_base_units' => 1]);
    } elseif ($defect === 'unknown_state') {
        StudentTuitionPaymentAttempt::query()->where('request_key', $payment->request_key)->update(['state' => 'unknown']);
    }
    $batch = reconcileMovement($c, $payment);
    $review = app(ReviewCollectionBatch::class)->handle($c['reviewer'], $batch, $batch->snapshot_digest,
        'approve_receipts', 'independent-accounting-check', 'Historical record inspected, not promoted by its label.');
    $bill = usdcBill($c);

    expect(fn () => app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], wakePlanInput($c, $review, [
        'currency' => 'USDC', 'bill_ids' => [$bill->id], 'opening_funds' => '0',
        'realized_receipts' => '5', 'restricted_cash' => '0',
    ])))->toThrow(ValidationException::class, 'legacy verification flag alone');
    expect(BudgetSnapshot::query()->count())->toBe(0);
})->with(['missing_attempt', 'wrong_chain', 'different_amount', 'unknown_state']);

test('the movement exclusion is enforced on read, not only on write', function (): void {
    $c = wakeContext();
    $batch = reconcileMovement($c, reconcileContextPayment());
    $review = app(ReviewCollectionBatch::class)->handle($c['reviewer'], $batch, $batch->snapshot_digest,
        'approve_receipts', 'independent-accounting-check', 'Checked.');
    $bill = usdcBill($c);

    $snapshot = app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], wakePlanInput($c, $review, [
        'currency' => 'USDC', 'bill_ids' => [$bill->id], 'opening_funds' => '0',
        'realized_receipts' => '5', 'restricted_cash' => '0', 'collection_review_ids' => [$review->id],
    ]));

    expect($snapshot->hasValidSnapshot())->toBeTrue();

    // A snapshot whose stored amounts contradict the exclusion must be refused
    // by validation even though nothing went through the capture action.
    $probe = $snapshot->replicate();
    $content = $probe->snapshot;
    $content['amounts']['opening_funds'] = '50000';
    $probe->setRawAttributes(array_merge($snapshot->getAttributes(), [
        'snapshot' => json_encode($content),
        'snapshot_digest' => PaymentIntent::digest($content),
    ]));

    expect($probe->hasValidSnapshot())->toBeFalse();
});

test('a movement total no bound receipt supports is not valid evidence', function (): void {
    $c = wakeContext();
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'],
        wakePlanInput($c, approveWakeReceipt($c)));

    expect($snapshot->hasValidSnapshot())->toBeTrue();

    // The recorded total is what the exclusion rule trusts, so it must equal
    // the sum of the entries rather than being an independently typed figure.
    $probe = $snapshot->replicate();
    $content = $probe->snapshot;
    $content['movement_receipts_minor_units'] = '100000';
    $probe->setRawAttributes(array_merge($snapshot->getAttributes(), [
        'snapshot' => json_encode($content),
        'snapshot_digest' => PaymentIntent::digest($content),
    ]));

    expect($probe->hasValidSnapshot())->toBeFalse();
});

test('a plan with no movement receipts keeps its own opening balance', function (): void {
    $c = wakeContext();

    // Existing staff-attested opening funds retain their contract. This test
    // does not claim that arbitrary bank statements have been reconciled.
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($c['actor'], $c['budget'], wakePlanInput($c,
        approveWakeReceipt($c), ['opening_funds' => '50', 'realized_receipts' => '0', 'restricted_cash' => '0',
            'collection_review_ids' => []]));

    // PHP has two decimals, so 50 is 5,000 minor units.
    expect($snapshot->hasValidSnapshot())->toBeTrue()
        ->and($snapshot->snapshot['amounts']['opening_funds'])->toBe('5000')
        ->and($snapshot->snapshot['movement_receipts_minor_units'])->toBe('0');
});
