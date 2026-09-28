<?php

declare(strict_types=1);

use App\Enums\CurrencyCode;
use App\Services\CurrencyConverter;

test('converts USDC base units to fiat minor units with integer math', function (): void {
    $converter = app(CurrencyConverter::class);

    // 100 USDC = 100_000000 base units -> 5,750 PHP minor units (57.50 PHP per USDC)
    expect($converter->usdcToFiat(100_000000, CurrencyCode::PHP))->toBe(575000)
        ->and($converter->usdcToFiat(100_000000, CurrencyCode::USD))->toBe(10000)
        ->and($converter->fiatToUsdc(575000, CurrencyCode::PHP))->toBe(100_000000);
});

test('locks a quote with expiry for audit trail', function (): void {
    $converter = app(CurrencyConverter::class);

    $quote = $converter->lockQuote(CurrencyCode::PHP);

    expect($quote['quote'])->toBe('PHP')
        ->and($quote['units_per_usdc'])->toBeGreaterThan(0)
        ->and($quote['quote_id'])->not->toBeEmpty()
        ->and($quote['expires_at'])->not->toBeEmpty();
});

test('formats dual display without floats', function (): void {
    $converter = app(CurrencyConverter::class);

    $formatted = $converter->formatDual(100_000000, CurrencyCode::PHP);

    expect($formatted)->toContain('USDC')->and($formatted)->toContain('PHP');
});
