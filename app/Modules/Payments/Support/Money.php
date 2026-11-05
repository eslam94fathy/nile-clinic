<?php

namespace App\Modules\Payments\Support;

/**
 * Exact money (research R2). Amounts are stored as DECIMAL and returned as strings; all arithmetic is
 * on whole piastres, because floats drift (0.1 + 0.2 != 0.3). Validation has already limited amounts
 * to two decimals, so nothing here validates. Stored amounts are never negative, but a report's net
 * can be, so fromPiastres keeps the sign (feature 010, research R5).
 */
final class Money
{
    /**
     * Parses the digits; never multiplies a float by 100, which drifts (19.99 * 100 != 1999).
     */
    public static function toPiastres(string|int|float $amount): int
    {
        $parts = explode('.', (string) $amount, 2);
        $fraction = substr(str_pad($parts[1] ?? '', 2, '0'), 0, 2);

        return (int) $parts[0] * 100 + (int) $fraction;
    }

    public static function fromPiastres(int $piastres): string
    {
        return sprintf('%s%d.%02d', $piastres < 0 ? '-' : '', intdiv(abs($piastres), 100), abs($piastres) % 100);
    }

    /**
     * What the charge costs after its discount.
     */
    public static function due(string $fee, string $discount): string
    {
        return self::fromPiastres(self::toPiastres($fee) - self::toPiastres($discount));
    }

    /**
     * What is still owed.
     */
    public static function balance(string $fee, string $discount, string $paid): string
    {
        return self::fromPiastres(self::toPiastres($fee) - self::toPiastres($discount) - self::toPiastres($paid));
    }
}
