<?php

declare(strict_types=1);

use App\Filament\Pages\ApprovalCenter;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\AgentDecisions\AgentDecisionResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Transactions\TransactionResource;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Wallet;
use Filament\Facades\Filament;
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

test('filament admin dashboard renders custom title and widgets', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $response = $this->actingAs($admin)->get(Dashboard::getUrl());

    $response->assertSuccessful();
    $response->assertSee('EduFlow AI');
});

test('super admin can access approval center page', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $response = $this->actingAs($admin)->get(ApprovalCenter::getUrl());

    $response->assertSuccessful();
    $response->assertSee('Human-in-the-Loop Safeguard Active');
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
