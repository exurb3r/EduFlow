<?php

declare(strict_types=1);

use App\Actions\ApproveFundingWindow;
use App\Actions\ApproveReceiptBudgetRoute;
use App\Actions\CaptureBudgetSnapshot;
use App\Actions\ImportTuitionPaymentAsCollection;
use App\Actions\PrepareFundingWindow;
use App\Actions\PrepareReceiptBudgetRoute;
use App\Actions\ReserveVendorPayment;
use App\Actions\ReviewCollectionBatch;
use App\Filament\Pages\FinanceSupervisor;
use App\Models\BudgetSnapshot;
use App\Models\CollectionBatch;
use App\Models\CollectionBatchReview;
use App\Models\FinancePlanReview;
use App\Models\FinanceWorkflowRun;
use App\Models\PaymentAuthorization;
use App\Models\PaymentIntent;
use App\Models\PaymentReservation;
use App\Models\ReceiptPlanTrigger;
use App\Models\StudentBill;
use App\Models\Transaction;
use App\Services\DepartmentBudgetPlanner;
use App\Services\FinanceWorkflowDispatcher;
use App\Services\FinanceWorkflows;
use App\Services\ReceiptTriggeredPlanning;
use App\Services\StudentBillPaymentQuote;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/** @return array<string, mixed> */
function receiptRoutingContext(): array
{
    $c = paymentAuthorizationContext(withFunding: false);
    Bus::fake();
    config(['eduflow.background_finance.enabled' => true, 'eduflow.background_finance.receipt_routing_enabled' => true,
        'queue.connections.database.retry_after' => 360]);
    $original = BudgetSnapshot::query()->firstOrFail();
    $c['base'] = app(CaptureBudgetSnapshot::class)->handle($c['maker'], $c['budget'], [
        'capture_key' => (string) Str::uuid(), 'currency' => 'USDC', 'department' => $original->snapshot['department']['name'],
        'period_start' => $original->snapshot['department']['period_start'], 'period_end' => $original->snapshot['department']['period_end'],
        'as_of' => now()->utc()->toIso8601String(), 'valid_until' => now()->addHour()->utc()->toIso8601String(),
        'bill_ids' => array_column($original->snapshot['bills'], 'id'), 'collection_review_ids' => [],
        'allocation' => '100', 'already_spent' => '0', 'other_budget_commitments' => '0', 'opening_funds' => '0',
        'realized_receipts' => '0', 'actual_outflows' => '0', 'restricted_cash' => '0', 'protected_reserve' => '10', 'other_cash_commitments' => '0',
        'budget_evidence' => 'existing-allocation', 'cash_evidence' => 'receipt-only-context', 'commitment_evidence' => 'reviewed-commitments',
        'commitments_exclude_selected_bills' => true, 'cash_buckets_disjoint' => true, 'opening_funds_exclude_collections' => true,
    ]);
    $c['route'] = app(PrepareReceiptBudgetRoute::class)->handle($c['maker'], $c['base'], CollectionBatch::BILL_PORTAL_STREAM,
        now()->subSecond()->utc()->toIso8601String(), $c['base']->snapshot['valid_until'], (string) Str::uuid());
    $c['payer'] = billPaymentContext(['balance' => 200_000000], $c['institution']);
    config(['lepton.arc.treasury' => $c['wallet']->address]);

    return $c;
}

/** @param array<string, mixed> $c */
function routedReceiptReview(array $c, int $amount = 100_000000, string $marker = 'e', bool $voidBill = false): CollectionBatchReview
{
    $payment = billConsent($c['payer'], $amount);
    $service = app(StudentBillPaymentQuote::class);
    $service->recordSettlement($payment, billSettlementStub($c['payer'], $payment, $amount, marker: $marker));
    if ($voidBill) {
        $c['payer']['bill']->update(['status' => StudentBill::STATUS_VOID]);
    }
    $payment = $service->allocate($payment->fresh());
    $batch = app(ImportTuitionPaymentAsCollection::class)->handle($c['maker'], $payment, (string) Str::uuid());

    return app(ReviewCollectionBatch::class)->handle($c['reviewer'], $batch, $batch->snapshot_digest,
        'approve_receipts', 'synthetic-independent-proof-check', 'Verified receipt and liability checked.');
}

