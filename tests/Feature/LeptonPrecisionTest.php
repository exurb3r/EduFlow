<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Wallet;
use App\Services\LeptonTreasuryService;
use Yukazakiri\Lepton\Support\Amounts;

/**
 * Guards the precision and formatting traps discovered while wiring live Arc
 * reads. These are the failures that silently produced plausible-looking but
 * wrong numbers on the operations dashboard.
 */
it('reads an 18-decimal balance that overflows a 64-bit int without corrupting it', function (): void {
    // 20 USDC at 18 decimals is 2e19 wei, which exceeds PHP_INT_MAX.
    expect(Amounts::fromHexQuantity('0x1158e460913d00000', 18))->toBe('20')
        ->and((float) Amounts::fromHexQuantity('0x1158e460913d00000', 18))->toBe(20.0);
});

it('keeps sub-unit precision for dust balances', function (): void {
    expect(Amounts::fromHexQuantity('0x1', 18))->toBe('0.000000000000000001')
        ->and((float) Amounts::fromHexQuantity('0x1', 18))->toBe(1.0e-18);
});

it('returns raw numerics so large ledger figures are not truncated by the comma', function (): void {
    $org = Organization::create([
        'name' => 'Numeric Academy',
        'currency' => 'USDC',
        'minimum_reserve' => 10000.00,
        'max_auto_payment' => 1000.00,
        'max_daily_disbursement' => 5000.00,
        'human_approval_threshold' => 1000.00,
    ]);

    $wallet = Wallet::create([
        'organization_id' => $org->id,
        'provider' => 'circle',
        'network' => 'arc',
        'address' => '0x1234567890123456789012345678901234567890',
        'balance' => 24470.00,
        'status' => 'active',
    ]);

    $status = app(LeptonTreasuryService::class)->status($wallet);

    // A pre-formatted "24,470.00" would cast to 24.0 and hide the drift.
    expect($status['ledger_balance'])->toBe(24470.0)
        ->and((float) $status['ledger_balance'])->toBe(24470.0);
});

it('never reports a fake driver as live settlement', function (): void {
    config([
        'lepton.default' => 'fake',
        'lepton.arc.treasury' => '0x1234567890123456789012345678901234567890',
    ]);

    $org = Organization::create([
        'name' => 'Fake Academy',
        'currency' => 'USDC',
        'minimum_reserve' => 10000.00,
        'max_auto_payment' => 1000.00,
        'max_daily_disbursement' => 5000.00,
        'human_approval_threshold' => 1000.00,
    ]);

    $wallet = Wallet::create([
        'organization_id' => $org->id,
        'provider' => 'circle',
        'network' => 'arc',
        'address' => '0x1234567890123456789012345678901234567890',
        'balance' => 100.00,
        'status' => 'active',
    ]);

    $status = app(LeptonTreasuryService::class)->status($wallet);

    expect($status['is_fake'])->toBeTrue()
        ->and($status['driver'])->toBe('fake');
});

it('keeps the reported block number faithful to the chain', function (): void {
    config(['lepton.default' => 'fake', 'lepton.arc.treasury' => '0x1234567890123456789012345678901234567890']);

    $org = Organization::create([
        'name' => 'Block Academy',
        'currency' => 'USDC',
        'minimum_reserve' => 10000.00,
        'max_auto_payment' => 1000.00,
        'max_daily_disbursement' => 5000.00,
        'human_approval_threshold' => 1000.00,
    ]);

    $wallet = Wallet::create([
        'organization_id' => $org->id,
        'provider' => 'circle',
        'network' => 'arc',
        'address' => '0x1234567890123456789012345678901234567890',
        'balance' => 100.00,
        'status' => 'active',
    ]);

    $status = app(LeptonTreasuryService::class)->status($wallet);

    // The fake gateway reports 0x0. Anything non-negative and exact is fine;
    // what matters is that it is not a hexdec()-mangled negative.
    expect($status['block'])->toBe(0)
        ->and($status['block'])->not->toBeLessThan(0);
});

it('has no hexdec cast left in the chain-reading code paths', function (): void {
    // hexdec() silently degrades to float past PHP_INT_MAX. Every chain read
    // must go through Amounts::fromHexQuantity() instead.
    $paths = [
        'app/Services/LeptonTreasuryService.php',
        'app/Services/LeptonReconciliationService.php',
        'app/Console/Commands/LeptonDoctor.php',
        'app/Filament/Resources/Transactions/Pages/ViewTransaction.php',
    ];

    $offenders = [];

    foreach ($paths as $path) {
        $code = implode("\n", array_filter(
            explode("\n", (string) file_get_contents(base_path($path))),
            fn (string $line): bool => ! str_starts_with(trim($line), '*') && ! str_starts_with(trim($line), '//'),
        ));

        if (str_contains($code, 'hexdec(')) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], 'These still cast a hex quantity with hexdec(): '.implode(', ', $offenders));
});
