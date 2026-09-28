<?php

declare(strict_types=1);

use Eduflow\Lepton\Gateways\FakeLeptonGateway;
use Eduflow\Lepton\Support\Amounts;

it('converts decimal strings to base units without floats', function (): void {
    expect(Amounts::fromDecimalString('100.00'))->toBe(100_000000)
        ->and(Amounts::fromDecimalString('0.01'))->toBe(10_000)
        ->and(Amounts::toCliAmount(100_000000))->toBe('100')
        ->and(Amounts::toCliAmount(100_500000))->toBe('100.5');
});

it('fake gateway tracks balances in memory', function (): void {
    $gateway = new FakeLeptonGateway;
    $gateway->seedBalance('0xSRC', 500_000000);

    $result = $gateway->transfer('0xSRC', '0xDST', 100_000000);

    expect($result->isFake)->toBeTrue()
        ->and($gateway->balance('0xSRC')->amountBaseUnits)->toBe(400_000000)
        ->and($gateway->balance('0xDST')->amountBaseUnits)->toBe(100_000000);
});
