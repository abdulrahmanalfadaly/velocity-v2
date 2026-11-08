<?php

declare(strict_types=1);

namespace Tests\Unit\Prioritization;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use App\Domain\Prioritization\Criteria;
use App\Domain\Prioritization\PrioritizationEngine;
use App\Domain\Prioritization\RankingParameters;
use App\Domain\Prioritization\Rounding;
use App\Domain\Prioritization\ScenarioSet;
use App\Domain\Prioritization\ScoredTask;
use App\Domain\Prioritization\TaskInput;
use App\Domain\Prioritization\Weights;

/**
 * FR4–FR6: the engine reproduces the worked example of Table 3.4.
 */
final class PrioritizationEngineTest extends TestCase
{
    private DateTimeImmutable $now;
    private PrioritizationEngine $engine;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-11-09 09:00:00');
        $this->engine = new PrioritizationEngine();
    }

    /** @return list<ScoredTask> */
    private function rankScenario(): array
    {
        return $this->engine->rank(ScenarioSet::inputs($this->now), $this->now, RankingParameters::defaults());
    }

    public function testScenarioOrderMatchesTable34(): void
    {
        $codes = array_map(static fn (ScoredTask $t) => $t->task->id, $this->rankScenario());
        self::assertSame(ScenarioSet::EXPECTED_ORDER, $codes);
    }

    public function testScenarioScoresMatchTable34(): void
    {
        foreach ($this->rankScenario() as $task) {
            self::assertSame(
                ScenarioSet::EXPECTED_SCORES[$task->task->id],
                $task->displayScore(),
                "Score of {$task->task->id}",
            );
        }
    }

    public function testRanksAreAssignedOneToN(): void
    {
        self::assertSame(range(1, 10), array_map(static fn (ScoredTask $t) => $t->rank, $this->rankScenario()));
    }

    /** @return iterable<string, array{string, array<string, float>, array<string, float>}> */
    public static function table34Rows(): iterable
    {
        // code => [c_I, c_U, c_D, c_P], [K_I, K_U, K_D, K_P]
        yield 'T1' => ['T1', [1.00, 0.50, 0.54, 0.42], [42.0, 11.5, 12.4, 5.0]];
        yield 'T3' => ['T3', [0.50, 1.00, 0.88, 0.50], [21.0, 23.0, 20.1, 6.0]];
        yield 'T10' => ['T10', [0.75, 0.75, 0.78, 0.13], [31.5, 17.3, 17.9, 1.5]];
        yield 'T6' => ['T6', [0.25, 1.00, 0.93, 0.06], [10.5, 23.0, 21.5, 0.8]];
        yield 'T4' => ['T4', [0.50, 0.50, 0.64, 0.25], [21.0, 11.5, 14.6, 3.0]];
        yield 'T5' => ['T5', [1.00, 0.00, 0.20, 0.27], [42.0, 0.0, 4.6, 3.2]];
        yield 'T7' => ['T7', [0.50, 0.25, 0.70, 0.25], [21.0, 5.8, 16.1, 3.0]];
        yield 'T8' => ['T8', [0.75, 0.25, 0.33, 0.05], [31.5, 5.8, 7.7, 0.6]];
        yield 'T2' => ['T2', [0.25, 0.25, 0.78, 0.19], [10.5, 5.8, 17.9, 2.3]];
        yield 'T9' => ['T9', [0.25, 0.00, 0.58, 0.10], [10.5, 0.0, 13.4, 1.2]];
    }

    /**
     * @param list<float> $criteria
     * @param list<float> $contributions
     */
    #[DataProvider('table34Rows')]
    public function testCriteriaAndContributionsMatchTable34(string $code, array $criteria, array $contributions): void
    {
        $byCode = [];
        foreach ($this->rankScenario() as $task) {
            $byCode[$task->task->id] = $task;
        }
        $task = $byCode[$code];

        foreach (Criteria::KEYS as $i => $key) {
            self::assertSame($criteria[$i], Rounding::halfUp($task->criterion($key), 2), "c_{$key} of {$code}");
            self::assertSame($contributions[$i], Rounding::halfUp($task->contribution($key), 1), "K_{$key} of {$code}");
        }
    }

    public function testContributionsSumToScore(): void
    {
        foreach ($this->rankScenario() as $task) {
            self::assertEqualsWithDelta($task->score, array_sum($task->contributions), 1e-9);
        }
    }

    public function testOverdueTaskHasMaximalProximityAndPressure(): void
    {
        $task = new TaskInput(1, 'Late', 3, 3, $this->now->modify('-2 hours'), 1.0, $this->now);
        $scored = $this->engine->score($task, $this->now, RankingParameters::defaults());

        self::assertSame(1.0, $scored->criterion(Criteria::DEADLINE));
        self::assertSame(1.0, $scored->criterion(Criteria::PRESSURE));
        self::assertTrue($scored->isOverdue());
    }

    public function testTaskWithoutDueDateUsesThirtyDayPressureHorizon(): void
    {
        $task = new TaskInput(1, 'Someday', 3, 3, null, 12.0, $this->now);
        $scored = $this->engine->score($task, $this->now, RankingParameters::defaults());

        self::assertSame(0.0, $scored->criterion(Criteria::DEADLINE));
        self::assertEqualsWithDelta(12 / (4 * 30), $scored->criterion(Criteria::PRESSURE), 1e-12);
        self::assertNull($scored->daysRemaining);
    }

    public function testProximityIsOneHalfAtTheHorizon(): void
    {
        $task = new TaskInput(1, 'A week away', 3, 3, $this->now->modify('+7 days'), 1.0, $this->now);
        $scored = $this->engine->score($task, $this->now, RankingParameters::defaults());

        self::assertEqualsWithDelta(0.5, $scored->criterion(Criteria::DEADLINE), 1e-12);
    }

    public function testPressureIsCappedAtOne(): void
    {
        $task = new TaskInput(1, 'Huge', 3, 3, $this->now->modify('+2 days'), 100.0, $this->now);
        $scored = $this->engine->score($task, $this->now, RankingParameters::defaults());

        self::assertSame(1.0, $scored->criterion(Criteria::PRESSURE));
    }

    /** FR6: positions change as time passes, without any change to the tasks. */
    public function testScoresRiseAsTheDueDateApproaches(): void
    {
        $task = new TaskInput(1, 'Report', 4, 3, $this->now->modify('+10 days'), 6.0, $this->now);
        $params = RankingParameters::defaults();

        $today = $this->engine->score($task, $this->now, $params)->score;
        $later = $this->engine->score($task, $this->now->modify('+7 days'), $params)->score;

        self::assertGreaterThan($today, $later);
    }

    public function testTiesAreBrokenByDueDateThenDurationThenCreation(): void
    {
        $params = RankingParameters::defaults();
        $created = $this->now->modify('-1 day');
        $due = $this->now->modify('+3 days');
        // Same importance/urgency, no due date: pressure differs only with duration.
        $noDue = new TaskInput('no-due', 'No due', 3, 3, null, 1.0, $created);
        $sameA = new TaskInput('a', 'A', 3, 3, null, 1.0, $created->modify('+1 minute'));

        $ranked = $this->engine->rank([$sameA, $noDue], $this->now, $params);
        self::assertSame(['no-due', 'a'], array_map(static fn ($t) => $t->task->id, $ranked), 'Earlier creation wins a full tie');

        // Weight only importance: tasks with equal importance tie on score.
        $importanceOnly = $params->withWeights(Weights::fromArray(['I' => 1, 'U' => 0, 'D' => 0, 'P' => 0]));
        $withDue = new TaskInput('due', 'Due', 3, 3, $due, 5.0, $created);
        $without = new TaskInput('none', 'None', 3, 3, null, 1.0, $created);
        $shorter = new TaskInput('short', 'Short', 3, 3, $due, 2.0, $created);

        $ranked = $this->engine->rank([$without, $withDue, $shorter], $this->now, $importanceOnly);
        self::assertSame(['short', 'due', 'none'], array_map(static fn ($t) => $t->task->id, $ranked));
    }

    public function testChangingWeightsReordersTheList(): void
    {
        $urgencyOnly = RankingParameters::defaults()->withWeights(Weights::fromArray(['I' => 0, 'U' => 1, 'D' => 0, 'P' => 0]));
        $ranked = $this->engine->rank(ScenarioSet::inputs($this->now), $this->now, $urgencyOnly);

        self::assertContains($ranked[0]->task->id, ['T3', 'T6']);
        self::assertNotSame(ScenarioSet::EXPECTED_ORDER, array_map(static fn ($t) => $t->task->id, $ranked));
    }
}
