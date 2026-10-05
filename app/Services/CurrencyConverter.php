<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\CurrencyRate;
use Brick\Math\BigInteger;
use Brick\Math\Exception\IntegerOverflowException;
use Brick\Math\RoundingMode;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OverflowException;
use RuntimeException;

class CurrencyConverter
{
    /** @return array<string, int> */
    public static function fallbackRates(): array
    {
        return ['USD' => 100, 'PHP' => 5750, 'EUR' => 92, 'GBP' => 79, 'CAD' => 136, 'SGD' => 134, 'INR' => 8300];
    }

    public function latestRate(CurrencyCode $fiat): ?CurrencyRate
    {
        if ($fiat === CurrencyCode::USDC) {
            return null;
        }

        /** @var CurrencyRate|null $rate */
        $rate = CurrencyRate::query()
            ->where('base_code', 'USDC')->where('quote_code', $fiat->value)
            ->where('units_per_usdc', '>', 0)
            ->where('quoted_at', '<=', now())
            ->where('quoted_at', '>', now()->subSeconds($this->maxRateAgeSeconds()))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('quoted_at')->orderByDesc('id')->first();

        return $rate;
    }

    /** Indicative display conversion only; not a guarantee of an executable exchange. */
    public function unitsPerUsdc(CurrencyCode $fiat): int
    {
        if ($fiat === CurrencyCode::USDC) {
            return CurrencyCode::USDC->multiplier();
        }

        $rate = $this->latestRate($fiat);

        return $rate instanceof CurrencyRate ? $rate->units_per_usdc : self::fallbackRates()[$fiat->value];
    }

    public function usdcToFiat(int $usdcBaseUnits, CurrencyCode $fiat): int
    {
        return $this->convert($usdcBaseUnits, $this->unitsPerUsdc($fiat), CurrencyCode::USDC->multiplier());
    }

    public function fiatToUsdc(int $fiatMinorUnits, CurrencyCode $fiat): int
    {
        return $this->convert($fiatMinorUnits, CurrencyCode::USDC->multiplier(), $this->unitsPerUsdc($fiat));
    }

    /**
     * Snapshots an indicative rate without extending its source validity.
     *
     * @return array{quote_id: string, base: string, quote: string, units_per_usdc: int, provider: string, quoted_at: string, expires_at: string, locked_at: string, source_rate_id: ?int, indicative: bool}
     */
    public function lockQuote(CurrencyCode $fiat): array
    {
        $rate = $this->latestRate($fiat);
        $lockedAt = now();
        $quotedAt = $rate instanceof CurrencyRate ? $rate->quoted_at : $lockedAt;
        $expiresAt = $lockedAt->copy()->addSeconds($this->maxRateAgeSeconds());

        if ($rate instanceof CurrencyRate) {
            $maximumSourceExpiry = $rate->quoted_at->copy()->addSeconds($this->maxRateAgeSeconds());
            $sourceExpiry = $rate->expires_at;
            $expiresAt = $sourceExpiry !== null && $sourceExpiry->lt($maximumSourceExpiry) ? $sourceExpiry : $maximumSourceExpiry;
        }

        return [
            'quote_id' => (string) Str::uuid(),
            'base' => 'USDC',
            'quote' => $fiat->value,
            'units_per_usdc' => $fiat === CurrencyCode::USDC ? CurrencyCode::USDC->multiplier() : ($rate instanceof CurrencyRate ? $rate->units_per_usdc : self::fallbackRates()[$fiat->value]),
            'provider' => $rate instanceof CurrencyRate ? ($rate->provider) : ($fiat === CurrencyCode::USDC ? 'identity' : 'fallback'),
            'quoted_at' => $quotedAt->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
            'locked_at' => $lockedAt->toIso8601String(),
            'source_rate_id' => $rate?->id,
            'indicative' => $fiat !== CurrencyCode::USDC,
        ];
    }

    /**
     * Non-fallback fresh evidence for a future reviewed FX adapter, not an executable offer.
     *
     * @return array<string, mixed>
     */
    public function requireFreshQuote(CurrencyCode $fiat): array
    {
        $quote = $this->lockQuote($fiat);

        if ($fiat === CurrencyCode::USDC) {
            return $quote;
        }

        $rate = $quote['source_rate_id'] === null ? null : CurrencyRate::query()->find($quote['source_rate_id']);

        if (! $rate instanceof CurrencyRate || $rate->is_fallback || $rate->expires_at === null || $rate->isExpired()
            || trim($rate->provider) === '' || $rate->provider === 'fallback') {
            throw new RuntimeException('A fresh non-fallback currency rate with source expiry is required.');
        }

        return $quote;
    }

    public function formatDual(int $usdcBaseUnits, CurrencyCode $fiat): string
    {
        $usdc = (new Money($usdcBaseUnits, CurrencyCode::USDC))->format(2).' USDC';

        if ($fiat === CurrencyCode::USDC) {
            return $usdc;
        }

        return $usdc.' ≈ '.$fiat->symbol().(new Money($this->usdcToFiat($usdcBaseUnits, $fiat), $fiat))->format().' '.$fiat->value;
    }

    public function formatUsdc(int $baseUnits): string
    {
        return (new Money($baseUnits, CurrencyCode::USDC))->format().' USDC';
    }

    private function convert(int $amount, int $multiplier, int $divisor): int
    {
        if ($multiplier <= 0 || $divisor <= 0) {
            throw new InvalidArgumentException('Currency rate must be positive.');
        }

        try {
            return BigInteger::of($amount)->multipliedBy($multiplier)->dividedBy($divisor, RoundingMode::Down)->toInt();
        } catch (IntegerOverflowException $exception) {
            throw new OverflowException('Converted amount exceeds supported signed integer range.', $exception->getCode(), previous: $exception);
        }
    }

    private function maxRateAgeSeconds(): int
    {
        return max(1, (int) config('eduflow.max_rate_age_seconds', 900));
    }
}
