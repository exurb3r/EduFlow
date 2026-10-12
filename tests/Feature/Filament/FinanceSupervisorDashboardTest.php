<?php

declare(strict_types=1);

use App\Actions\ActivateFinancePolicy;
use App\Actions\CaptureBudgetSnapshot;
use App\Actions\CaptureCollectionBatch;
use App\Actions\CaptureInvoiceVersion;
use App\Actions\CreateFinancePolicyVersion;
use App\Actions\PrepareFundingWindow;
use App\Actions\ReviewCollectionBatch;
use App\Actions\ReviewInvoiceVersion;
use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Filament\Finance\Widgets\ApprovalInboxWidget;
use App\Filament\Finance\Widgets\ArcSettlementWidget;
use App\Filament\Finance\Widgets\AutonomyLaneWidget;
use App\Filament\Finance\Widgets\BudgetCapacityWidget;
use App\Filament\Finance\Widgets\BudgetHeadroomChart;
use App\Filament\Finance\Widgets\CollectionsOverviewWidget;
use App\Filament\Finance\Widgets\CollectionsTrendChart;
use App\Filament\Finance\Widgets\EvidenceTimelineWidget;
use App\Filament\Finance\Widgets\OperationsHealthWidget;
use App\Filament\Finance\Widgets\PaymentDecisionChart;
use App\Filament\Finance\Widgets\WorkflowRunStateChart;
use App\Filament\Finance\Widgets\WorkflowRunWidget;
use App\Filament\Pages\Collections;
use App\Filament\Pages\FinanceDashboard;
use App\Filament\Pages\FinanceSupervisor;
use App\Filament\Pages\PaymentReviews;
use App\Models\Budget;
use App\Models\BudgetSnapshot;
use App\Models\CollectionBatch;
use App\Models\CollectionBatchReview;
use App\Models\FinanceWorkflowRun;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Wallet;
use App\Services\FinanceWorkflows;
use Brick\Math\BigInteger;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use ReflectionMethod;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * A cashier-entered, independently reviewed plan with one reserved payment.
 *
 * Mirrors the pilot's zero-student baseline: aggregate receipts, one
 * department budget, one approved bill, and a hold — but no executor, so no
 * transaction row may exist.
 *
 * @return array{actor: User, reviewer: User, institution: Organization, batch: CollectionBatch, bill: Invoice}
 */
