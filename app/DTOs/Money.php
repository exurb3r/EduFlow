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
            throw new OverflowException('Money exceeds supported signed integer range.', previous: $exception);
        }
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException('Amounts in different currencies cannot be combined without a quote.');
        }
    }
}
