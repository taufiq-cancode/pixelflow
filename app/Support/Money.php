<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * All money in PixelFlow is an integer number of nanodollars
 * (1 nanodollar = $0.000000001). Pixelforge reports exact decimal strings
 * with up to nine decimals, so nanodollars represent every amount exactly.
 *
 * Decimal strings are converted with string and integer operations only:
 * floats would drift (see DECISIONS.md §2–3).
 */
final class Money
{
    public const NANOS_PER_DOLLAR = 1_000_000_000;

    /**
     * Converts an exact USD amount such as "0.169080000" to nanodollars.
     */
    public static function usdToNanos(string $usd): int
    {
        return self::parseDecimal($usd, 9);
    }

    /**
     * Pixelforge rates are US dollars per 1M tokens. One token at R dollars
     * per 1M costs R × 1e9 / 1e6 = R × 1000 nanodollars, so "12.50" becomes
     * 12500 nanodollars per token.
     */
    public static function ratePerMillionToNanosPerToken(string $rate): int
    {
        return self::parseDecimal($rate, 3);
    }

    /**
     * Formats nanodollars as an exact nine-decimal USD string, e.g.
     * 169080000 → "0.169080000". Never rounds.
     */
    public static function format(int $nanos): string
    {
        $sign = $nanos < 0 ? '-' : '';
        $absolute = abs($nanos);
        $dollars = intdiv($absolute, self::NANOS_PER_DOLLAR);
        $fraction = str_pad((string) ($absolute % self::NANOS_PER_DOLLAR), 9, '0', STR_PAD_LEFT);

        return "{$sign}{$dollars}.{$fraction}";
    }

    /**
     * Parses a non-negative decimal string into an integer scaled by
     * 10^$scale ("12.50" with scale 3 → 12500). Throws instead of rounding
     * when the value has more precision than the scale can hold.
     */
    private static function parseDecimal(string $value, int $scale): int
    {
        if (preg_match('/^(\d{1,9})(?:\.(\d+))?$/', $value, $matches) !== 1) {
            throw new InvalidArgumentException("\"{$value}\" is not a valid non-negative decimal amount.");
        }

        $whole = $matches[1];
        // Trailing zeros carry no value: "12.5000" is exactly 12.5.
        $fraction = rtrim($matches[2] ?? '', '0');

        if (strlen($fraction) > $scale) {
            throw new InvalidArgumentException(
                "\"{$value}\" has more than {$scale} decimal places and cannot be represented exactly."
            );
        }

        return (int) $whole * 10 ** $scale + (int) str_pad($fraction, $scale, '0');
    }
}
