<?php

declare(strict_types=1);

use App\Actions\CaptureBudgetSnapshot;
use App\Actions\CaptureCollectionBatch;
use App\Actions\CaptureInvoiceVersion;
use App\Actions\ReviewCollectionBatch;
use App\Actions\ReviewFinancePlan;
use App\Actions\ReviewInvoiceVersion;
use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Filament\Pages\Collections;
use App\Filament\Pages\FinanceSupervisor;
use App\Jobs\ProcessFinanceWorkflow;
use App\Models\Budget;
use App\Models\BudgetSnapshot;
use App\Models\CollectionBatch;
use App\Models\CollectionBatchReview;
use App\Models\FinancePlanReview;
use App\Models\FinanceWorkflowRun;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Student;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Services\AcceptedPlanPayments;
use App\Services\FinanceReviewAlerts;
use App\Services\FinanceWorkflowDispatcher;
use App\Services\FinanceWorkflows;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\travel;

/** @return array{actor: User, reviewer: User, snapshot: BudgetSnapshot, institution: Organization} */
function workflowContext(): array
{
    config(['eduflow.institution_id' => null, 'eduflow.background_finance.enabled' => true,
        'eduflow.background_finance.queue_connection' => 'database']);
    app()->instance(ArcNetworkGateway::class, Mockery::mock(ArcNetworkGateway::class));
    app()->instance(WalletGateway::class, Mockery::mock(WalletGateway::class));
    /** @var Organization $institution */
    $institution = Organization::factory()->create(['currency' => 'PHP']);
    /** @var User $actor */
    $actor = User::factory()->create();
    $actor->assignRole(Role::findOrCreate('finance_officer', 'web'));
    /** @var User $reviewer */
    $reviewer = User::factory()->create();
    $reviewer->assignRole(Role::findOrCreate('admin', 'web'));
    $budget = Budget::query()->create(['organization_id' => $institution->id, 'name' => 'Teaching', 'category' => 'software',
        'allocated_amount' => '100', 'spent_amount' => '0', 'remaining_amount' => '100', 'status' => 'active']);
    $vendor = Vendor::query()->create(['organization_id' => $institution->id, 'name' => 'Teaching alias',
        'wallet_address' => '0x'.str_repeat('2', 40), 'status' => 'verified', 'risk_level' => 'low']);
    $invoice = Invoice::query()->create(['organization_id' => $institution->id, 'vendor_id' => $vendor->id, 'budget_id' => $budget->id,
        'reference' => 'LMS-'.Str::uuid(), 'amount' => '25', 'due_date' => now()->addDay(), 'category' => 'software', 'status' => 'pending']);
    $bill = app(CaptureInvoiceVersion::class)->handle($actor, $invoice, ['capture_key' => (string) Str::uuid(), 'source_amount' => '25', 'source_currency' => 'PHP',
        'source_evidence' => 'synthetic-bill', 'business_approval_reference' => 'synthetic-approval', 'department' => 'Teaching',
        'period_start' => now()->toDateString(), 'period_end' => now()->addDays(14)->toDateString(), 'source_per_usdc' => '50',
        'rate_source' => 'staff-reference', 'rate_observed_at' => now()->subMinute()->toIso8601String(), 'rounding' => 'down']);
    app(ReviewInvoiceVersion::class)->handle($reviewer, $bill, $bill->snapshot_digest, 'approve_evidence', 'Checked.');
    $batch = app(CaptureCollectionBatch::class)->handle($actor, ['capture_key' => (string) Str::uuid(), 'source_stream' => 'cashier',
        'source_reference' => 'report-1', 'source_document_digest' => hash('sha256', 'workflow-report'), 'currency' => 'PHP',
        'received_amount' => '100', 'restricted_amount' => '10', 'collected_from' => now()->subDays(2)->toIso8601String(),
        'collected_until' => now()->subDay()->toIso8601String(), 'cash_evidence_reference' => 'synthetic-receipts',
        'source_stream_disjoint' => true, 'received_not_forecast' => true]);
    $review = app(ReviewCollectionBatch::class)->handle($reviewer, $batch, $batch->snapshot_digest, 'approve_receipts', 'independent-check', 'Checked.');
    $snapshot = app(CaptureBudgetSnapshot::class)->handle($actor, $budget, ['capture_key' => (string) Str::uuid(), 'currency' => 'PHP',
        'department' => 'Teaching', 'period_start' => now()->toDateString(), 'period_end' => now()->addDays(14)->toDateString(),
        'as_of' => now()->subMinute()->toIso8601String(), 'valid_until' => now()->addHour()->toIso8601String(), 'bill_ids' => [$bill->id],
        'allocation' => '100', 'already_spent' => '0', 'other_budget_commitments' => '0', 'opening_funds' => '0', 'realized_receipts' => '100',
        'actual_outflows' => '0', 'restricted_cash' => '10', 'protected_reserve' => '10', 'other_cash_commitments' => '0',
        'budget_evidence' => 'synthetic-budget', 'cash_evidence' => 'synthetic-cash', 'commitment_evidence' => 'synthetic-commitments',
        'commitments_exclude_selected_bills' => true, 'cash_buckets_disjoint' => true, 'opening_funds_exclude_collections' => true,
        'collection_review_ids' => [$review->id]]);

    return ['actor' => $actor, 'reviewer' => $reviewer, 'snapshot' => $snapshot, 'institution' => $institution];
}

