<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CurrencyCode;
use App\Models\CurrencyRate;
use Illuminate\Support\Str;

class CurrencyConverter
{
    /**
     * Fallback units of fiat minor units per 1 USDC.
     *
     * @return array<string,int>
     */
    public static function fallbackRates(): array
    {
        return [
            'USD' => 100,
            'PHP' => 5750,
            'EUR' => 92,
            'GBP' => 79,
            'CAD' => 136,
            'SGD' => 134,
            'INR' => 8300,
        ];
    }

    public function latestRate(CurrencyCode $fiat): ?CurrencyRate
    {
        if ($fiat === CurrencyCode::USDC || $fiat === CurrencyCode::USD) {
            return null;
        }

        return CurrencyRate::query()
            ->where('base_code', 'USDC')
            ->where('quote_code', $fiat->value)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('quoted_at')
            ->first();
    }

    public function unitsPerUsdc(CurrencyCode $fiat): int
    {
        if ($fiat === CurrencyCode::USDC) {
            return 1000000;
        }

        if ($fiat === CurrencyCode::USD) {
            return 100;
        }

        $rate = $this->latestRate($fiat);

        if ($rate) {
            return $rate->units_per_usdc;
        }

        return self::fallbackRates()[$fiat->value] ?? 100;
    }

    /**
     * Convert USDC base units (6 decimals) to fiat minor units (2 decimals) using integer math.
     */
    public function usdcToFiat(int $usdcBaseUnits, CurrencyCode $fiat): int
    {
        if ($fiat === CurrencyCode::USDC) {
            return $usdcBaseUnits;
        }

        return intdiv($usdcBaseUnits * $this->unitsPerUsdc($fiat), 1000000);
    }

    /**
     * Convert fiat minor units to USDC base units using integer math.
     */
    public function fiatToUsdc(int $fiatMinorUnits, CurrencyCode $fiat): int
    {
        if ($fiat === CurrencyCode::USDC) {
            return $fiatMinorUnits;
        }

        $units = $this->unitsPerUsdc($fiat);

        if ($units <= 0) {
            return 0;
        }

        return intdiv($fiatMinorUnits * 1000000, $units);
    }

    /**
     * Create an immutable locked quote snapshot for audit trail.
     *
     * @return array{quote_id:string, base:string, quote:string, units_per_usdc:int, provider:string, quoted_at:string, expires_at:string}
     */
    public function lockQuote(CurrencyCode $fiat): array
    {
        $rate = $this->latestRate($fiat);
        $units = $this->unitsPerUsdc($fiat);
        $quotedAt = now();
        $expiresAt = $quotedAt->copy()->addMinutes(15);

        return [
            'quote_id' => (string) Str::uuid(),
            'base' => 'USDC',
            'quote' => $fiat->value,
            'units_per_usdc' => $units,
            'provider' => $rate?->provider ?? 'fallback',
            'quoted_at' => $quotedAt->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    public function formatDual(int $usdcBaseUnits, CurrencyCode $fiat): string
    {
        $usdc = number_format($usdcBaseUnits / 1000000, 2).' USDC';

        if ($fiat === CurrencyCode::USDC) {
            return $usdc;
        }

        $fiatMinor = $this->usdcToFiat($usdcBaseUnits, $fiat);
        $fiatFormatted = number_format($fiatMinor / 100, 2).' '.$fiat->value;

        return $usdc.' ≈ '.$fiat->symbol().$fiatFormatted;
    }

    public function formatUsdc(int $baseUnits): string
    {
        return number_format($baseUnits / 1000000, 6).' USDC';
    }
}