test('independently approved receipt route creates one bounded non-executable successor without staff impersonation', function (): void {
    $c = receiptRoutingContext();
    $approval = app(ApproveReceiptBudgetRoute::class)->handle($c['reviewer'], $c['route'], $c['route']->snapshot_digest, 'Closed context and receipt source reviewed.');
    $review = routedReceiptReview($c);
    expect(ReceiptPlanTrigger::query()->count())->toBe(1);
    $service = app(ReceiptTriggeredPlanning::class);
    $successor = $service->process($review);
    expect($service->process($review)->id)->toBe($successor->id)
        ->and($successor->snapshot['bills'])->toBe($c['base']->snapshot['bills'])
        ->and($successor->snapshot['amounts']['allocation'])->toBe('100000000')
        ->and($successor->snapshot['amounts']['realized_receipts'])->toBe('100000000')
        ->and($successor->snapshot['amounts']['opening_funds'])->toBe('0')
        ->and($successor->snapshot['valid_until'])->toBe($c['route']->valid_until->toIso8601String())
        ->and($successor->snapshot['receipt_routing']['approval_id'])->toBe($approval->id)
        ->and($successor->snapshot['receipt_routing']['service_capability'])->toBe('receipt_successor_planning')
        ->and($c['base']->fresh()->snapshot_digest)->toBe($c['base']->snapshot_digest);
    $collectionRun = FinanceWorkflowRun::query()->where('collection_batch_id', $review->collection_batch_id)->sole();
    app(FinanceWorkflows::class)->process($collectionRun->id);
    $planRun = FinanceWorkflowRun::query()->where('budget_snapshot_id', $successor->id)->sole();
    app(FinanceWorkflows::class)->process($planRun->id);
    expect($planRun->fresh()->state)->toBe('waiting_for_review')->and($planRun->fresh()->result['can_execute'])->toBeFalse()
        ->and(PaymentAuthorization::query()->count())->toBe(0)->and(PaymentReservation::query()->count())->toBe(0)
        ->and(Transaction::query()->count())->toBe(0);
});

test('route approval refuses self review overlapping sources stale digests and expired context', function (string $case): void {
    $c = receiptRoutingContext();
    if ($case === 'overlap') {
        app(ApproveReceiptBudgetRoute::class)->handle($c['reviewer'], $c['route'], $c['route']->snapshot_digest, 'First context reviewed.');
        $c['route'] = app(PrepareReceiptBudgetRoute::class)->handle($c['maker'], $c['base'], CollectionBatch::BILL_PORTAL_STREAM,
            now()->subSecond()->utc()->toIso8601String(), $c['base']->snapshot['valid_until'], (string) Str::uuid());
    }
    if ($case === 'expired') {
        $this->travel(61)->minutes();
    }
    expect(fn () => app(ApproveReceiptBudgetRoute::class)->handle($case === 'self' ? $c['maker'] : $c['reviewer'], $c['route'],
        $case === 'digest' ? str_repeat('0', 64) : $c['route']->snapshot_digest, 'Source routing reviewed.'))
        ->toThrow($case === 'self' ? AuthorizationException::class : ValidationException::class);
})->with(['self', 'overlap', 'digest', 'expired']);

test('routing disabled or unapproved leaves reviewed receipts unplanned', function (string $case): void {
    $c = receiptRoutingContext();
    if ($case === 'disabled') {
        app(ApproveReceiptBudgetRoute::class)->handle($c['reviewer'], $c['route'], $c['route']->snapshot_digest, 'Source reviewed.');
        config(['eduflow.background_finance.receipt_routing_enabled' => false]);
    }
    $review = routedReceiptReview($c);
    expect(app(ReceiptTriggeredPlanning::class)->process($review))->toBeNull()
        ->and(ReceiptPlanTrigger::query()->count())->toBe(0);
})->with(['disabled', 'unapproved']);

test('successor blocks changed budget stale proof and expired route without rewriting base context', function (string $case): void {
    $c = receiptRoutingContext();
    app(ApproveReceiptBudgetRoute::class)->handle($c['reviewer'], $c['route'], $c['route']->snapshot_digest, 'Source reviewed.');
    $review = routedReceiptReview($c);
    if ($case === 'budget') {
        $c['budget']->update(['allocated_amount' => '200']);
    } elseif ($case === 'expired') {
        $this->travel(61)->minutes();
    } else {
        DB::table('student_bill_payments')->update(['settlement_evidence_digest' => str_repeat('0', 64)]);
    }
    expect(app(ReceiptTriggeredPlanning::class)->process($review))->toBeNull()
        ->and(ReceiptPlanTrigger::query()->sole()->state)->toBe('blocked')
        ->and(BudgetSnapshot::query()->count())->toBe(2);
})->with(['budget', 'expired', 'proof']);