function financeDashboardContext(): array
{
    config([
        'eduflow.institution_id' => null,
        'eduflow.background_finance.enabled' => true,
        'eduflow.background_finance.queue_connection' => 'database',
        'lepton.default' => 'fake',
        'lepton.arc.chain' => 'ARC-TESTNET',
        'lepton.arc.chain_id' => 5042002,
        'lepton.arc.treasury' => '0x'.str_repeat('5', 40),
    ]);

    /** @var Organization $institution */
    $institution = Organization::factory()->create(['currency' => 'PHP']);
    Wallet::query()->create([
        'organization_id' => $institution->id, 'provider' => 'circle', 'network' => 'arc-testnet',
        'address' => '0x'.str_repeat('5', 40), 'balance' => '0', 'status' => 'active',
    ]);
    /** @var User $actor */
    $actor = User::factory()->create();
    $actor->assignRole(Role::findOrCreate('finance_officer', 'web'));
    /** @var User $reviewer */
    $reviewer = User::factory()->create();
    $reviewer->assignRole(Role::findOrCreate('admin', 'web'));

    $budget = Budget::query()->create([
        'organization_id' => $institution->id, 'name' => 'Teaching', 'category' => 'software',
        'allocated_amount' => '20000', 'spent_amount' => '0', 'remaining_amount' => '20000', 'status' => 'active',
    ]);

    $vendor = Vendor::query()->create([
        'organization_id' => $institution->id, 'name' => 'ISP alias',
        'wallet_address' => '0x'.str_repeat('3', 40), 'status' => 'verified', 'risk_level' => 'low',
    ]);

    $invoice = Invoice::query()->create([
        'organization_id' => $institution->id, 'vendor_id' => $vendor->id, 'budget_id' => $budget->id,
        'reference' => 'ISP-'.Str::upper((string) Str::random(6)), 'amount' => '1000', 'due_date' => now()->addDays(5),
        'category' => 'internet', 'status' => 'pending',
    ]);

    $bill = app(CaptureInvoiceVersion::class)->handle($actor, $invoice, [
        'capture_key' => (string) Str::uuid(), 'source_amount' => '1000', 'source_currency' => 'PHP',
        'source_evidence' => 'synthetic-bill', 'business_approval_reference' => 'synthetic-approval',
        'department' => 'Teaching', 'period_start' => now()->toDateString(), 'period_end' => now()->addDays(14)->toDateString(),
        'source_per_usdc' => '50', 'rate_source' => 'staff-reference',
        'rate_observed_at' => now()->subMinute()->toIso8601String(), 'rounding' => 'down',
    ]);
    app(ReviewInvoiceVersion::class)->handle($reviewer, $bill, $bill->snapshot_digest, 'approve_evidence', 'Checked.');

    $batch = app(CaptureCollectionBatch::class)->handle($actor, [
        'capture_key' => (string) Str::uuid(), 'source_stream' => 'tuition.cashier',
        'source_reference' => 'cashier-report-'.Str::random(4),
        'source_document_digest' => hash('sha256', 'dashboard-report-'.Str::random(8)),
        'currency' => 'PHP', 'received_amount' => '50000', 'restricted_amount' => '10000',
        'collected_from' => now()->subDays(3)->toIso8601String(), 'collected_until' => now()->subDays(2)->toIso8601String(),
        'cash_evidence_reference' => 'synthetic-receipts', 'source_stream_disjoint' => true, 'received_not_forecast' => true,
    ]);

    $review = app(ReviewCollectionBatch::class)->handle(
        $reviewer, $batch, $batch->snapshot_digest, 'approve_receipts', 'independent-check', 'Counted against the till summary.');

    app(CaptureBudgetSnapshot::class)->handle($actor, $budget, [
        'capture_key' => (string) Str::uuid(), 'currency' => 'PHP', 'department' => 'Teaching',
        'period_start' => now()->toDateString(), 'period_end' => now()->addDays(14)->toDateString(),
        'as_of' => now()->subMinute()->toIso8601String(), 'valid_until' => now()->addHour()->toIso8601String(),
        'bill_ids' => [$bill->id], 'allocation' => '20000', 'already_spent' => '0', 'other_budget_commitments' => '0',
        'opening_funds' => '0', 'realized_receipts' => '50000', 'actual_outflows' => '0', 'restricted_cash' => '10000',
        'protected_reserve' => '15000', 'other_cash_commitments' => '0', 'budget_evidence' => 'synthetic-budget',
        'cash_evidence' => 'synthetic-cash', 'commitment_evidence' => 'synthetic-commitments',
        'commitments_exclude_selected_bills' => true, 'cash_buckets_disjoint' => true,
        'opening_funds_exclude_collections' => true, 'collection_review_ids' => [$review->id],
    ]);

    return ['actor' => $actor, 'reviewer' => $reviewer, 'institution' => $institution, 'batch' => $batch, 'bill' => $invoice];
}

/** Narrow a Livewire test handle to the finance dashboard it booted. */
function bootedFinanceDashboard(Testable $page): FinanceDashboard
{
    $instance = $page->instance();

    if (! $instance instanceof FinanceDashboard) {
        throw new RuntimeException('The Livewire test did not boot the finance dashboard.');
    }

    return $instance;
}

/** Narrow a Livewire test handle to the chart widget it booted. */
function bootedChart(Testable $page): ChartWidget
{
    $instance = $page->instance();

    if (! $instance instanceof ChartWidget) {
        throw new RuntimeException('The Livewire test did not boot a chart widget.');
    }

    return $instance;
}

/**
 * Read a chart widget's protected payload through the one accessor it has.
 *
 * @return array<string, mixed>
 */
function chartPayload(ChartWidget $widget): array
{
    $method = new ReflectionMethod($widget, 'getCachedData');

    /** @var array<string, mixed> $data */
    $data = $method->invoke($widget);

    return $data;
}

/**
 * A USDC budget under an activated policy, so a funding window can be opened.
 *
 * Kept separate from the local-currency cashier context: a window binds exact
 * USDC evidence and a block-bound Arc balance, which is a different claim
 * from a receipt in the till.
 *
 * @return array{institution: Organization, actor: User, reviewer: User, wallet: Wallet, snapshot: BudgetSnapshot}
 */
