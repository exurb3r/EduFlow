<?php

declare(strict_types=1);

namespace Eduflow\Lepton\Support;

use InvalidArgumentException;

final class Amounts
{
    /**
     * Convert decimal string like "100.50" to base units (100500000 for 6 decimals).
     * Never accepts floats to avoid precision drift.
     */
    public static function fromDecimalString(string $decimal, int $decimals = 6): int
    {
        $decimal = trim($decimal);

        if (! preg_match('/^\d+(\.\d{1,'.$decimals.'})?$/', $decimal)) {
            throw new InvalidArgumentException("Invalid decimal amount [{$decimal}].");
        }

        $parts = explode('.', $decimal);
        $whole = $parts[0];
        $fraction = $parts[1] ?? '';
        $fraction = str_pad(substr($fraction, 0, $decimals), $decimals, '0');

        return ((int) $whole) * (10 ** $decimals) + (int) $fraction;
    }

    public static function toDecimalString(int $baseUnits, int $decimals = 6): string
    {
        $divisor = 10 ** $decimals;
        $whole = intdiv($baseUnits, $divisor);
        $fraction = str_pad((string) ($baseUnits % $divisor), $decimals, '0', STR_PAD_LEFT);

        return $whole.'.'.rtrim($fraction, '0') === $whole.'.' ? $whole.'.0' : $whole.'.'.rtrim($fraction, '0');
    }

    /**
     * Format for `circle` CLI which expects decimal like "100.5", not base units.
     */
    public static function toCliAmount(int $baseUnits, int $decimals = 6): string
    {
        $divisor = 10 ** $decimals;
        $whole = intdiv($baseUnits, $divisor);
        $remainder = $baseUnits % $divisor;

        if ($remainder === 0) {
            return (string) $whole;
        }

        $fraction = rtrim(str_pad((string) $remainder, $decimals, '0', STR_PAD_LEFT), '0');

        return $whole.'.'.$fraction;
    }
}