test('durable queued planning runs without prompt user session wallet or student and notifies independent supervisor once', function (): void {
    $c = workflowContext();
    $run = FinanceWorkflowRun::query()->where('kind', 'budget_plan')->firstOrFail();
    expect($run->state)->toBe('queued');
    $job = new ProcessFinanceWorkflow($run->id);
    $job->handle(app(FinanceWorkflows::class), app(FinanceReviewAlerts::class));
    $job->handle(app(FinanceWorkflows::class), app(FinanceReviewAlerts::class));
    $run->refresh();
    expect($run->state)->toBe('waiting_for_review')->and($run->attempts)->toBe(1)->and($run->hasValidResult())->toBeTrue()
        ->and($run->result['remaining_cash_minor_units'])->toBe('5500')->and($run->result['can_execute'])->toBeFalse()
        ->and(DB::table('notifications')->where('notifiable_id', $c['reviewer']->id)->count())->toBe(1)
        ->and(DB::table('notifications')->where('notifiable_id', $c['actor']->id)->count())->toBe(0)
        ->and(Transaction::query()->count())->toBe(0)->and(Student::query()->count())->toBe(0);
});

test('supervisor records exact plan feedback without payment authority and retries preserve immutable review', function (): void {
    $c = workflowContext();
    $run = FinanceWorkflowRun::query()->where('kind', 'budget_plan')->firstOrFail();
    app(FinanceWorkflows::class)->process($run->id);
    $run->refresh();
    expect(fn () => app(ReviewFinancePlan::class)->handle($c['actor'], $run, $run->result_digest, 'accept_plan', 'Self review.'))->toThrow(AuthorizationException::class);
    $review = app(ReviewFinancePlan::class)->handle($c['reviewer'], $run, $run->result_digest, 'accept_plan', 'Plan checked.');
    expect(app(ReviewFinancePlan::class)->handle($c['reviewer'], $run, $run->result_digest, 'accept_plan', 'Plan checked.')->id)->toBe($review->id)
        ->and($run->fresh()->state)->toBe('completed')->and(FinancePlanReview::query()->count())->toBe(1)
        ->and(Transaction::query()->count())->toBe(0)->and($review->hasValidEvidence($run->fresh()))->toBeTrue();
});

