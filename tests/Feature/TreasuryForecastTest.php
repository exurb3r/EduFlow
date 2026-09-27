<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Vendor;
use App\Models\Wallet;
use App\Services\TreasuryForecastService;

beforeEach(function (): void {
    $this->forecastService = app(TreasuryForecastService::class);

    $this->org = Organization::create([
        'name' => 'Forecast Academy',
        'currency' => 'USDC',
        'minimum_reserve' => 10000.00,
        'max_auto_payment' => 1000.00,
        'max_daily_disbursement' => 5000.00,
        'human_approval_threshold' => 1000.00,
    ]);

    $this->wallet = Wallet::create([
        'organization_id' => $this->org->id,
        'provider' => 'circle',
        'network' => 'arc',
        'address' => '0xforecastholder',
        'balance' => 20000.00,
        'status' => 'active',
    ]);

    $this->vendor = Vendor::create([
        'organization_id' => $this->org->id,
        'name' => 'Utility Corp',
        'wallet_address' => '0xutil123',
        'status' => 'verified',
        'risk_level' => 'low',
    ]);
});

test('forecast predicts safe status when projected balance remains above reserve', function (): void {
    Invoice::create([
        'organization_id' => $this->org->id,
        'vendor_id' => $this->vendor->id,
        'reference' => 'INV-SAFE-1',
        'amount' => 3000.00,
        'due_date' => now()->addDays(5),
        'status' => 'pending',
    ]);

    $forecast = $this->forecastService->forecast($this->org, $this->wallet, 30);

    expect($forecast->currentBalance)->toBe(20000.00)
        ->and($forecast->scheduledObligations)->toBe(3000.00)
        ->and($forecast->projectedBalance)->toBe(17000.00)
        ->and($forecast->isSafe())->toBeTrue()
        ->and($forecast->daysUntilReserveBreach)->toBeNull();
});

test('forecast predicts critical status and identifies day of reserve breach', function (): void {
    // 20,000 balance, 10,000 reserve
    // Day 10: 8,000 invoice (balance 12,000 - safe)
    // Day 18: 4,000 invoice (balance drops to 8,000 - breach on day 18!)
    Invoice::create([
        'organization_id' => $this->org->id,
        'vendor_id' => $this->vendor->id,
        'reference' => 'INV-BREACH-1',
        'amount' => 8000.00,
        'due_date' => now()->addDays(10),
        'status' => 'pending',
    ]);

    Invoice::create([
        'organization_id' => $this->org->id,
        'vendor_id' => $this->vendor->id,
        'reference' => 'INV-BREACH-2',
        'amount' => 4000.00,
        'due_date' => now()->addDays(18),
        'status' => 'pending',
    ]);

    $forecast = $this->forecastService->forecast($this->org, $this->wallet, 30);

    expect($forecast->projectedBalance)->toBe(8000.00)
        ->and($forecast->isCritical())->toBeTrue()
        ->and($forecast->daysUntilReserveBreach)->toBe(18);
});
