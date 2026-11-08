<?php

declare(strict_types=1);

namespace App\Domain\Prioritization;

/**
 * Algorithm 3.1 – DeriveWeightsAHP: geometric-mean approximation of the
 * principal eigenvector of a pairwise comparison matrix, with Saaty's
 * consistency index and ratio.
 */
final class AhpCalculator
{
    /** Random consistency indices (Saaty, 1980). */
    public const RANDOM_INDEX = [1 => 0.0, 2 => 0.0, 3 => 0.58, 4 => 0.90, 5 => 1.12, 6 => 1.24, 7 => 1.32, 8 => 1.41, 9 => 1.45];

    public const CONSISTENCY_THRESHOLD = 0.10;

    /**
     * The pairwise comparison matrix of Table 3.3 (rows/columns I, U, D, P).
     *
     * @return list<list<float>>
     */
    public static function velocityMatrix(): array
    {
        return [
            [1, 2, 2, 3],
            [1 / 2, 1, 1, 2],
            [1 / 2, 1, 1, 2],
            [1 / 3, 1 / 2, 1 / 2, 1],
        ];
    }

    /**
     * @param list<list<float|int>> $matrix n × n reciprocal matrix on Saaty's 1–9 scale
     * @return array{weights: list<float>, lambdaMax: float, ci: float, cr: float, consistent: bool}
     */
    public function derive(array $matrix): array
    {
        $n = count($matrix);
        if ($n < 1 || !isset(self::RANDOM_INDEX[$n])) {
            throw new \InvalidArgumentException('The matrix must have between 1 and 9 rows.');
        }
        foreach ($matrix as $i => $row) {
            if (count($row) !== $n) {
                throw new \InvalidArgumentException('The matrix must be square.');
            }
            foreach ($row as $j => $value) {
                if ($value <= 0) {
                    throw new \InvalidArgumentException('Pairwise judgments must be positive.');
                }
                if ($i === $j && abs($value - 1) > 1e-9) {
                    throw new \InvalidArgumentException('Diagonal entries must equal 1.');
                }
                if (abs($value * $matrix[$j][$i] - 1) > 1e-6) {
                    throw new \InvalidArgumentException('The matrix must be reciprocal (a_ji = 1 / a_ij).');
                }
            }
        }

        // Steps 2–4: geometric mean of each row, normalised to sum 1.
        $geometric = [];
        foreach ($matrix as $row) {
            $geometric[] = array_product($row) ** (1 / $n);
        }
        $sum = array_sum($geometric);
        $weights = array_map(static fn (float $g) => $g / $sum, $geometric);

        // Step 5: λmax ≈ mean of (A·w)_i / w_i.
        $ratios = [];
        foreach ($matrix as $i => $row) {
            $aw = 0.0;
            foreach ($row as $j => $value) {
                $aw += $value * $weights[$j];
            }
            $ratios[] = $aw / $weights[$i];
        }
        $lambdaMax = array_sum($ratios) / $n;

        // Steps 6–7.
        $ci = $n > 1 ? ($lambdaMax - $n) / ($n - 1) : 0.0;
        $ri = self::RANDOM_INDEX[$n];
        $cr = $ri > 0 ? $ci / $ri : 0.0;

        return [
            'weights' => $weights,
            'lambdaMax' => $lambdaMax,
            'ci' => $ci,
            'cr' => $cr,
            'consistent' => $cr <= self::CONSISTENCY_THRESHOLD,
        ];
    }
}
