<?php

declare(strict_types=1);

namespace App\Panel;

/** Helpers for fixed-scale decimals (numeric columns are strings in Doctrine, JSON numbers on the wire). */
final class Decimal
{
    private function __construct() {}

    public static function format(float $value, int $scale): string
    {
        return number_format($value, $scale, '.', '');
    }

    /**
     * The value rounded to $scale decimal places, or null when it has more places than that
     * (beyond float noise): no silent rounding of client numbers.
     */
    public static function exact(float $value, int $scale): ?float
    {
        $rounded = round($value, $scale);

        return abs($value - $rounded) > 1e-9 ? null : $rounded;
    }
}