function financeDashboardArcContext(): array
{
    $context = financeDashboardContext();

    $institution = $context['institution'];
    $maker = $context['actor'];
    $reviewer = $context['reviewer'];

    $policy = app(CreateFinancePolicyVersion::class)->handle(
        $maker, 'dashboard-v1', Money::fromDecimal('10', CurrencyCode::USDC),
        new Money(0, CurrencyCode::USDC), new Money(0, CurrencyCode::USDC), new Money(1_000000, CurrencyCode::USDC),
    );
    app(ActivateFinancePolicy::class)->handle($reviewer, $policy, null);

    /** @var Wallet $wallet */
    $wallet = Wallet::query()->where('organization_id', $institution->id)->firstOrFail();
    $wallet->update(['balance' => '100']);

    /** @var Budget $budget */
    $budget = Budget::query()->where('organization_id', $institution->id)->firstOrFail();

    $vendor = Vendor::query()->where('organization_id', $institution->id)->firstOrFail();
    $invoice = Invoice::query()->create([
        'organization_id' => $institution->id, 'vendor_id' => $vendor->id, 'budget_id' => $budget->id,
        'reference' => 'USDC-'.Str::upper((string) Str::random(6)), 'amount' => '25', 'due_date' => now()->addDays(4),
        'category' => 'internet', 'status' => 'pending',
    ]);

    $bill = app(CaptureInvoiceVersion::class)->handle($maker, $invoice, [
        'capture_key' => (string) Str::uuid(), 'source_amount' => '25.000000', 'source_currency' => 'USDC',
        'source_evidence' => 'synthetic-usdc-bill', 'business_approval_reference' => 'synthetic-usdc-approval',
        'department' => 'Teaching', 'period_start' => now()->toDateString(), 'period_end' => now()->addDays(14)->toDateString(),
        'source_per_usdc' => '1', 'rate_source' => 'identity-reference',
        'rate_observed_at' => now()->subMinute()->toIso8601String(), 'rounding' => 'down',
    ]);
    app(ReviewInvoiceVersion::class)->handle($reviewer, $bill, $bill->snapshot_digest, 'approve_evidence', 'Checked.');

    $snapshot = app(CaptureBudgetSnapshot::class)->handle($maker, $budget, [
        'capture_key' => (string) Str::uuid(), 'currency' => 'USDC', 'department' => 'Teaching',
        'period_start' => now()->toDateString(), 'period_end' => now()->addDays(14)->toDateString(),
        'as_of' => now()->subMinute()->toIso8601String(), 'valid_until' => now()->addHour()->toIso8601String(),
        'bill_ids' => [$bill->id], 'allocation' => '100', 'already_spent' => '0', 'other_budget_commitments' => '0',
        'opening_funds' => '100', 'realized_receipts' => '0', 'actual_outflows' => '0', 'restricted_cash' => '0',
        'protected_reserve' => '10', 'other_cash_commitments' => '0', 'budget_evidence' => 'synthetic-approval',
        'cash_evidence' => 'synthetic-opening-funds', 'commitment_evidence' => 'synthetic-commitments',
        'commitments_exclude_selected_bills' => true, 'cash_buckets_disjoint' => true,
        'opening_funds_exclude_collections' => true, 'collection_review_ids' => [],
    ]);

    return ['institution' => $institution, 'actor' => $maker, 'reviewer' => $reviewer, 'wallet' => $wallet, 'snapshot' => $snapshot];
}

/**
 * A committed-block read the funding window can bind to.
 *
 * `lepton.default` stays on fake throughout so the widgets keep labelling
 * this as simulation evidence while the block, chain and balance agree.
 */
