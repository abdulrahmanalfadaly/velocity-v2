<?php

declare(strict_types=1);

namespace App\Domain\Evaluation;

/**
 * Statistics used in the evaluation (Section 3.6.5): Spearman's rank
 * correlation between two orderings of the same items and top-k overlap.
 */
final class Statistics
{
    /**
     * Spearman's ρ between two strict orderings of the same items
     * (ρ = 1 − 6 Σ d² / (n (n² − 1))).
     *
     * @param list<int|string> $orderA items, best first
     * @param list<int|string> $orderB the same items, best first
     */
    public static function spearman(array $orderA, array $orderB): float
    {
        $n = count($orderA);
        if ($n !== count($orderB) || $n < 2) {
            throw new \InvalidArgumentException('Both orderings must contain the same two or more items.');
        }
        $rankB = array_flip(array_map('strval', $orderB));
        $sum = 0;
        foreach ($orderA as $i => $item) {
            $key = (string) $item;
            if (!isset($rankB[$key])) {
                throw new \InvalidArgumentException("Item '{$key}' is missing from the second ordering.");
            }
            $d = $i - $rankB[$key];
            $sum += $d * $d;
        }

        return 1 - (6 * $sum) / ($n * ($n * $n - 1));
    }

    /**
     * Number of items shared by the first $k of both orderings.
     *
     * @param list<int|string> $orderA
     * @param list<int|string> $orderB
     */
    public static function topOverlap(array $orderA, array $orderB, int $k = 3): int
    {
        return count(array_intersect(
            array_map('strval', array_slice($orderA, 0, $k)),
            array_map('strval', array_slice($orderB, 0, $k)),
        ));
    }

    /** @param list<float> $values */
    public static function mean(array $values): float
    {
        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }

    /** @param list<float> $values */
    public static function standardDeviation(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }
        $mean = self::mean($values);
        $sum = 0.0;
        foreach ($values as $value) {
            $sum += ($value - $mean) ** 2;
        }

        return sqrt($sum / ($n - 1));
    }

    /** @param list<float> $values */
    public static function percentile(array $values, float $p): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $index = ($p / 100) * (count($values) - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        return $values[$lower] + ($values[$upper] - $values[$lower]) * ($index - $lower);
    }
}
