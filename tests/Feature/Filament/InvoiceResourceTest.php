<?php

declare(strict_types=1);

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Invoices\Widgets\InvoiceOverviewWidget;
use App\Filament\Resources\Invoices\Widgets\InvoiceStatsWidget;
use App\Models\Budget;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use App\Models\Vendor;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach (['super_admin', 'admin', 'finance_officer', 'student', 'user'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }

    foreach (['ViewAny:Invoice', 'View:Invoice', 'Update:Invoice', 'Create:Invoice', 'Delete:Invoice'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    Role::findByName('super_admin', 'web')->syncPermissions(Permission::all());
    Role::findByName('admin', 'web')->syncPermissions(Permission::all());
});

use Illuminate\Support\Str;

function createTestVendor(int $organizationId, string $name = 'Fiber Internet Corp', string $email = 'billing@fiber.example.com'): Vendor
{
    return Vendor::query()->create([
        'organization_id' => $organizationId,
        'name' => $name,
        'category' => 'technology',
        'email' => $email,
        'wallet_address' => '0x'.str_repeat('a', 40),
        'status' => 'verified',
        'risk_level' => 'low',
    ]);
}

function createTestInvoice(array $overrides = []): Invoice
{
    return Invoice::create([
        'organization_id' => $overrides['organization_id'] ?? 1,
        'vendor_id' => $overrides['vendor_id'] ?? 1,
        'budget_id' => $overrides['budget_id'] ?? null,
        'reference' => $overrides['reference'] ?? 'INV-'.Str::upper(Str::random(8)),
        'amount' => $overrides['amount'] ?? 1000.00,
        'due_date' => $overrides['due_date'] ?? now()->addDays(14)->toDateString(),
        'category' => $overrides['category'] ?? 'operations',
        'status' => $overrides['status'] ?? 'pending',
    ]);
}

test('invoices resource is registered in admin panel', function (): void {
    expect(InvoiceResource::getUrl('index', panel: 'admin'))->toContain('invoices');
});

test('staff can open invoices list with stats widget and directory tabs', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('super_admin'));
    filament()->setCurrentPanel('admin');

    $org = Organization::factory()->create();
    $vendor = createTestVendor($org->id);

    $pending = createTestInvoice([
        'organization_id' => $org->id,
        'vendor_id' => $vendor->id,
        'reference' => 'INV-TEST-PENDING',
        'status' => 'pending',
        'amount' => 1200.00,
    ]);

    $paid = createTestInvoice([
        'organization_id' => $org->id,
        'vendor_id' => $vendor->id,
        'reference' => 'INV-TEST-PAID',
        'status' => 'paid',
        'amount' => 4500.00,
    ]);

    Livewire::test(ListInvoices::class)
        ->assertSuccessful()
        ->assertSee('INV-TEST-PENDING')
        ->assertSee('INV-TEST-PAID')
        ->set('activeTab', 'pending')
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$paid])
        ->set('activeTab', 'settled')
        ->assertCanSeeTableRecords([$paid])
        ->assertCanNotSeeTableRecords([$pending]);

    Livewire::test(InvoiceStatsWidget::class)
        ->assertSuccessful()
        ->assertSee('Total Payables')
        ->assertSee('Settled on Arc')
        ->assertSee('Pending Review');
});

test('staff can view invoice details with overview widget and settlement proof', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('super_admin'));
    filament()->setCurrentPanel('admin');

    $org = Organization::factory()->create();
    $vendor = createTestVendor($org->id, 'Fiber Internet Corp', 'billing@fiber.example.com');
    $budget = Budget::factory()->create([
        'organization_id' => $org->id,
        'name' => 'Technology Infrastructure',
    ]);

    $invoice = createTestInvoice([
        'organization_id' => $org->id,
        'vendor_id' => $vendor->id,
        'budget_id' => $budget->id,
        'reference' => 'INV-FIBER-009',
        'amount' => 2500.00,
        'status' => 'pending',
    ]);

    Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
        ->assertSuccessful()
        ->assertSee('INV-FIBER-009')
        ->assertSee('Fiber Internet Corp')
        ->assertSee('2,500.00 USDC')
        ->assertSee('Technology Infrastructure');

    Livewire::test(InvoiceOverviewWidget::class, ['record' => $invoice])
        ->assertSuccessful()
        ->assertSee('INV-FIBER-009')
        ->assertSee('Fiber Internet Corp')
        ->assertSee('2,500.00 USDC');
});

test('staff can trigger on-chain settlement reverification action', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('super_admin'));
    filament()->setCurrentPanel('admin');

    $org = Organization::factory()->create();
    $vendor = createTestVendor($org->id);

    $invoice = createTestInvoice([
        'organization_id' => $org->id,
        'vendor_id' => $vendor->id,
        'status' => 'pending',
    ]);

    Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
        ->callAction('reverify')
        ->assertNotified()
        ->assertHasNoActionErrors();
});

test('staff can update invoice from edit page', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('super_admin'));
    filament()->setCurrentPanel('admin');

    $org = Organization::factory()->create();
    $vendor = createTestVendor($org->id);

    $invoice = createTestInvoice([
        'organization_id' => $org->id,
        'vendor_id' => $vendor->id,
        'reference' => 'INV-EDITABLE-01',
        'amount' => 1000.00,
    ]);

    Livewire::test(EditInvoice::class, ['record' => $invoice->getKey()])
        ->assertSuccessful()
        ->fillForm(['amount' => 1250.00])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((float) $invoice->fresh()->amount)->toBe(1250.00);
});
