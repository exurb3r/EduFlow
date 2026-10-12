<?php

declare(strict_types=1);

namespace App\Services;

use Brick\Math\BigInteger;
use Yukazakiri\Lepton\Support\Amounts;

/**
 * Non-throwing readers for JSON-RPC hex quantities.
 *
 * `arc-canteen` and `circle` are CLIs, so every chain read here is text captured
 * from a subprocess pipe — and a CLI will happily colour that text. Anything that
 * forces colour on (FORCE_COLOR=1, a pty on the parent) makes `arc-canteen rpc
 * eth_blockNumber` answer `\e[1;36m0x3fcb62f\e[0m` instead of `0x3fcb62f`.
 * `Amounts::fromHexQuantity()` rejects that, and because the unwrapped value
 * reached it from an unguarded call site it threw
 * `Invalid hex quantity [\e[1;36m0x3fcb62f\e[0m]` and returned HTTP 500 for the
 * whole admin dashboard.
 *
 * So treat CLI output as untrusted: strip the decoration first, and report
 * anything still not a plain `0x…` quantity as *unavailable* rather than as a
 * reason to fail the page. Nothing in here throws.
 *
 * @see LeptonTreasuryService::status() — the caller that reads a
 *      block number on every dashboard render, which is where this surfaced.
 */
final class ChainQuantity
{
    /**
     * CSI/SGR colour escapes (`\e[1;36m`) and OSC sequences closed by BEL or ST.
     */
    private const ESCAPES = "/\e\[[0-?]*[ -\/]*[@-~]|\e\][^\a\e]*(?:\a|\e\\\\)/";

    /**
     * The bare `0x…` quantity behind whatever the CLI printed, or null when the
     * payload is not one. Handles ANSI colour, quoting, padding and CRLF.
     */
    public static function normalize(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $stripped = preg_replace(self::ESCAPES, '', $raw) ?? $raw;
        $value = trim($stripped, " \t\n\r\0\x0B\"'");

        // Case-insensitive, like Amounts itself: an uppercase quantity is valid.
        return preg_match('/^0x[0-9a-f]+$/Di', $value) === 1 ? $value : null;
    }

    /**
     * Exact decimal rendering at $decimals scale, or null when unreadable.
     *
     * String arithmetic throughout, so an 18-decimal value above PHP_INT_MAX
     * stays precise.
     */
    public static function toDecimalString(mixed $raw, int $decimals = 0): ?string
    {
        $value = self::normalize($raw);

        if ($value === null) {
            return null;
        }

        try {
            return Amounts::fromHexQuantity($value, $decimals);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Block numbers and chain ids as int, or null when unreadable.
     *
     * Null rather than a wrapped negative when the quantity exceeds PHP_INT_MAX:
     * an (int) cast of an out-of-range string is exactly the silent corruption
     * `Amounts` exists to prevent.
     */
    public static function toInt(mixed $raw): ?int
    {
        $decimal = self::toDecimalString($raw);

        if ($decimal === null || BigInteger::of($decimal)->isGreaterThan(PHP_INT_MAX)) {
            return null;
        }

        return (int) $decimal;
    }
}