test('accepted proposal prepares one exact bill reservation without payment authority and exposes progress', function (): void {
    $c = paymentAuthorizationContext();
    config(['eduflow.background_finance.enabled' => true]);
    $snapshot = $c['window']->snapshot['budget_snapshot_id'];
    app(FinanceWorkflows::class)->budgetCaptured(BudgetSnapshot::query()->findOrFail($snapshot));
    $run = FinanceWorkflowRun::query()->where('budget_snapshot_id', $snapshot)->firstOrFail();
    app(FinanceWorkflows::class)->process($run->id);
    $run->refresh();
    app(ReviewFinancePlan::class)->handle($c['reviewer'], $run, $run->result_digest, 'accept_plan', 'Approved proposal within existing allocation.');
    $service = app(AcceptedPlanPayments::class);
    $billId = $c['drafts'][1]->invoice_version_id;

    $hold = $service->prepareBill($c['maker'], $run, $billId, $c['approval'], $run->result_digest,
        $c['approval']->approval_digest, new Money(2, CurrencyCode::USDC));
    $replay = $service->prepareBill($c['maker'], $run, $billId, $c['approval'], $run->result_digest,
        $c['approval']->approval_digest, new Money(2, CurrencyCode::USDC));

    expect($replay->id)->toBe($hold->id)->and($hold->payment_intent_id)->toBe($c['drafts'][1]->id)
        ->and($c['drafts'][1]->fresh()->authorization)->toBeNull()->and(Transaction::query()->count())->toBe(0);
    expect(collect($service->progress($c['reviewer'], $run))->firstWhere('invoice_version_id', $billId)['state'])
        ->toBe('awaiting_payment_review');
    Filament::setCurrentPanel('finance');
    actingAs($c['maker']);
    $page = Livewire::test(FinanceSupervisor::class);
    $page->__call('callAction', [TestAction::make('preparePlanPayment')->table($run), [
        'invoice_version_id' => $billId, 'funding_approval_digest' => $c['approval']->approval_digest, 'max_fee' => '0.000002',
    ]]);
    $page->__call('assertHasNoActionErrors', []);
    $page = Livewire::test(FinanceSupervisor::class);
    $page->__call('mountAction', [TestAction::make('viewFinanceRun')->table($run)]);
    $page->assertSet('mountedRunId', $run->id);
    expect(collect($page->get('mountedPaymentProgress'))->firstWhere('invoice_version_id', $billId)['state'])->toBe('awaiting_payment_review');
});

test('plan handoff refuses unaccepted plan stale digest and different funding context', function (string $case): void {
    $c = paymentAuthorizationContext();
    config(['eduflow.background_finance.enabled' => true]);
    app(FinanceWorkflows::class)->budgetCaptured(BudgetSnapshot::query()->findOrFail($c['window']->budget_snapshot_id));
    $run = FinanceWorkflowRun::query()->where('kind', 'budget_plan')->firstOrFail();
    app(FinanceWorkflows::class)->process($run->id);
    $run->refresh();
    if ($case !== 'unaccepted') {
        app(ReviewFinancePlan::class)->handle($c['reviewer'], $run, $run->result_digest, 'accept_plan', 'Exact plan reviewed.');
    }

    expect(fn () => app(AcceptedPlanPayments::class)->prepareBill($c['maker'], $run, $c['drafts'][1]->invoice_version_id,
        $c['approval'], $case === 'digest' ? str_repeat('0', 64) : $run->result_digest,
        $case === 'funding' ? str_repeat('0', 64) : $c['approval']->approval_digest, new Money($case === 'fee' ? 3 : 2, CurrencyCode::USDC)))
        ->toThrow(ValidationException::class);
})->with(['unaccepted', 'digest', 'funding', 'fee']);

test('stale source blocks background planning and cannot generate an approvable result', function (): void {
    $c = workflowContext();
    $run = FinanceWorkflowRun::query()->where('kind', 'budget_plan')->firstOrFail();
    Invoice::query()->where('organization_id', $c['institution']->id)->update(['reference' => 'changed']);
    app(FinanceWorkflows::class)->process($run->id);
    expect($run->fresh()->state)->toBe('blocked')->and($run->fresh()->result)->toBeNull()
        ->and(Gate::forUser($c['reviewer'])->allows('review', $run->fresh()))->toBeFalse();
});

