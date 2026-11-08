<?php

declare(strict_types=1);

namespace Tests\Unit\Prioritization;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use App\Domain\Prioritization\ExplanationBuilder;
use App\Domain\Prioritization\PrioritizationEngine;
use App\Domain\Prioritization\RankingParameters;
use App\Domain\Prioritization\ScenarioSet;
use App\Domain\Prioritization\ScoredTask;
use App\Domain\Prioritization\TaskInput;
use App\Domain\Prioritization\Weights;

/**
 * FR8: contributions sum to the score and the contrastive explanation names
 * the criteria with the largest positive and negative differences.
 */
final class ExplanationBuilderTest extends TestCase
{
    /** @var list<ScoredTask> */
    private array $ranked;
    private ExplanationBuilder $builder;

    protected function setUp(): void
    {
        $now = new DateTimeImmutable('2026-11-09 09:00:00');
        $this->ranked = (new PrioritizationEngine())->rank(ScenarioSet::inputs($now), $now, RankingParameters::defaults());
        $this->builder = new ExplanationBuilder();
    }

    public function testSummaryOfTopTaskMatchesSection355(): void
    {
        self::assertSame(
            'Score 70.9: importance contributed 42.0 points, deadline proximity 12.4, urgency 11.5 and workload pressure 5.0.',
            $this->builder->summary($this->ranked[0]),
        );
    }

    public function testContrastiveExplanationOfT1AgainstT3(): void
    {
        self::assertSame('T3 Group project meeting slides', $this->ranked[1]->task->title);
        self::assertSame(
            "Above T3 Group project meeting slides mainly because it is more important (+21.0 points), although T3 Group project meeting slides is more urgent (\u{2212}11.5), is due sooner (\u{2212}7.7) and is slightly more time-pressed (\u{2212}1.0).",
            $this->builder->contrast($this->ranked[0], $this->ranked[1]),
        );
    }

    public function testDeltasSumExactlyToTheScoreGap(): void
    {
        for ($k = 0; $k < count($this->ranked) - 1; $k++) {
            $explanation = $this->builder->explain($this->ranked, $k);
            self::assertEqualsWithDelta($explanation['gap'], array_sum($explanation['deltas']), 1e-9);
            self::assertGreaterThanOrEqual(0, $explanation['gap']);
        }
    }

    public function testLastTaskHasNoContrast(): void
    {
        $explanation = $this->builder->explain($this->ranked, count($this->ranked) - 1);

        self::assertNull($explanation['contrast']);
        self::assertStringStartsWith('Score 25.1:', $explanation['summary']);
    }

    public function testEveryContrastStartsWithAboveOrLevel(): void
    {
        for ($k = 0; $k < count($this->ranked) - 1; $k++) {
            self::assertMatchesRegularExpression('/^(Above|Level with) /', (string) $this->builder->explain($this->ranked, $k)['contrast']);
        }
    }

    public function testTieIsExplainedByTheTieBreaker(): void
    {
        $now = new DateTimeImmutable('2026-11-09 09:00:00');
        $params = RankingParameters::defaults()->withWeights(Weights::fromArray(['I' => 1, 'U' => 0, 'D' => 0, 'P' => 0]));
        $a = new TaskInput('a', 'Essay', 4, 2, $now->modify('+2 days'), 2.0, $now);
        $b = new TaskInput('b', 'Poster', 4, 2, $now->modify('+5 days'), 2.0, $now);
        $ranked = (new PrioritizationEngine())->rank([$b, $a], $now, $params);

        self::assertSame('Level with Poster on score; placed above it because it is due earlier.', $this->builder->contrast($ranked[0], $ranked[1]));
    }
}
