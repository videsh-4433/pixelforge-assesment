<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * All money in the app is an integer number of nanodollars (1 USD = 1,000,000,000 nano).
 *
 * Pixelforge reports costs as exact decimal strings with 9 decimal places, so nanodollars
 * represent every provider cost exactly, and integer maths never loses precision (floats would).
 */
final class Money
{
    public const NANO_PER_DOLLAR = 1_000_000_000;

    /** "0.046125000" -> 46125000. Rejects anything that isn't exactly representable. */
    public static function decimalToNano(string $decimal): int
    {
        if (! preg_match('/^(-)?(\d+)(?:\.(\d{1,9}))?$/', trim($decimal), $m)) {
            throw new InvalidArgumentException("Not an exact USD amount with at most 9 decimals: [{$decimal}]");
        }

        $nano = (int) $m[2] * self::NANO_PER_DOLLAR + (int) str_pad($m[3] ?? '', 9, '0');

        return $m[1] === '-' ? -$nano : $nano;
    }

    /** 46125000 -> "0.046125000" (same format Pixelforge uses). */
    public static function nanoToDecimal(int $nano): string
    {
        $sign = $nano < 0 ? '-' : '';
        $abs = abs($nano);

        return $sign.intdiv($abs, self::NANO_PER_DOLLAR).'.'.str_pad((string) ($abs % self::NANO_PER_DOLLAR), 9, '0', STR_PAD_LEFT);
    }

    /** Integer division that rounds up (towards +infinity) for non-negative numerators. */
    public static function ceilDiv(int $numerator, int $denominator): int
    {
        return intdiv($numerator + $denominator - 1, $denominator);
    }
}