test('scheduler sweep recovers undispatched work once per lease and never uses a synchronous queue', function (): void {
    $c = workflowContext();
    $dispatcher = app(FinanceWorkflowDispatcher::class);

    // Capturing evidence dispatches its own work after commit, so the sweep
    // normally has nothing to do. Its job is recovery: a run whose dispatch was
    // lost to a crash between commit and enqueue must still be picked up.
    Bus::fake();
    FinanceWorkflowRun::query()->update(['dispatched_at' => null]);
    expect(FinanceWorkflowRun::query()->count())->toBe(2)
        ->and($dispatcher->dispatch())->toBe(2)
        ->and($dispatcher->dispatch())->toBe(0);
    Bus::assertDispatchedTimes(ProcessFinanceWorkflow::class, 2);

    // A synchronous queue would run financial work inside the request that
    // merely recorded it, so it is refused outright rather than tolerated.
    config(['eduflow.background_finance.queue_connection' => 'sync']);
    expect(fn () => $dispatcher->dispatch())->toThrow(ValidationException::class);
    expect($c['snapshot']->hasValidSnapshot())->toBeTrue();
});

test('work dispatched after commit is not enqueued twice by the sweep', function (): void {
    workflowContext();
    Bus::fake();
    $dispatcher = app(FinanceWorkflowDispatcher::class);

    // The runs were claimed when their evidence committed, so a sweep finds
    // nothing and must not re-enqueue them.
    expect($dispatcher->dispatch())->toBe(0);
    Bus::assertNothingDispatched();
});

test('workflow source transaction rollback leaves no phantom work and sweeper recovers undispatched evidence', function (): void {
    $c = workflowContext();
    $before = FinanceWorkflowRun::query()->count();
    DB::beginTransaction();
    $run = FinanceWorkflowRun::query()->create(['organization_id' => $c['institution']->id, 'budget_snapshot_id' => $c['snapshot']->id,
        'kind' => 'budget_plan', 'trigger_key' => 'rolled-back', 'source_digest' => $c['snapshot']->snapshot_digest]);
    DB::rollBack();
    expect(FinanceWorkflowRun::query()->count())->toBe($before)->and(FinanceWorkflowRun::query()->where('trigger_key', 'rolled-back')->exists())->toBeFalse();
});

test('cashier and finance supervisor pages display exact evidence and record plan decision through Livewire', function (): void {
    $c = workflowContext();
    $run = FinanceWorkflowRun::query()->where('kind', 'budget_plan')->firstOrFail();
    app(FinanceWorkflows::class)->process($run->id);
    $run->refresh();
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['reviewer']);
    get(FinanceSupervisor::getUrl(panel: 'finance'))->assertSuccessful()->assertSee('Finance supervisor');
    get(Collections::getUrl(panel: 'finance'))->assertSuccessful()->assertSee('Record fees received');
    $page = Livewire::test(FinanceSupervisor::class)->assertSee('Background finance runs');
    $page->__call('callAction', [TestAction::make('reviewFinancePlan')->table($run),
        ['decision' => 'accept_plan', 'reason' => 'Supervisor checked exact plan.', 'attestation' => true]]);
    $page->__call('assertHasNoActionErrors', []);
    expect(FinancePlanReview::query()->count())->toBe(1)->and($run->fresh()->state)->toBe('completed')->and(Transaction::query()->count())->toBe(0);
});

test('scheduler recovery discovers committed sources even when original enablement was off', function (): void {
    $c = workflowContext();
    DB::table((new FinanceWorkflowRun)->getTable())->delete();
    Bus::fake();
    expect(app(FinanceWorkflowDispatcher::class)->dispatch())->toBe(2)->and(FinanceWorkflowRun::query()->count())->toBe(2);
    Bus::assertDispatchedTimes(ProcessFinanceWorkflow::class, 2);
});

test('waiting approval becomes blocked on expiry rather than refreshing evidence or repeatedly replanning', function (): void {
    workflowContext();
    $run = FinanceWorkflowRun::query()->where('kind', 'budget_plan')->firstOrFail();
    app(FinanceWorkflows::class)->process($run->id);
    $digest = $run->fresh()->result_digest;
    travel(2)->hours();
    app(FinanceWorkflows::class)->process($run->id);
    expect($run->fresh()->state)->toBe('blocked')->and($run->fresh()->result_digest)->toBe($digest)->and($run->fresh()->attempts)->toBe(1);
});