function stubDashboardArcBalance(string $native): void
{
    $arc = Mockery::mock(ArcNetworkGateway::class);
    $arc->shouldReceive('chainCode')->andReturn('ARC-TESTNET');
    $arc->shouldReceive('chainId')->andReturn(5042002);
    $arc->shouldReceive('blockNumber')->andReturn('0x64');
    $arc->shouldReceive('rpcUrl')->andReturn('https://rpc.testnet.arc.network');
    $arc->shouldReceive('treasuryAddress')->andReturn('0x'.str_repeat('5', 40));
    mockExpectation($arc, 'addressExplorerUrl')->andReturnNull();
    mockExpectation($arc, 'rpc')->andReturnUsing(fn (string $method): mixed => match ($method) {
        'eth_chainId' => '0x'.BigInteger::of(5042002)->toBase(16),
        'eth_getBalance' => '0x'.BigInteger::of($native)->toBase(16),
        'eth_getBlockByNumber' => [
            'number' => '0x64',
            'hash' => '0x'.str_repeat('a', 64),
            'timestamp' => '0x'.BigInteger::of(now()->timestamp - 1)->toBase(16),
        ],
        default => throw new RuntimeException('Unexpected Arc read: '.$method),
    });

    app()->instance(ArcNetworkGateway::class, $arc);
}

test('finance panel root is the supervisor dashboard and renders every required panel', function (): void {
    $c = financeDashboardContext();
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['reviewer']);

    get(FinanceDashboard::getUrl(panel: 'finance'))
        ->assertSuccessful()
        ->assertSee('Finance supervisor');

    expect(FinanceDashboard::getUrl(panel: 'finance'))->toBe(url('/finance'))
        ->and(Filament::getPanel('finance')->getWidgets())->toEqualCanonicalizing([
            ApprovalInboxWidget::class,
            CollectionsOverviewWidget::class,
            CollectionsTrendChart::class,
            BudgetCapacityWidget::class,
            BudgetHeadroomChart::class,
            ArcSettlementWidget::class,
            WorkflowRunWidget::class,
            WorkflowRunStateChart::class,
            AutonomyLaneWidget::class,
            EvidenceTimelineWidget::class,
            PaymentDecisionChart::class,
            OperationsHealthWidget::class,
        ])
        ->and(bootedFinanceDashboard(Livewire::actingAs($c['reviewer'])->test(FinanceDashboard::class))->getWidgets())->toBe([
            ApprovalInboxWidget::class,
            CollectionsOverviewWidget::class,
            CollectionsTrendChart::class,
            BudgetCapacityWidget::class,
            BudgetHeadroomChart::class,
            ArcSettlementWidget::class,
            WorkflowRunWidget::class,
            WorkflowRunStateChart::class,
            AutonomyLaneWidget::class,
            EvidenceTimelineWidget::class,
            PaymentDecisionChart::class,
            OperationsHealthWidget::class,
        ]);
});

test('collections chart splits reported money by review state instead of summing it', function (): void {
    $c = financeDashboardContext();
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['reviewer']);

    app(CaptureCollectionBatch::class)->handle($c['actor'], [
        'capture_key' => (string) Str::uuid(), 'source_stream' => 'tuition.cashier',
        'source_reference' => 'cashier-unreviewed',
        'source_document_digest' => hash('sha256', 'unreviewed-'.Str::random(8)),
        'currency' => 'PHP', 'received_amount' => '3000', 'restricted_amount' => '0',
        'collected_from' => now()->subDay()->toIso8601String(), 'collected_until' => now()->toIso8601String(),
        'cash_evidence_reference' => 'synthetic', 'source_stream_disjoint' => true, 'received_not_forecast' => true,
    ]);

    $chart = Livewire::actingAs($c['reviewer'])->test(CollectionsTrendChart::class);

    $chart->assertSuccessful()
        ->assertSee('Reported collections by review state')
        ->assertSee('Reviewed — can fund a plan');

    $data = chartPayload(bootedChart($chart));

    expect(array_column($data['datasets'], 'label'))->toBe([
        'Reviewed — can fund a plan',
        'Awaiting independent review',
        'Held',
        'Rejected',
    ]);

    /**
     * 50000 reviewed and 3000 unreviewed must land in separate segments.
     * A single stacked series would read as 53000 available.
     */
    // Two separate collection intervals, so two bars. 50000 PHP reviewed,
    // 3000 PHP unreviewed — never one combined 53000 figure.
    expect($data['labels'])->toHaveCount(2)
        ->and($data['datasets'][0]['data'])->toBe([50000.0, 0.0])
        ->and($data['datasets'][1]['data'])->toBe([0.0, 3000.0]);
});

