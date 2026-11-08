<?php

declare(strict_types=1);

namespace Tests\Unit\Prioritization;

use PHPUnit\Framework\TestCase;
use App\Domain\Prioritization\AhpCalculator;
use App\Domain\Prioritization\Rounding;
use App\Domain\Prioritization\WeightAdjuster;
use App\Domain\Prioritization\Weights;

/**
 * Algorithm 3.1 (Table 3.3) and FR9 (proportional weight redistribution).
 */
final class AhpAndWeightsTest extends TestCase
{
    public function testAhpReproducesTable33(): void
    {
        $result = (new AhpCalculator())->derive(AhpCalculator::velocityMatrix());

        self::assertSame([0.42, 0.23, 0.23, 0.12], array_map(static fn ($w) => Rounding::halfUp($w, 2), $result['weights']));
        self::assertEqualsWithDelta(4.010, $result['lambdaMax'], 0.0005);
        self::assertEqualsWithDelta(0.0035, $result['ci'], 0.0002);
        self::assertSame(0.004, Rounding::halfUp($result['cr'], 3));
        self::assertTrue($result['consistent']);
    }

    public function testAhpRejectsNonReciprocalMatrix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AhpCalculator())->derive([[1, 3], [3, 1]]);
    }

    public function testDefaultWeightsAreTheAhpDefaults(): void
    {
        self::assertSame(['I' => 42, 'U' => 23, 'D' => 23, 'P' => 12], Weights::defaults()->toPercentages());
        self::assertTrue(Weights::defaults()->isDefault());
    }

    public function testMovingOneSliderRedistributesProportionally(): void
    {
        $adjusted = WeightAdjuster::adjust(['I' => 42, 'U' => 23, 'D' => 23, 'P' => 12], 'I', 60);

        self::assertSame(60, $adjusted['I']);
        self::assertSame(100, array_sum($adjusted));
        // 40 points shared 23 : 23 : 12 among the others.
        self::assertSame(['I' => 60, 'U' => 16, 'D' => 16, 'P' => 8], $adjusted);
    }

    public function testRedistributionFromZeroSharesEqually(): void
    {
        $adjusted = WeightAdjuster::adjust(['I' => 100, 'U' => 0, 'D' => 0, 'P' => 0], 'I', 70);

        self::assertSame(['I' => 70, 'U' => 10, 'D' => 10, 'P' => 10], $adjusted);
    }

    public function testRedistributionAlwaysSumsToHundred(): void
    {
        mt_srand(7);
        $weights = ['I' => 42, 'U' => 23, 'D' => 23, 'P' => 12];
        for ($i = 0; $i < 500; $i++) {
            $key = ['I', 'U', 'D', 'P'][mt_rand(0, 3)];
            $weights = WeightAdjuster::adjust($weights, $key, mt_rand(0, 100));
            self::assertSame(100, array_sum($weights));
            foreach ($weights as $value) {
                self::assertGreaterThanOrEqual(0, $value);
            }
        }
    }

    public function testWeightsAreNormalised(): void
    {
        $weights = Weights::fromArray(['I' => 2, 'U' => 1, 'D' => 1, 'P' => 0]);

        self::assertEqualsWithDelta(0.5, $weights->get('I'), 1e-12);
        self::assertSame(['I' => 50, 'U' => 25, 'D' => 25, 'P' => 0], $weights->toPercentages());
    }

    public function testAllZeroWeightsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Weights::fromArray(['I' => 0, 'U' => 0, 'D' => 0, 'P' => 0]);
    }

    public function testHalfUpRounding(): void
    {
        self::assertSame(45.9, Rounding::halfUp(21.0 + 5.75 + 0.23 * 0.7 * 100 + 3.0));
        self::assertSame(17.3, Rounding::halfUp(0.23 * 0.75 * 100));
        self::assertSame(-11.5, Rounding::halfUp(-11.5));
        self::assertSame("\u{2212}7.7", Rounding::signed(-7.74));
        self::assertSame('+21.0', Rounding::signed(21.0));
    }
}
