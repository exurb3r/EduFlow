<?php

declare(strict_types=1);

use App\Filament\Pages\TreasuryWallet;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Wallet;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function (): void {
    foreach (['super_admin', 'admin', 'finance_officer', 'user'] as $role) {
        Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }

    config([
        'eduflow.institution_id' => null,
        'lepton.default' => 'fake',
        'lepton.arc.chain' => 'ARC-TESTNET',
        'lepton.arc.chain_id' => 5042002,
        'lepton.arc.treasury' => '0x'.str_repeat('5', 40),
    ]);

    $fake = new FakeLeptonGateway;
    $fake->fakeAuthenticated();
    $this->app->instance(ArcNetworkGateway::class, $fake);

    /** @var Organization $institution */
    $institution = Organization::factory()->create();
    $this->institution = $institution;

    /** @var Wallet $wallet */
    $wallet = Wallet::query()->create([
        'organization_id' => $institution->id,
        'provider' => 'circle',
        'network' => 'arc',
        'address' => '0x'.str_repeat('5', 40),
        'balance' => 100.0,
        'status' => 'active',
    ]);
    $this->wallet = $wallet;

    $this->superAdmin = User::factory()->create()->assignRole('super_admin');
    $this->admin = User::factory()->create()->assignRole('admin');
    $this->financeOfficer = User::factory()->create()->assignRole('finance_officer');
    $this->regularUser = User::factory()->create()->assignRole('user');

    Filament::setCurrentPanel('admin');
});

test('guests are redirected to login', function (): void {
    get(TreasuryWallet::getUrl())
        ->assertRedirect('/admin/login');
});

test('unauthorized users cannot access treasury wallet page', function (): void {
    actingAs($this->regularUser)
        ->get(TreasuryWallet::getUrl())
        ->assertForbidden();
});

test('super admin can view treasury wallet page with balance and invoices', function (): void {
    /** @var Organization $institution */
    $institution = $this->institution;

    /** @var Vendor $vendor */
    $vendor = Vendor::query()->create([
        'organization_id' => $institution->id,
        'name' => 'Acme Supplies',
        'wallet_address' => '0x'.str_repeat('2', 40),
        'status' => 'verified',
        'risk_level' => 'low',
    ]);

    $unpaidInvoice = Invoice::query()->create([
        'organization_id' => $institution->id,
        'vendor_id' => $vendor->id,
        'reference' => 'INV-UNPAID-1',
        'amount' => 25.0,
        'due_date' => now()->addDays(5),
        'category' => 'supplies',
        'status' => 'pending',
    ]);

    $paidInvoice = Invoice::query()->create([
        'organization_id' => $institution->id,
        'vendor_id' => $vendor->id,
        'reference' => 'INV-PAID-1',
        'amount' => 50.0,
        'due_date' => now()->subDays(2),
        'category' => 'services',
        'status' => 'paid',
    ]);

    actingAs($this->superAdmin);

    $response = get(TreasuryWallet::getUrl());
    $response->assertOk();

    $test = Livewire::test(TreasuryWallet::class);
    $test->__call('assertCanSeeTableRecords', [[$unpaidInvoice]]);
    $test->__call('assertCanNotSeeTableRecords', [[$paidInvoice]]);
    $test->assertSee('25.00')
        ->assertSee($this->wallet->address);
});

test('non super admin users cannot access admin treasury wallet page', function (): void {
    actingAs($this->admin)
        ->get(TreasuryWallet::getUrl())
        ->assertForbidden();

    actingAs($this->financeOfficer)
        ->get(TreasuryWallet::getUrl())
        ->assertForbidden();
});

test('sync balance action updates wallet ledger from chain', function (): void {
    actingAs($this->superAdmin);

    $test = Livewire::test(TreasuryWallet::class);
    $test->__call('callAction', ['syncBalance']);
    $test->__call('assertNotified', []);
});