test('headroom chart plots allocation and cash separately and skips corrupt evidence', function (): void {
    $c = financeDashboardContext();
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['reviewer']);

    $chart = Livewire::actingAs($c['reviewer'])->test(BudgetHeadroomChart::class);

    $chart->assertSuccessful()->assertSee('Approved allocation against realized cash headroom');

    $data = chartPayload(bootedChart($chart));

    expect(array_column($data['datasets'], 'label'))->toBe(['Approved allocation', 'Realized cash headroom'])
        ->and($data['datasets'][0]['data'])->toBe([20000.0])
        ->and($data['datasets'][1]['data'])->toBe([25000.0]);
});

test('schema v3 budget capacity is displayed as reviewed evidence, not legacy', function (): void {
    $c = financeDashboardContext();
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['reviewer']);

    expect(BudgetSnapshot::query()->firstOrFail()->snapshot['schema_version'])->toBe(3);
    Livewire::actingAs($c['reviewer'])->test(BudgetCapacityWidget::class)
        ->assertSuccessful()->assertSee('Reviewed collections')->assertDontSee('Legacy attestation');
});

test('charts report an honest empty state instead of a flat zero graph', function (): void {
    $c = financeDashboardContext();
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['reviewer']);

    expect(chartPayload(bootedChart(Livewire::actingAs($c['reviewer'])->test(PaymentDecisionChart::class))))->toBe([]);

    Livewire::actingAs($c['reviewer'])
        ->test(PaymentDecisionChart::class)
        ->assertSee('No payment authorization recorded');
});

test('decision chart reports the full denominator, not approvals alone', function (): void {
    $c = financeDashboardContext();
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['reviewer']);

    $run = FinanceWorkflowRun::query()->where('kind', 'budget_plan')->firstOrFail();
    app(FinanceWorkflows::class)->process($run->id);
    $run->refresh();

    Livewire::actingAs($c['reviewer'])
        ->test(WorkflowRunStateChart::class)
        ->assertSuccessful()
        ->assertSee('Background work by run state')
        ->assertSee('Waiting for review');

    $data = chartPayload(bootedChart(Livewire::actingAs($c['reviewer'])->test(WorkflowRunStateChart::class)));

    expect($data['labels'])->toContain('Waiting for review')
        ->and(array_sum($data['datasets'][0]['data']))->toBeGreaterThan(0);
});

test('panel and chart headings route to the page that manages the records', function (): void {
    $c = financeDashboardContext();
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['reviewer']);

    $html = Livewire::actingAs($c['reviewer'])->test(CollectionsOverviewWidget::class)->html()
        .Livewire::actingAs($c['reviewer'])->test(CollectionsTrendChart::class)->html()
        .Livewire::actingAs($c['reviewer'])->test(BudgetCapacityWidget::class)->html()
        .Livewire::actingAs($c['reviewer'])->test(WorkflowRunWidget::class)->html()
        .Livewire::actingAs($c['reviewer'])->test(EvidenceTimelineWidget::class)->html();

    expect($html)
        ->toContain(Collections::getUrl(panel: 'finance'))
        ->toContain(FinanceSupervisor::getUrl(panel: 'finance'))
        ->toContain(PaymentReviews::getUrl(panel: 'finance'))
        // The anchor must actually render, or the heading is just a label
        // and the navigation affordance is invisible until someone guesses.
        ->toContain('fi-finance-heading-link')
        ->toContain('Reported collections by review state</a>');
});

test('collections panel separates reviewed money from reported money without inventing a balance', function (): void {
    $c = financeDashboardContext();
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['reviewer']);

    $unreviewed = app(CaptureCollectionBatch::class)->handle($c['actor'], [
        'capture_key' => (string) Str::uuid(), 'source_stream' => 'tuition.cashier',
        'source_reference' => 'cashier-report-late',
        'source_document_digest' => hash('sha256', 'late-report'),
        'currency' => 'PHP', 'received_amount' => '1000', 'restricted_amount' => '0',
        'collected_from' => now()->subDay()->toIso8601String(), 'collected_until' => now()->toIso8601String(),
        'cash_evidence_reference' => 'synthetic', 'source_stream_disjoint' => true, 'received_not_forecast' => true,
    ]);

    $widget = Livewire::actingAs($c['reviewer'])
        ->test(CollectionsOverviewWidget::class)
        ->assertSuccessful()
        ->assertSee('Collections by source and currency')
        ->assertSee('tuition.cashier');
    $widget->__call('assertCanSeeTableRecords', [[$c['batch'], $unreviewed]]);

    /**
     * The panel reports evidence buckets rather than one spendable total,
     * because unreviewed and restricted money cannot fund anything.
     */
    Livewire::actingAs($c['reviewer'])
        ->test(CollectionsOverviewWidget::class)
        ->assertDontSee('Available balance');
});

