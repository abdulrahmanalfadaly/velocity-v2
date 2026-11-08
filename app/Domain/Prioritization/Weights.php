<?php

declare(strict_types=1);

namespace App\Domain\Prioritization;

/**
 * Criterion weights (w_I, w_U, w_D, w_P), always summing to 1.
 *
 * The defaults are the AHP-derived weights of Table 3.3, stored rounded to two
 * decimals: 0.42, 0.23, 0.23, 0.12.
 */
final class Weights
{
    public const DEFAULTS = [
        Criteria::IMPORTANCE => 0.42,
        Criteria::URGENCY => 0.23,
        Criteria::DEADLINE => 0.23,
        Criteria::PRESSURE => 0.12,
    ];

    /** @param array<string, float> $values */
    private function __construct(private readonly array $values)
    {
    }

    public static function defaults(): self
    {
        return new self(self::DEFAULTS);
    }

    /**
     * Builds weights from any non-negative values; they are normalised to sum to 1.
     *
     * @param array<string, float|int|string> $values keyed by I, U, D, P
     */
    public static function fromArray(array $values): self
    {
        $clean = [];
        foreach (Criteria::KEYS as $key) {
            if (!array_key_exists($key, $values) || !is_numeric($values[$key])) {
                throw new \InvalidArgumentException("Missing weight for criterion '{$key}'.");
            }
            $value = (float) $values[$key];
            if ($value < 0 || !is_finite($value)) {
                throw new \InvalidArgumentException('Weights must be non-negative numbers.');
            }
            $clean[$key] = $value;
        }
        $sum = array_sum($clean);
        if ($sum <= 0) {
            throw new \InvalidArgumentException('At least one weight must be greater than zero.');
        }
        foreach ($clean as $key => $value) {
            $clean[$key] = $value / $sum;
        }

        return new self($clean);
    }

    /** @param array<string, int> $percentages integers summing to 100 */
    public static function fromPercentages(array $percentages): self
    {
        return self::fromArray($percentages);
    }

    public function get(string $key): float
    {
        Criteria::assertKey($key);

        return $this->values[$key];
    }

    /** @return array<string, float> */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * Whole percentages that sum to exactly 100 (largest-remainder rounding).
     *
     * @return array<string, int>
     */
    public function toPercentages(): array
    {
        return WeightAdjuster::normaliseToHundred(array_map(fn (float $w) => $w * 100, $this->values));
    }

    public function isDefault(): bool
    {
        foreach (self::DEFAULTS as $key => $value) {
            if (abs($this->values[$key] - $value) > 0.0005) {
                return false;
            }
        }

        return true;
    }
}
