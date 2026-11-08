<?php

declare(strict_types=1);

namespace App\Domain\Prioritization;

/**
 * Half-up rounding that is robust to binary floating-point noise, so that a
 * score such as 45.85 (stored as 45.849999...) rounds to 45.9 as Table 3.4
 * requires. Negative values round symmetrically (half away from zero).
 */
final class Rounding
{
    public static function halfUp(float $value, int $places = 1): float
    {
        $factor = 10 ** $places;
        // First remove representation noise far below the displayed precision.
        $scaled = round($value * $factor, 6);
        $rounded = $scaled >= 0 ? floor($scaled + 0.5) : -floor(-$scaled + 0.5);
        $result = $rounded / $factor;

        return $result == 0.0 ? 0.0 : $result; // normalise -0.0
    }

    public static function format(float $value, int $places = 1): string
    {
        return number_format(self::halfUp($value, $places), $places, '.', '');
    }

    /** Signed format with a typographic minus, e.g. "+21.0" or "−11.5". */
    public static function signed(float $value, int $places = 1): string
    {
        $rounded = self::halfUp($value, $places);
        $text = number_format(abs($rounded), $places, '.', '');

        return ($rounded < 0 ? "\u{2212}" : '+') . $text;
    }
}