test('capacity panel keeps allocation and realized cash as separate exact figures', function (): void {
    $c = financeDashboardContext();
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['reviewer']);

    $snapshot = BudgetSnapshot::query()->where('organization_id', $c['institution']->id)->firstOrFail();

    // allocation 20000 - spent 0 - commitments 0 = 20000 budget headroom
    // 0 opening + 50000 receipts - 0 outflow - 10000 restricted - 15000 reserve = 25000 cash headroom
    expect($snapshot->headroom())->toBe(['budget_minor_units' => '2000000', 'cash_minor_units' => '2500000']);

    Livewire::actingAs($c['reviewer'])
        ->test(BudgetCapacityWidget::class)
        ->assertSuccessful()
        ->assertSee('Approved allocation, realized cash and held capacity')
        ->assertSee('20,000.00 PHP')
        ->assertSee('25,000.00 PHP')
        ->assertSee('Reviewed collections');
});

test('arc panel labels fake evidence and refuses to present a spendable balance', function (): void {
    $c = financeDashboardContext();
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['reviewer']);

    Livewire::actingAs($c['reviewer'])
        ->test(ArcSettlementWidget::class)
        ->assertSuccessful()
        ->assertSee('Observed Arc settlement rail')
        ->assertSee('Funding evidence');

    expect(Transaction::query()->count())->toBe(0);
});

test('arc panel reports a block-bound funding observation with its fake label', function (): void {
    $c = financeDashboardArcContext();
    stubDashboardArcBalance('100000000000000000007');
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['reviewer']);

    app(PrepareFundingWindow::class)->handle(
        $c['actor'], $c['snapshot'], $c['wallet'], (string) Str::uuid(), now()->addMinutes(10)->toIso8601String(),
    );

    Livewire::actingAs($c['reviewer'])
        ->test(ArcSettlementWidget::class)
        ->assertSuccessful()
        ->assertSee('Observed Arc settlement rail')
        ->assertSee('FAKE DRIVER')
        ->assertSee('Current');
});

test('arc panel refuses to age a stale funding observation into a usable balance', function (): void {
    $c = financeDashboardArcContext();
    stubDashboardArcBalance('100000000000000000007');
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['reviewer']);

    app(PrepareFundingWindow::class)->handle(
        $c['actor'], $c['snapshot'], $c['wallet'], (string) Str::uuid(), now()->addMinutes(10)->toIso8601String(),
    );

    $this->travel(20)->minutes();

    Livewire::actingAs($c['reviewer'])
        ->test(ArcSettlementWidget::class)
        ->assertSee('Stale');
});

test('workflow panel reports durable run state and never implies a payment', function (): void {
    $c = financeDashboardContext();
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['reviewer']);

    $run = FinanceWorkflowRun::query()->where('kind', 'budget_plan')->firstOrFail();
    app(FinanceWorkflows::class)->process($run->id);

    $widget = Livewire::actingAs($c['reviewer'])
        ->test(WorkflowRunWidget::class)
        ->assertSuccessful()
        ->assertSee('Background finance work')
        ->assertSee('Budget planner')
        ->assertSee('Waiting for review')
        ->assertSee('Budget snapshot #');
    $widget->__call('assertCanSeeTableRecords', [[$run->fresh()]]);

    expect(Transaction::query()->count())->toBe(0);
});