test('scheduler recovers receipt reviewed while routing was off without reopening completed plan results', function (): void {
    $c = receiptRoutingContext();
    app(ApproveReceiptBudgetRoute::class)->handle($c['reviewer'], $c['route'], $c['route']->snapshot_digest, 'Source reviewed.');
    config(['eduflow.background_finance.receipt_routing_enabled' => false]);
    $review = routedReceiptReview($c);
    $run = FinanceWorkflowRun::query()->where('collection_batch_id', $review->collection_batch_id)->sole();
    app(FinanceWorkflows::class)->process($run->id);
    $digest = $run->fresh()->result_digest;
    expect($run->fresh()->state)->toBe('completed')->and(ReceiptPlanTrigger::query()->count())->toBe(0);
    config(['eduflow.background_finance.receipt_routing_enabled' => true]);
    app(FinanceWorkflowDispatcher::class)->dispatch();
    expect($run->fresh()->state)->toBe('queued');
    app(FinanceWorkflows::class)->process($run->id);
    app(FinanceWorkflows::class)->process($run->id);

    expect(ReceiptPlanTrigger::query()->sole()->state)->toBe('completed')
        ->and(BudgetSnapshot::query()->count())->toBe(3)->and($run->fresh()->result_digest)->toBe($digest);
});

test('finance can independently approve exact route and inspect successor trace without executing payments', function (): void {
    $c = receiptRoutingContext();
    Filament::setCurrentPanel('finance');
    $this->actingAs($c['reviewer']);
    $page = Livewire::test(FinanceSupervisor::class);
    $page->__call('callAction', ['approveReceiptRoute', ['route_digest' => $c['route']->snapshot_digest,
        'reason' => 'Source and immutable planning context checked.', 'attestation' => true]]);
    $page->__call('assertHasNoActionErrors', []);
    expect($c['route']->fresh()->approval->approved_by)->toBe($c['reviewer']->id);
    $review = routedReceiptReview($c);
    app(ReceiptTriggeredPlanning::class)->process($review);
    Livewire::test(FinanceSupervisor::class)->assertSee('Receipt routing and successors')->assertSee('Approved for planning only');
    expect(PaymentAuthorization::query()->count())->toBe(0);
});

test('successors accumulate each reviewed receipt once even when queue delivery arrives out of order', function (): void {
    $c = receiptRoutingContext();
    app(ApproveReceiptBudgetRoute::class)->handle($c['reviewer'], $c['route'], $c['route']->snapshot_digest, 'Source reviewed.');
    $first = routedReceiptReview($c, 30_000000, 'a');
    $second = routedReceiptReview($c, 40_000000, 'b');
    $service = app(ReceiptTriggeredPlanning::class);
    $one = $service->process($second);
    $two = $service->process($first);

    expect($one->snapshot['amounts']['realized_receipts'])->toBe('40000000')
        ->and($two->snapshot['amounts']['realized_receipts'])->toBe('70000000')
        ->and($two->snapshot['receipt_routing']['predecessor_snapshot_id'])->toBe($one->id)
        ->and($two->hasValidSnapshot())->toBeTrue()
        ->and($service->process($second)->id)->toBe($one->id)->and($service->process($first)->id)->toBe($two->id)
        ->and($two->snapshot['amounts']['protected_reserve'])->toBe($c['base']->snapshot['amounts']['protected_reserve'])
        ->and($two->snapshot['collections'])->toHaveCount(2)->and(ReceiptPlanTrigger::query()->count())->toBe(2);
});

test('receipt-triggered plan keeps overpayments restricted rather than proposing spendable cash', function (): void {
    $c = receiptRoutingContext();
    app(ApproveReceiptBudgetRoute::class)->handle($c['reviewer'], $c['route'], $c['route']->snapshot_digest, 'Source reviewed.');
    $review = routedReceiptReview($c, voidBill: true);
    $snapshot = app(ReceiptTriggeredPlanning::class)->process($review);

    expect($snapshot->snapshot['amounts']['restricted_cash'])->toBe('100000000')
        ->and($snapshot->headroom()['cash_minor_units'])->toBe('-10000000')
        ->and(app(DepartmentBudgetPlanner::class)->forBackground($snapshot)['bills'][0]['decision'])->toBe('hold');
});

test('raw successor tampering cannot widen allocation or drop receipts even with a fresh digest', function (): void {
    $c = receiptRoutingContext();
    app(ApproveReceiptBudgetRoute::class)->handle($c['reviewer'], $c['route'], $c['route']->snapshot_digest, 'Source reviewed.');
    $snapshot = app(ReceiptTriggeredPlanning::class)->process(routedReceiptReview($c));
    $probe = $snapshot->fresh();
    $content = $probe->snapshot;
    $content['amounts']['allocation'] = '200000000';
    $probe->snapshot = $content;
    $probe->snapshot_digest = PaymentIntent::digest($content);

    expect($probe->hasValidSnapshot())->toBeFalse()->and($snapshot->fresh()->hasValidSnapshot())->toBeTrue();
});

