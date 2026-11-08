<?php

declare(strict_types=1);

namespace Tests\Unit\Prioritization;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use App\Domain\Prioritization\PrioritizationEngine;
use App\Domain\Prioritization\RankingParameters;
use App\Domain\Prioritization\ScenarioSet;
use App\Domain\Prioritization\ScoredTask;
use App\Domain\Prioritization\TaskInput;

/**
 * FR7 / rank-stability test: creating or completing a task never changes the
 * relative order of the other open tasks.
 */
final class RankStabilityTest extends TestCase
{
    private PrioritizationEngine $engine;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->engine = new PrioritizationEngine();
        $this->now = new DateTimeImmutable('2026-11-09 09:00:00');
    }

    /** @param list<ScoredTask> $ranked @return list<int|string> */
    private static function ids(array $ranked): array
    {
        return array_map(static fn (ScoredTask $t) => $t->task->id, $ranked);
    }

    /** @param list<int|string> $order @param list<int|string> $keep @return list<int|string> */
    private static function restrict(array $order, array $keep): array
    {
        return array_values(array_filter($order, static fn ($id) => in_array($id, $keep, true)));
    }

    public function testInsertingEachPossibleTaskKeepsScenarioOrder(): void
    {
        $params = RankingParameters::defaults();
        $base = ScenarioSet::inputs($this->now);
        $before = self::ids($this->engine->rank($base, $this->now, $params));

        foreach ([1, 3, 5] as $importance) {
            foreach ([1, 3, 5] as $urgency) {
                foreach ([null, 0.25, 2, 9, 40] as $days) {
                    $new = new TaskInput(
                        'NEW',
                        'Inserted',
                        $importance,
                        $urgency,
                        $days === null ? null : ScenarioSet::dueDate($this->now, $days),
                        3.0,
                        $this->now,
                    );
                    $after = self::ids($this->engine->rank([...$base, $new], $this->now, $params));
                    self::assertSame($before, self::restrict($after, $before));
                }
            }
        }
    }

    public function testCompletingAnyTaskKeepsScenarioOrder(): void
    {
        $params = RankingParameters::defaults();
        $base = ScenarioSet::inputs($this->now);
        $before = self::ids($this->engine->rank($base, $this->now, $params));

        foreach ($base as $index => $completed) {
            $remaining = $base;
            unset($remaining[$index]);
            $after = self::ids($this->engine->rank(array_values($remaining), $this->now, $params));
            $expected = array_values(array_filter($before, static fn ($id) => $id !== $completed->id));
            self::assertSame($expected, $after);
        }
    }

    public function testRandomSetsAreStableUnderInsertionAndRemoval(): void
    {
        mt_srand(20261108);
        $params = RankingParameters::defaults();

        for ($trial = 0; $trial < 200; $trial++) {
            $tasks = [];
            $count = mt_rand(2, 25);
            for ($i = 0; $i < $count; $i++) {
                $tasks[] = $this->randomTask($i);
            }
            $before = self::ids($this->engine->rank($tasks, $this->now, $params));

            $inserted = [...$tasks, $this->randomTask(1000)];
            self::assertSame($before, self::restrict(self::ids($this->engine->rank($inserted, $this->now, $params)), $before));

            $removeAt = mt_rand(0, $count - 1);
            $removedId = $tasks[$removeAt]->id;
            $remaining = $tasks;
            unset($remaining[$removeAt]);
            $expected = array_values(array_filter($before, static fn ($id) => $id !== $removedId));
            self::assertSame($expected, self::ids($this->engine->rank(array_values($remaining), $this->now, $params)));
        }
    }

    private function randomTask(int $id): TaskInput
    {
        $due = mt_rand(0, 5) === 0 ? null : $this->now->modify(sprintf('%+d minutes', mt_rand(-3 * 1440, 45 * 1440)));

        return new TaskInput(
            $id,
            'Task ' . $id,
            mt_rand(1, 5),
            mt_rand(1, 5),
            $due,
            mt_rand(1, 80) / 4,
            $this->now->modify('-' . mt_rand(1, 10000) . ' minutes'),
        );
    }
}
