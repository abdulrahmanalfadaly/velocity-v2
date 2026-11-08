<?php

namespace App\Services;

use App\Domain\Prioritization\Criteria;
use App\Domain\Prioritization\ExplanationBuilder;
use App\Domain\Prioritization\RankingParameters;
use App\Domain\Prioritization\Rounding;
use App\Domain\Prioritization\ScoredTask;
use App\Models\Task;
use Illuminate\Support\Collection;

/**
 * One ranking: the engine's scored tasks in order, the task models behind
 * them, and their explanations. Hand it to the screens with toArray().
 */
class RankedList implements \Countable
{
    /** Two neighbours closer than this many points are flagged as "almost tied". */
    public const NEAR_TIE = 1.0;

    /** @var array<int, int> task id => index */
    private array $index = [];

    /**
     * @param  list<ScoredTask>  $scored
     * @param  Collection<int, Task>  $tasks  keyed by id
     */
    public function __construct(
        public readonly array $scored,
        public readonly Collection $tasks,
        private readonly ExplanationBuilder $explainer,
        public readonly RankingParameters $params,
    ) {
        foreach ($scored as $k => $s) {
            $this->index[(int) $s->task->id] = $k;
        }
    }

    public function count(): int
    {
        return count($this->scored);
    }

    public function rankOf(int $taskId): ?int
    {
        return isset($this->index[$taskId]) ? $this->index[$taskId] + 1 : null;
    }

    public function scoreOf(int $taskId): ?float
    {
        return isset($this->index[$taskId]) ? $this->scored[$this->index[$taskId]]->displayScore() : null;
    }

    /** Screen data for the task at position $k (0-based), explained against the one below it. */
    public function present(int $k): array
    {
        $s = $this->scored[$k];
        $task = $this->tasks[(int) $s->task->id];
        $e = $this->explainer->explain($this->scored, $k);
        $below = $e['below'] ? $this->tasks[(int) $e['below']->task->id] : null;
        $round = fn (array $values) => array_map(fn ($v) => Rounding::halfUp($v, 1), $values);

        return TaskPresenter::base($task) + [
            'rank' => $s->rank,
            'score' => $s->displayScore(),
            'contrib' => $round($s->contributions),
            'criteria' => array_map(fn ($v) => round($v, 4), $s->criteria),
            'days' => $s->daysRemaining === null ? null : round($s->daysRemaining, 3),
            'summary' => $e['summary'],
            'contrast' => $e['contrast'],
            'deltas' => $e['deltas'] === null ? null : $round($e['deltas']),
            'gap' => $e['gap'] === null ? null : Rounding::halfUp($e['gap'], 1),
            'below' => $below ? ['id' => $below->id, 'title' => $below->title] : null,
            'nearTie' => $e['gap'] !== null && Rounding::halfUp($e['gap'], 1) < self::NEAR_TIE,
        ];
    }

    /** @param  null|callable(Task): bool  $filter */
    public function toArray(?callable $filter = null): array
    {
        $out = [];
        foreach ($this->scored as $k => $s) {
            if ($filter === null || $filter($this->tasks[(int) $s->task->id])) {
                $out[] = $this->present($k);
            }
        }

        return $out;
    }

    /** The weights and settings behind this ranking, as shown on screen. */
    public function settings(): array
    {
        return [
            'weights' => $this->params->weights->toPercentages(),
            'isDefault' => $this->params->weights->isDefault(),
            'horizonDays' => $this->params->horizonDays,
            'hoursPerDay' => $this->params->hoursPerDay,
            'keys' => Criteria::KEYS,
        ];
    }
}