test('existing payment reservations block automatic successor instead of reusing stale commitments', function (): void {
    $c = receiptRoutingContext();
    app(ApproveReceiptBudgetRoute::class)->handle($c['reviewer'], $c['route'], $c['route']->snapshot_digest, 'Source reviewed.');
    $review = routedReceiptReview($c);
    stubPaymentAuthorizationBalance('100000000000000000007');
    $snapshot = BudgetSnapshot::query()->firstOrFail();
    $window = app(PrepareFundingWindow::class)->handle($c['maker'], $snapshot, $c['wallet'], (string) Str::uuid(), now()->addMinutes(10)->toIso8601String());
    $approval = app(ApproveFundingWindow::class)->handle($c['reviewer'], $window, $window->snapshot_digest, 'Synthetic funds checked.');
    app(ReserveVendorPayment::class)->handle($c['maker'], $c['drafts'][0], $approval, (string) Str::uuid(), $c['drafts'][0]->snapshot_digest, $approval->approval_digest);

    expect(app(ReceiptTriggeredPlanning::class)->process($review))->toBeNull()
        ->and(ReceiptPlanTrigger::query()->sole()->state)->toBe('blocked');
});

test('receipt review rollback discards successor trigger and background wake', function (): void {
    $c = receiptRoutingContext();
    app(ApproveReceiptBudgetRoute::class)->handle($c['reviewer'], $c['route'], $c['route']->snapshot_digest, 'Source reviewed.');
    Bus::fake();
    DB::beginTransaction();
    routedReceiptReview($c);
    expect(ReceiptPlanTrigger::query()->count())->toBe(1);
    DB::rollBack();

    expect(ReceiptPlanTrigger::query()->count())->toBe(0)->and(CollectionBatch::query()->count())->toBe(0);
    Bus::assertNothingDispatched();
});

test('route and approval evidence remain immutable and successors cannot auto-approve their plans', function (): void {
    $c = receiptRoutingContext();
    $approval = app(ApproveReceiptBudgetRoute::class)->handle($c['reviewer'], $c['route'], $c['route']->snapshot_digest, 'Source reviewed.');
    expect(fn () => $c['route']->update(['source_stream' => CollectionBatch::TUITION_PORTAL_STREAM]))->toThrow(LogicException::class);
    expect(fn () => $approval->update(['reason' => 'Changed']))->toThrow(LogicException::class);
    $snapshot = app(ReceiptTriggeredPlanning::class)->process(routedReceiptReview($c));
    $trigger = ReceiptPlanTrigger::query()->sole();
    expect(fn () => $trigger->update(['state' => 'queued']))->toThrow(LogicException::class)
        ->and(FinancePlanReview::query()->count())->toBe(0);
});

test('unreviewed held or out-of-window receipt cannot trigger automatic planning', function (string $case): void {
    $c = receiptRoutingContext();
    app(ApproveReceiptBudgetRoute::class)->handle($c['reviewer'], $c['route'], $c['route']->snapshot_digest, 'Source reviewed.');
    $payment = billConsent($c['payer']);
    $service = app(StudentBillPaymentQuote::class);
    $service->recordSettlement($payment, billSettlementStub($c['payer'], $payment));
    $payment = $service->allocate($payment->fresh());
    $batch = app(ImportTuitionPaymentAsCollection::class)->handle($c['maker'], $payment, (string) Str::uuid());
    if ($case === 'unreviewed') {
        $run = FinanceWorkflowRun::query()->where('collection_batch_id', $batch->id)->sole();
        app(FinanceWorkflows::class)->process($run->id);
    } else {
        if ($case === 'outside') {
            $this->travel(61)->minutes();
        }
        app(ReviewCollectionBatch::class)->handle($c['reviewer'], $batch, $batch->snapshot_digest,
            $case === 'held' ? 'hold' : 'approve_receipts', 'Synthetic source checked.', 'Receipt review recorded.');
        $run = FinanceWorkflowRun::query()->where('collection_batch_id', $batch->id)->sole();
        app(FinanceWorkflows::class)->process($run->id);
    }

    expect(BudgetSnapshot::query()->count())->toBe(2)->and(PaymentAuthorization::query()->count())->toBe(0);
    if ($case !== 'outside') {
        expect(ReceiptPlanTrigger::query()->count())->toBe(0);
    } else {
        expect(ReceiptPlanTrigger::query()->sole()->state)->toBe('blocked');
    }
})->with(['unreviewed', 'held', 'outside']);
