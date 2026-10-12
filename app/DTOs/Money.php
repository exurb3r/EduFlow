<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\CurrencyCode;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\Exception\IntegerOverflowException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use JsonSerializable;
use OverflowException;

final readonly class Money implements JsonSerializable
{
    public function __construct(public int $minorUnits, public CurrencyCode $currency) {}

    public function toBaseUnits(): int
    {
        return $this->minorUnits;
    }

    public static function fromDecimal(string $amount, CurrencyCode $currency): self
    {
        $decimals = $currency->decimals();

        if (! preg_match('/^-?\d+(?:\.\d{1,'.$decimals.'})?$/D', $amount)) {
            throw new InvalidArgumentException('Amount must be a plain decimal string within the currency precision.');
        }

        $negative = str_starts_with($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '-'), 2), 2, '');
        $units = BigInteger::of($whole)->multipliedBy($currency->multiplier())
            ->plus(BigInteger::of(str_pad($fraction, $decimals, '0')));

        if ($negative) {
            $units = $units->negated();
        }

        return new self(self::checkedInteger($units), $currency);
    }

    public function decimal(): string
    {
        return (string) BigDecimal::ofUnscaledValue($this->minorUnits, $this->currency->decimals());
    }

    public function format(?int $displayDecimals = null): string
    {
        $displayDecimals ??= $this->currency->decimals();

        if ($displayDecimals < 0 || $displayDecimals > $this->currency->decimals()) {
            throw new InvalidArgumentException('Display precision must be within currency precision.');
        }

        $rounded = (string) BigDecimal::ofUnscaledValue($this->minorUnits, $this->currency->decimals())
            ->toScale($displayDecimals, RoundingMode::HalfUp);
        $negative = str_starts_with($rounded, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($rounded, '-'), 2), 2, '');
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);

        return ($negative ? '-' : '').$grouped.($displayDecimals === 0 ? '' : '.'.$fraction);
    }

    /**
     * Format exact base units that may exceed one Money integer.
     *
     * Aggregates (headroom, cumulative holds, realised receipts) are summed
     * outside this DTO and can outrun PHP_INT_MAX without losing precision,
     * so dashboards must be able to render them without a float detour.
     * Anything that is not a plain integer string is refused rather than
     * guessed at: a wrong figure on a finance screen is worse than none.
     */
    public static function formatExact(mixed $minorUnits, mixed $currency): string
    {
        $code = is_string($currency) ? CurrencyCode::tryFrom($currency) : null;

        if ($code === null || (! is_string($minorUnits) && ! is_int($minorUnits)) || preg_match('/^-?(?:0|[1-9][0-9]*)$/D', (string) $minorUnits) !== 1) {
            return 'Exact amount unavailable';
        }

        $integer = BigInteger::of($minorUnits);

        try {
            return (new self($integer->toInt(), $code))->format().' '.$code->value;
        } catch (IntegerOverflowException) {
            $decimal = (string) BigDecimal::ofUnscaledValue($integer, $code->decimals());
            [$whole, $fraction] = explode('.', $decimal, 2);

            return preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole).'.'.$fraction.' '.$code->value;
        }
    }

    /**
     * Convert exact base units to a float for charting only.
     *
     * Chart.js cannot render an integer string, so a chart eventually has to
     * cross into float. Doing that once, here, keeps the conversion auditable
     * and named: the exact figure remains authoritative in the tables beside
     * the chart, and this value is never stored, persisted or authorized.
     */
    public static function toChartValue(mixed $minorUnits, mixed $currency): float
    {
        $code = is_string($currency) ? CurrencyCode::tryFrom($currency) : null;

        if ($code === null || (! is_string($minorUnits) && ! is_int($minorUnits)) || preg_match('/^-?(?:0|[1-9][0-9]*)$/D', (string) $minorUnits) !== 1) {
            return 0.0;
        }

        $units = BigInteger::of($minorUnits);

        if ($code->decimals() === 0) {
            return (float) $units->toInt();
        }

        return (float) (string) BigDecimal::ofUnscaledValue($units, $code->decimals());
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(self::checkedInteger(BigInteger::of($this->minorUnits)->plus($other->minorUnits)), $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(self::checkedInteger(BigInteger::of($this->minorUnits)->minus($other->minorUnits)), $this->currency);
    }

    /** @return array{minor_units: string, currency: string, decimal: string} */
    public function jsonSerialize(): array
    {
        return ['minor_units' => (string) $this->minorUnits, 'currency' => $this->currency->value, 'decimal' => $this->decimal()];
    }

    private static function checkedInteger(BigInteger $units): int
    {
        try {
            return $units->toInt();
        } catch (IntegerOverflowException $exception) {
            throw new OverflowException('Money exceeds supported signed integer range.', $exception->getCode(), previous: $exception);
        }
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException('Amounts in different currencies cannot be combined without a quote.');
        }
    }
}
