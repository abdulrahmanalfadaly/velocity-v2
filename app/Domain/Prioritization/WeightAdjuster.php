<?php

declare(strict_types=1);

namespace App\Domain\Prioritization;

/**
 * FR9 – moving one weight slider redistributes the difference proportionally
 * among the other three so that the weights always sum to 100%.
 *
 * Works on whole percentages; largest-remainder rounding keeps the total at
 * exactly 100. The same rule is mirrored in the browser (app.js).
 */
final class WeightAdjuster
{
    /**
     * @param array<string, int> $current whole percentages keyed by I, U, D, P (sum 100)
     * @return array<string, int>
     */
    public static function adjust(array $current, string $changed, int $newValue): array
    {
        Criteria::assertKey($changed);
        $newValue = max(0, min(100, $newValue));
        $remaining = 100 - $newValue;

        $others = array_values(array_filter(Criteria::KEYS, static fn (string $k) => $k !== $changed));
        $othersSum = 0;
        foreach ($others as $key) {
            $othersSum += max(0, (int) ($current[$key] ?? 0));
        }

        $raw = [];
        foreach ($others as $key) {
            $raw[$key] = $othersSum > 0
                ? max(0, (int) ($current[$key] ?? 0)) * $remaining / $othersSum
                : $remaining / count($others);
        }

        $distributed = self::largestRemainder($raw, $remaining);
        $result = [];
        foreach (Criteria::KEYS as $key) {
            $result[$key] = $key === $changed ? $newValue : $distributed[$key];
        }

        return $result;
    }

    /**
     * Scales any non-negative values to whole percentages summing to 100.
     *
     * @param array<string, float|int> $values
     * @return array<string, int>
     */
    public static function normaliseToHundred(array $values): array
    {
        $sum = array_sum($values);
        if ($sum <= 0) {
            throw new \InvalidArgumentException('At least one weight must be greater than zero.');
        }
        $raw = [];
        foreach ($values as $key => $value) {
            $raw[$key] = max(0, $value) * 100 / $sum;
        }

        return self::largestRemainder($raw, 100);
    }

    /**
     * @param array<string, float> $raw
     * @return array<string, int>
     */
    private static function largestRemainder(array $raw, int $total): array
    {
        $floors = [];
        $remainders = [];
        $order = 0;
        foreach ($raw as $key => $value) {
            $value = round($value, 9);
            $floors[$key] = (int) floor($value);
            $remainders[] = [$key, $value - floor($value), $order++];
        }
        $left = $total - array_sum($floors);
        usort($remainders, static fn (array $a, array $b) => [$b[1], $a[2]] <=> [$a[1], $b[2]]);
        for ($i = 0; $i < $left && $i < count($remainders); $i++) {
            $floors[$remainders[$i][0]]++;
        }

        return $floors;
    }
}