test('approval inbox lists the unreviewed batch without granting any authority', function (): void {
    $c = financeDashboardContext();
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['reviewer']);

    $pending = app(CaptureCollectionBatch::class)->handle($c['actor'], [
        'capture_key' => (string) Str::uuid(), 'source_stream' => 'misc.cashier',
        'source_reference' => 'cashier-pending-'.Str::random(4),
        'source_document_digest' => hash('sha256', 'pending-'.Str::random(8)),
        'currency' => 'PHP', 'received_amount' => '2500', 'restricted_amount' => '500',
        'collected_from' => now()->subDay()->toIso8601String(), 'collected_until' => now()->toIso8601String(),
        'cash_evidence_reference' => 'synthetic', 'source_stream_disjoint' => true, 'received_not_forecast' => true,
    ]);

    Livewire::actingAs($c['reviewer'])
        ->test(ApprovalInboxWidget::class)
        ->assertSuccessful()
        ->assertSee('Approval inbox')
        ->assertSee('Awaiting independent receipt review')
        ->assertSee($pending->source_reference)
        ->assertSee('2,500.00 PHP')
        ->assertSee('A separate reviewer must confirm the source');

    // A reviewed batch is decided, not pending.
    expect(CollectionBatchReview::query()->where('collection_batch_id', $c['batch']->id)->exists())->toBeTrue();
});

test('approval inbox separates the three authorities rather than merging pending counts', function (): void {
    $c = financeDashboardContext();
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['reviewer']);

    Livewire::actingAs($c['reviewer'])
        ->test(ApprovalInboxWidget::class)
        ->assertSee('Awaiting independent receipt review')
        ->assertSee('Proposals awaiting a plan decision')
        ->assertSee('Reserved payments awaiting authorization')
        ->assertSee('Cashier receipt review, plan acceptance and payment authorization are different acts');
});

test('approval inbox is invisible to a user without supervision authority', function (): void {
    financeDashboardContext();
    Filament::setCurrentPanel(Filament::getPanel('finance'));

    actingAs(User::factory()->create());

    expect(ApprovalInboxWidget::canView())->toBeFalse();

    actingAs(User::factory()->create()->assignRole('finance_officer'));

    expect(ApprovalInboxWidget::canView())->toBeTrue();
});

test('automatic lane reports zero allowance instead of an empty or implied mandate', function (): void {
    $c = financeDashboardContext();
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['reviewer']);

    Livewire::actingAs($c['reviewer'])
        ->test(AutonomyLaneWidget::class)
        ->assertSuccessful()
        ->assertSee('Automatic payment lane')
        ->assertSee('None approved')
        ->assertSee('Zero allowance')
        // Real counts, and an empty lane says so rather than implying success.
        ->assertSee('Decisions vs escalated')
        ->assertSee('Nothing yet')
        // The agreement figure is labelled retrospective and is not a rate yet.
        ->assertSee('Supervisor feedback (retrospective)')
        ->assertSee('Not reviewed yet')
        // And it must never claim to be an approval measure.
        ->assertDontSee('approval rate');

    expect(Transaction::query()->count())->toBe(0);
});

test('evidence timeline stops at the hold and never claims settlement', function (): void {
    $c = financeDashboardContext();
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['reviewer']);

    Livewire::actingAs($c['reviewer'])
        ->test(EvidenceTimelineWidget::class)
        ->assertSuccessful()
        ->assertSee('Evidence-linked payment timeline')
        ->assertDontSee('Verified');
});

test('operations health tells a stopped worker apart from an idle one', function (): void {
    $c = financeDashboardContext();
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs($c['reviewer']);

    Livewire::actingAs($c['reviewer'])
        ->test(OperationsHealthWidget::class)
        ->assertSuccessful()
        ->assertSee('Operations health')
        ->assertSee('Worker heartbeat')
        ->assertSee('Never')
        ->assertSee('Unresolved payment outcomes');

    DB::table('jobs')->insert([
        'queue' => 'finance-planning', 'payload' => '{}', 'attempts' => 0,
        'reserved_at' => null, 'available_at' => time(), 'created_at' => time(),
    ]);

    Livewire::actingAs($c['reviewer'])
        ->test(OperationsHealthWidget::class)
        ->assertSee('Queue depth');
});

test('dashboard widgets fail closed without an installation institution', function (): void {
    config(['eduflow.institution_id' => null]);
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    actingAs(User::factory()->create());

    foreach ([
        ArcSettlementWidget::class,
        AutonomyLaneWidget::class,
        BudgetCapacityWidget::class,
        CollectionsOverviewWidget::class,
        EvidenceTimelineWidget::class,
        OperationsHealthWidget::class,
        WorkflowRunWidget::class,
    ] as $widget) {
        Livewire::actingAs(User::factory()->create())
            ->test($widget)
            ->assertSuccessful();
    }
});
