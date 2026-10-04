<?php

declare(strict_types=1);

use App\Enums\CurrencyCode;
use App\Models\CurrencyRate;
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

test('currency conversion survives intermediate overflow and refuses out of range results', function (): void {
    $converter = app(CurrencyConverter::class);

    expect($converter->usdcToFiat(PHP_INT_MAX, CurrencyCode::USD))->toBe(intdiv(PHP_INT_MAX, 10000))
        ->and($converter->fiatToUsdc(1_000_000_000_000, CurrencyCode::USD))->toBe(10_000_000_000_000_000)
        ->and($converter->usdcToFiat(PHP_INT_MIN, CurrencyCode::USD))->toBe(intdiv(PHP_INT_MIN, 10000))
        ->and(fn () => $converter->fiatToUsdc(PHP_INT_MAX, CurrencyCode::USD))->toThrow(OverflowException::class)
        ->and($converter->formatUsdc(PHP_INT_MAX))->toBe('9,223,372,036,854.775807 USDC');
});

test('quote snapshot retains source timestamps and cannot extend source expiry', function (): void {
    $this->freezeSecond();
    $rate = CurrencyRate::query()->create([
        'base_code' => 'USDC', 'quote_code' => 'PHP', 'units_per_usdc' => 5800,
        'provider' => 'reviewed-feed', 'quoted_at' => now()->subMinutes(2),
        'expires_at' => now()->addMinutes(3), 'is_fallback' => false,
    ]);

    $quote = app(CurrencyConverter::class)->requireFreshQuote(CurrencyCode::PHP);

    expect($quote['units_per_usdc'])->toBe(5800)
        ->and($quote['quoted_at'])->toBe($rate->quoted_at->toIso8601String())
        ->and($quote['expires_at'])->toBe($rate->expires_at->toIso8601String())
        ->and($quote['locked_at'])->toBe(now()->toIso8601String())
        ->and($quote['indicative'])->toBeTrue();
});

test('USD display uses an available source rate instead of forced one to one parity', function (): void {
    CurrencyRate::query()->create([
        'base_code' => 'USDC', 'quote_code' => 'USD', 'units_per_usdc' => 98,
        'provider' => 'reviewed-feed', 'quoted_at' => now(), 'expires_at' => now()->addMinutes(5), 'is_fallback' => false,
    ]);

    expect(app(CurrencyConverter::class)->usdcToFiat(100_000000, CurrencyCode::USD))->toBe(9800);
});

test('fresh quote refuses stale future fallback unbounded or invalid source rates', function (array $changes): void {
    $this->freezeSecond();
    CurrencyRate::query()->create(array_replace([
        'base_code' => 'USDC', 'quote_code' => 'PHP', 'units_per_usdc' => 5800,
        'provider' => 'reviewed-feed', 'quoted_at' => now(), 'expires_at' => now()->addMinutes(5), 'is_fallback' => false,
    ], $changes));

    expect(fn () => app(CurrencyConverter::class)->requireFreshQuote(CurrencyCode::PHP))->toThrow(RuntimeException::class);
})->with([
    'fallback' => [['is_fallback' => true]],
    'missing expiry' => [['expires_at' => null]],
    'missing provider' => [['provider' => '']],
    'invalid rate' => [['units_per_usdc' => 0]],
]);

test('expired future and old sources do not become fresh locked quotes', function (string $case): void {
    $this->freezeSecond();
    $quotedAt = match ($case) {
        'future' => now()->addMinute(),
        'old' => now()->subHour(),
        default => now()->subMinutes(5),
    };
    CurrencyRate::query()->create([
        'base_code' => 'USDC', 'quote_code' => 'PHP', 'units_per_usdc' => 5800,
        'provider' => 'reviewed-feed', 'quoted_at' => $quotedAt,
        'expires_at' => $case === 'expired' ? now() : now()->addMinutes(10), 'is_fallback' => false,
    ]);

    $converter = app(CurrencyConverter::class);
    expect($converter->lockQuote(CurrencyCode::PHP)['provider'])->toBe('fallback')
        ->and(fn () => $converter->requireFreshQuote(CurrencyCode::PHP))->toThrow(RuntimeException::class);
})->with(['expired', 'future', 'old']);

test('quote lifetime is bounded by source age even when provider expiry is later', function (): void {
    $this->freezeSecond();
    CurrencyRate::query()->create([
        'base_code' => 'USDC', 'quote_code' => 'PHP', 'units_per_usdc' => 5800,
        'provider' => 'reviewed-feed', 'quoted_at' => now()->subMinutes(14),
        'expires_at' => now()->addHour(), 'is_fallback' => false,
    ]);

    expect(app(CurrencyConverter::class)->requireFreshQuote(CurrencyCode::PHP)['expires_at'])
        ->toBe(now()->addMinute()->toIso8601String());

    $this->travel(1)->minutes();
    expect(fn () => app(CurrencyConverter::class)->requireFreshQuote(CurrencyCode::PHP))->toThrow(RuntimeException::class);
});

test('rate expiry boundary is expired and fiat identity conversion preserves integer range', function (): void {
    $this->freezeSecond();
    $rate = new CurrencyRate(['expires_at' => now()]);
    $converter = app(CurrencyConverter::class);

    expect($rate->isExpired())->toBeTrue()
        ->and($converter->usdcToFiat(PHP_INT_MAX, CurrencyCode::USDC))->toBe(PHP_INT_MAX)
        ->and($converter->fiatToUsdc(PHP_INT_MIN, CurrencyCode::USDC))->toBe(PHP_INT_MIN);
});

test('static rates remain display only and same currency needs no FX provider', function (): void {
    $converter = app(CurrencyConverter::class);

    expect($converter->lockQuote(CurrencyCode::PHP)['indicative'])->toBeTrue()
        ->and(fn () => $converter->requireFreshQuote(CurrencyCode::PHP))->toThrow(RuntimeException::class)
        ->and($converter->requireFreshQuote(CurrencyCode::USDC)['indicative'])->toBeFalse()
        ->and($converter->requireFreshQuote(CurrencyCode::USDC)['provider'])->toBe('identity');
});
