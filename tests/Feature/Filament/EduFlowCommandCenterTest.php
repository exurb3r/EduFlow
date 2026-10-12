<?php

declare(strict_types=1);

use AlizHarb\ActivityLog\Widgets\ActivityHeatmapWidget;
use App\Actions\CaptureCollectionBatch;
use App\Filament\Clusters\Settings\Pages\ApplicationDetailsSettingsPage;
use App\Filament\Clusters\Settings\Pages\ApplicationFeaturesSettingsPage;
use App\Filament\Pages\ApprovalCenter;
use App\Filament\Pages\Collections;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\FinanceDashboard;
use App\Filament\Pages\PaymentReviews;
use App\Filament\Resources\AgentDecisions\AgentDecisionResource;
use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Transactions\TransactionResource;
use App\Filament\Widgets\AdoptionActivityChart;
use App\Filament\Widgets\AgentActivityFeedWidget;
use App\Filament\Widgets\InstallationReadinessWidget;
use App\Filament\Widgets\LeptonNetworkWidget;
use App\Filament\Widgets\TreasuryOverviewWidget;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Wallet;
use Filament\Facades\Filament;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use ReflectionMethod;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->org = Organization::firstOrCreate(
        ['name' => 'Command Center Academy'],
        [
            'currency' => 'USDC',
            'minimum_reserve' => 10000.00,
            'max_auto_payment' => 1000.00,
            'max_daily_disbursement' => 5000.00,
            'human_approval_threshold' => 1000.00,
        ]
    );

    $this->wallet = Wallet::firstOrCreate(
        ['organization_id' => $this->org->id],
        [
            'provider' => 'circle',
            'network' => 'arc',
            'address' => '0xcommandcenterwallet',
            'balance' => 25420.00,
            'status' => 'active',
        ]
    );
});

test('filament admin dashboard renders the installation console', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $response = $this->actingAs($admin)->get(Dashboard::getUrl());

    $response->assertSuccessful();
    $response->assertSee('Installation &amp; operations', escape: false);
});

/** Narrow a Livewire test handle to the dashboard it booted. */
function bootedDashboard(Testable $page): Dashboard
{
    $instance = $page->instance();

    if (! $instance instanceof Dashboard) {
        throw new RuntimeException('The Livewire test did not boot the admin dashboard.');
    }

    return $instance;
}

test('admin dashboard pins its widgets instead of rendering every registered one', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $widgets = bootedDashboard(Livewire::actingAs($admin)->test(Dashboard::class))->getWidgets();

    expect($widgets)->toBe([
        InstallationReadinessWidget::class,
        AdoptionActivityChart::class,
        LeptonNetworkWidget::class,
        TreasuryOverviewWidget::class,
        AgentActivityFeedWidget::class,
        AccountWidget::class,
    ]);

    /**
     * Without the explicit list the panel renders every registered widget, so
     * a plugin's activity widget could appear beside settlement figures that
     * an operator would reasonably read as authoritative.
     */
    expect($widgets)->not->toContain(FilamentInfoWidget::class);
    expect($widgets)->not->toContain(ActivityHeatmapWidget::class);
});

test('installation readiness reports the single-institution boundary honestly', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    Livewire::actingAs($admin)
        ->test(InstallationReadinessWidget::class)
        ->assertSuccessful()
        ->assertSee('Installation readiness')
        ->assertSee('Single institution');
});

test('money posture counts evidence rather than summing float invoice amounts', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    Livewire::actingAs($admin)
        ->test(TreasuryOverviewWidget::class)
        ->assertSuccessful()
        ->assertSee('Money posture')
        ->assertSee('Local ledger only')
        ->assertSee('Not executed');
});

test('money posture stats link to the pages that manage each figure', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $html = Livewire::actingAs($admin)->test(TreasuryOverviewWidget::class)->html();

    expect($html)
        ->toContain(TransactionResource::getUrl('index'))
        ->toContain(Collections::getUrl(panel: 'finance'))
        ->toContain(PaymentReviews::getUrl(panel: 'finance'))
        ->toContain(FinanceDashboard::getUrl(panel: 'finance'));
});

test('installation activity chart reports adoption as records, not money moved', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $chart = Livewire::actingAs($admin)->test(AdoptionActivityChart::class);

    $chart->assertSuccessful()
        ->assertSee('Installation activity')
        ->assertSee('No activity recorded yet');

    $method = new ReflectionMethod($chart->instance(), 'getCachedData');
    expect($method->invoke($chart->instance()))->toBe([]);

    app(CaptureCollectionBatch::class)->handle($admin, [
        'capture_key' => (string) Str::uuid(), 'source_stream' => 'cashier', 'source_reference' => 'chart-1',
        'source_document_digest' => hash('sha256', 'chart-1'), 'currency' => 'PHP',
        'received_amount' => '1000', 'restricted_amount' => '0',
        'collected_from' => now()->subDay()->toIso8601String(), 'collected_until' => now()->toIso8601String(),
        'cash_evidence_reference' => 'synthetic', 'source_stream_disjoint' => true, 'received_not_forecast' => true,
    ]);

    $data = $method->invoke(Livewire::actingAs($admin)->test(AdoptionActivityChart::class)->instance());

    $collectionBatches = collect($data['datasets'])->firstWhere('label', 'Collection batches');

    expect($collectionBatches)->not->toBeNull()
        ->and(array_sum($collectionBatches['data']))->toBe(1)
        ->and($data['labels'])->toHaveCount(14);
});

test('readiness stats route to the settings pages that control each gate', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $html = Livewire::actingAs($admin)->test(InstallationReadinessWidget::class)->html();

    expect($html)
        ->toContain(ApplicationDetailsSettingsPage::getUrl())
        ->toContain(ApplicationFeaturesSettingsPage::getUrl())
        ->toContain(AiProviderResource::getUrl('index'))
        ->toContain(FinanceDashboard::getUrl(panel: 'finance'));
});

test('admin dashboard no longer offers fabricated ledger revenue', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $page = Livewire::actingAs($admin)->test(Dashboard::class);

    $page->assertDontSee('Record Tuition Revenue');
    $page->assertDontSee('Run Autonomous Agent Cycle');
});

test('super admin can access approval center page', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $response = $this->actingAs($admin)->get(ApprovalCenter::getUrl());

    $response->assertSuccessful();
    $response->assertSee('Legacy path');
    $response->assertSee('not the authorized vendor payment route');
});

test('super admin can access invoice and transaction resources', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $vendor = Vendor::create([
        'organization_id' => $this->org->id,
        'name' => 'Speedy Vendor',
        'wallet_address' => '0xspeedy',
        'status' => 'verified',
        'risk_level' => 'low',
    ]);

    $invoice = Invoice::create([
        'organization_id' => $this->org->id,
        'vendor_id' => $vendor->id,
        'reference' => 'INV-TEST-CMD-1',
        'amount' => 125.00,
        'due_date' => now()->addDays(2),
        'status' => 'pending',
    ]);

    $invResponse = $this->actingAs($admin)->get(InvoiceResource::getUrl('index'));
    $invResponse->assertSuccessful();
    $invResponse->assertSee($invoice->reference);

    $txResponse = $this->actingAs($admin)->get(TransactionResource::getUrl('index'));
    $txResponse->assertSuccessful();

    $decisionResponse = $this->actingAs($admin)->get(AgentDecisionResource::getUrl('index'));
    $decisionResponse->assertSuccessful();
});
