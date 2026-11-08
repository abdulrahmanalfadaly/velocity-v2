<?php

declare(strict_types=1);

namespace Tests\Unit\Prioritization;

use PHPUnit\Framework\TestCase;
use App\Domain\Evaluation\Statistics;

final class StatisticsTest extends TestCase
{
    public function testIdenticalOrderingsCorrelatePerfectly(): void
    {
        self::assertSame(1.0, Statistics::spearman(['a', 'b', 'c', 'd'], ['a', 'b', 'c', 'd']));
    }

    public function testReversedOrderingsCorrelateNegatively(): void
    {
        self::assertSame(-1.0, Statistics::spearman(['a', 'b', 'c', 'd'], ['d', 'c', 'b', 'a']));
    }

    public function testKnownValue(): void
    {
        // One adjacent swap among 10 items: rho = 1 - 6*2/(10*99) = 0.98788
        $a = range(1, 10);
        $b = [2, 1, 3, 4, 5, 6, 7, 8, 9, 10];
        self::assertEqualsWithDelta(0.98788, Statistics::spearman($a, $b), 1e-5);
    }

    public function testTopOverlap(): void
    {
        self::assertSame(2, Statistics::topOverlap(['T1', 'T3', 'T10', 'T6'], ['T3', 'T6', 'T1', 'T10']));
    }

    public function testMismatchedItemsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Statistics::spearman(['a', 'b'], ['a', 'c']);
    }
}