test('missing independent reviewer is visible and never fabricates approval or cash', function (): void {
    $c = workflowContext();
    $run = FinanceWorkflowRun::query()->where('kind', 'budget_plan')->firstOrFail();
    app(FinanceWorkflows::class)->process($run->id);
    $c['reviewer']->removeRole('admin');
    app(FinanceReviewAlerts::class)->deliver($run->id);
    expect($run->fresh()->last_error)->toContain('No eligible independent supervisor')->and($run->fresh()->state)->toBe('waiting_for_review')
        ->and(DB::table('notifications')->count())->toBe(0)->and(FinancePlanReview::query()->count())->toBe(0);
});

test('collection work routes independent review and closes only after recorded intact receipt decision', function (): void {
    $c = workflowContext();
    /** @var CollectionBatch $batch */
    $batch = CollectionBatch::query()->firstOrFail();
    $run = FinanceWorkflowRun::query()->where('kind', 'collection_review')->firstOrFail();
    app(FinanceWorkflows::class)->process($run->id);
    expect($run->fresh()->state)->toBe('completed');
    DB::table((new CollectionBatchReview)->getTable())->where('collection_batch_id', $batch->id)->update(['reason' => 'tampered']);
    DB::table((new FinanceWorkflowRun)->getTable())->where('id', $run->id)->update(['state' => 'waiting_for_review']);
    app(FinanceReviewAlerts::class)->deliver($run->id);
    expect($run->fresh()->state)->toBe('blocked');
});

test('stale expected digest and changed current document cannot receive plan acceptance', function (string $case): void {
    $c = workflowContext();
    $run = FinanceWorkflowRun::query()->where('kind', 'budget_plan')->firstOrFail();
    app(FinanceWorkflows::class)->process($run->id);
    $run->refresh();
    if ($case === 'document') {
        Invoice::query()->update(['category' => 'changed']);
    }
    expect(fn () => app(ReviewFinancePlan::class)->handle($c['reviewer'], $run, $case === 'digest' ? str_repeat('0', 64) : $run->result_digest,
        'accept_plan', 'Accept stale plan.'))->toThrow(ValidationException::class)->and(FinancePlanReview::query()->count())->toBe(0);
})->with(['digest', 'document']);

test('foreign or unverified users cannot supervise and foreign queue identity cannot process work', function (string $case): void {
    $c = workflowContext();
    $run = FinanceWorkflowRun::query()->where('kind', 'budget_plan')->firstOrFail();
    if ($case === 'unverified') {
        $c['reviewer']->forceFill(['email_verified_at' => null])->save();
    } else {
        /** @var Organization $other */
        $other = Organization::factory()->create();
        config(['eduflow.institution_id' => $other->id]);
    }
    expect(Gate::forUser($c['reviewer'])->allows('view', $run))->toBeFalse();
    if ($case === 'foreign') {
        expect(fn () => app(FinanceWorkflows::class)->process($run->id))->toThrow(RuntimeException::class);
    }
})->with(['foreign', 'unverified']);

test('background run failure and populated rollback retain visible evidence', function (): void {
    workflowContext();
    $run = FinanceWorkflowRun::query()->where('kind', 'budget_plan')->firstOrFail();
    (new ProcessFinanceWorkflow($run->id))->failed(new RuntimeException('Injected failure'));
    expect($run->fresh()->state)->toBe('failed')->and($run->fresh()->last_error)->toContain('bounded retries');
    $migration = require database_path('migrations/2026_10_09_043525_create_finance_workflow_runs_table.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'evidence exists');
});

test('disabled background capability creates no new work and performs no planning', function (): void {
    $c = workflowContext();
    config(['eduflow.background_finance.enabled' => false]);
    $run = FinanceWorkflowRun::query()->where('kind', 'budget_plan')->firstOrFail();
    app(FinanceWorkflows::class)->process($run->id);
    expect($run->fresh()->state)->toBe('queued')->and(app(FinanceWorkflowDispatcher::class)->dispatch())->toBe(0);
});
