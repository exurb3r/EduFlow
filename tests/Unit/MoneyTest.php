<?php

declare(strict_types=1);

use App\DTOs\Money;
use App\Enums\CurrencyCode;

test('money parses exact decimals and formats every supported currency', function (CurrencyCode $currency): void {
    $value = $currency === CurrencyCode::USDC ? '1234.123456' : '1234.12';
    $money = Money::fromDecimal($value, $currency);

    expect($money->decimal())->toBe($value)
        ->and($money->minorUnits)->toBe($currency === CurrencyCode::USDC ? 1234123456 : 123412)
        ->and($money->format())->toBe($currency === CurrencyCode::USDC ? '1,234.123456' : '1,234.12');
})->with(CurrencyCode::cases());

test('money preserves integer boundaries and rejects out of range decimals', function (): void {
    $max = new Money(PHP_INT_MAX, CurrencyCode::USDC);
    $min = new Money(PHP_INT_MIN, CurrencyCode::USDC);

    expect(Money::fromDecimal($max->decimal(), CurrencyCode::USDC)->minorUnits)->toBe(PHP_INT_MAX)
        ->and(Money::fromDecimal($min->decimal(), CurrencyCode::USDC)->minorUnits)->toBe(PHP_INT_MIN)
        ->and($max->decimal())->toBe('9223372036854.775807')
        ->and($min->decimal())->toBe('-9223372036854.775808')
        ->and(fn () => Money::fromDecimal('9223372036854.775808', CurrencyCode::USDC))->toThrow(OverflowException::class);
});

test('money refuses ambiguous or lossy input', function (string $input): void {
    expect(fn () => Money::fromDecimal($input, CurrencyCode::USD))->toThrow(InvalidArgumentException::class);
})->with(['', ' 1.00', '1.00 ', '1,000.00', '1e3', '+1.00', '.50', '1.', '1.001', '--1', 'NaN']);

test('money rounds display separately without losing stored precision', function (): void {
    $money = Money::fromDecimal('999.995001', CurrencyCode::USDC);

    expect($money->format(2))->toBe('1,000.00')
        ->and($money->decimal())->toBe('999.995001')
        ->and(Money::fromDecimal('-1.235000', CurrencyCode::USDC)->format(2))->toBe('-1.24')
        ->and(Money::fromDecimal('0.000001', CurrencyCode::USDC)->format())->toBe('0.000001');
});

test('money addition subtraction and serialization remain exact above JavaScript safe integer', function (): void {
    $money = new Money(9007199254740993, CurrencyCode::USDC);
    $unit = new Money(1, CurrencyCode::USDC);

    expect($money->plus($unit)->minorUnits)->toBe(9007199254740994)
        ->and($money->minus($unit)->minorUnits)->toBe(9007199254740992)
        ->and(json_decode(json_encode($money, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'minor_units' => '9007199254740993', 'currency' => 'USDC', 'decimal' => '9007199254.740993',
        ]);
});

test('money refuses currency mismatch and arithmetic overflow', function (): void {
    expect(fn () => (new Money(1, CurrencyCode::USD))->plus(new Money(1, CurrencyCode::PHP)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new Money(PHP_INT_MAX, CurrencyCode::USDC))->plus(new Money(1, CurrencyCode::USDC)))->toThrow(OverflowException::class)
        ->and(fn () => (new Money(PHP_INT_MIN, CurrencyCode::USDC))->minus(new Money(1, CurrencyCode::USDC)))->toThrow(OverflowException::class);
});
