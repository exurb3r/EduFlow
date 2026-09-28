<?php

declare(strict_types=1);

namespace App\Enums;

enum CurrencyCode: string
{
    case USDC = 'USDC';
    case USD = 'USD';
    case PHP = 'PHP';
    case EUR = 'EUR';
    case GBP = 'GBP';
    case CAD = 'CAD';
    case SGD = 'SGD';
    case INR = 'INR';

    public function decimals(): int
    {
        return match ($this) {
            self::USDC => 6,
            default => 2,
        };
    }

    public function multiplier(): int
    {
        return 10 ** $this->decimals();
    }

    public function symbol(): string
    {
        return match ($this) {
            self::USDC => '$',
            self::USD => '$',
            self::PHP => '₱',
            self::EUR => '€',
            self::GBP => '£',
            self::CAD => 'CA$',
            self::SGD => 'S$',
            self::INR => '₹',
        };
    }

    public function label(): string
    {
        return $this->value;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
