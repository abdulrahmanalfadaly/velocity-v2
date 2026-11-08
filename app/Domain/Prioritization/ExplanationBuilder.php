<?php

declare(strict_types=1);

namespace App\Domain\Prioritization;

/**
 * Algorithm 3.3 – Explain: per-task and contrastive explanations derived from
 * the linear score. Because S is a weighted sum, the contributions are exact
 * and the per-criterion differences between two tasks sum to their score gap.
 */
final class ExplanationBuilder
{
    /** Differences smaller than this (in points) are described as "slightly". */
    public const SLIGHT_THRESHOLD = 2.0;

    private const PHRASE = [
        Criteria::IMPORTANCE => ['it is more important', 'it is slightly more important'],
        Criteria::URGENCY => ['it is more urgent', 'it is slightly more urgent'],
        Criteria::DEADLINE => ['it is due sooner', 'it is due slightly sooner'],
        Criteria::PRESSURE => ['it is more time-pressed', 'it is slightly more time-pressed'],
    ];

    /**
     * "Score 70.9: importance contributed 42.0 points, deadline proximity 12.4,
     * urgency 11.5 and workload pressure 5.0."
     */
    public function summary(ScoredTask $task): string
    {
        $parts = [];
        foreach ($this->contributionsDescending($task) as $key => $points) {
            $value = Rounding::format($points);
            $parts[] = $parts === []
                ? Criteria::NAMES[$key] . ' contributed ' . $value . ' points'
                : Criteria::NAMES[$key] . ' ' . $value;
        }

        return 'Score ' . Rounding::format($task->score) . ': ' . self::joinList($parts) . '.';
    }

    /**
     * Per-criterion differences Δ_j = K_A,j − K_B,j (points). Σ Δ_j = S_A − S_B.
     *
     * @return array<string, float>
     */
    public function deltas(ScoredTask $above, ScoredTask $below): array
    {
        $deltas = [];
        foreach (Criteria::KEYS as $key) {
            $deltas[$key] = $above->contribution($key) - $below->contribution($key);
        }

        return $deltas;
    }

    /**
     * Contrastive explanation of why $above is placed above $below.
     *
     * "Above T3 mainly because it is more important (+21.0 points), although T3
     * is more urgent (−11.5), is due sooner (−7.7) and is slightly more
     * time-pressed (−1.0)."
     */
    public function contrast(ScoredTask $above, ScoredTask $below): string
    {
        $deltas = $this->deltas($above, $below);
        $plus = [];
        $minus = [];
        foreach ($deltas as $key => $delta) {
            $rounded = Rounding::halfUp($delta, 1);
            if ($rounded > 0) {
                $plus[$key] = $delta;
            } elseif ($rounded < 0) {
                $minus[$key] = $delta;
            }
        }
        arsort($plus);
        asort($minus);

        $other = $below->task->title;

        if ($plus === []) {
            return 'Level with ' . $other . ' on score; placed above it because ' . $this->tieBreakReason($above, $below) . '.';
        }

        $reasons = [];
        foreach ($plus as $key => $delta) {
            $suffix = $reasons === [] ? ' points' : '';
            $reasons[] = $this->phrase($key, $delta) . ' (' . Rounding::signed($delta) . $suffix . ')';
        }
        $text = 'Above ' . $other . ' mainly because ' . self::joinList($reasons);

        if ($minus !== []) {
            $counters = [];
            foreach ($minus as $key => $delta) {
                // Same phrase without the leading "it".
                $counters[] = substr($this->phrase($key, $delta), 3) . ' (' . Rounding::signed($delta) . ')';
            }
            $text .= ', although ' . $other . ' ' . self::joinList($counters);
        }

        return $text . '.';
    }

    /**
     * Full explanation for the task at (zero-based) position $k of a ranked list.
     *
     * @param list<ScoredTask> $ranked
     * @return array{summary: string, contrast: ?string, deltas: ?array<string, float>, gap: ?float, below: ?ScoredTask}
     */
    public function explain(array $ranked, int $k): array
    {
        $task = $ranked[$k];
        $below = $ranked[$k + 1] ?? null;

        return [
            'summary' => $this->summary($task),
            'contrast' => $below ? $this->contrast($task, $below) : null,
            'deltas' => $below ? $this->deltas($task, $below) : null,
            'gap' => $below ? $task->score - $below->score : null,
            'below' => $below,
        ];
    }

    /** @return array<string, float> contributions sorted descending (stable on criterion order). */
    public function contributionsDescending(ScoredTask $task): array
    {
        $items = [];
        foreach (Criteria::KEYS as $index => $key) {
            $items[] = [$key, $task->contribution($key), $index];
        }
        usort($items, static fn (array $a, array $b) => [$b[1], $a[2]] <=> [$a[1], $b[2]]);

        $sorted = [];
        foreach ($items as [$key, $value]) {
            $sorted[$key] = $value;
        }

        return $sorted;
    }

    private function phrase(string $key, float $delta): string
    {
        return self::PHRASE[$key][abs($delta) < self::SLIGHT_THRESHOLD ? 1 : 0];
    }

    private function tieBreakReason(ScoredTask $a, ScoredTask $b): string
    {
        $dueA = $a->task->dueAt?->getTimestamp();
        $dueB = $b->task->dueAt?->getTimestamp();
        if ($dueA !== $dueB) {
            return $dueB === null ? 'it has a due date and ' . $b->task->title . ' does not' : 'it is due earlier';
        }
        if (abs($a->task->estHours - $b->task->estHours) > 1e-9) {
            return 'it takes less time';
        }

        return 'it was added earlier';
    }

    /** @param list<string> $items */
    private static function joinList(array $items): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }
        $last = array_pop($items);

        return implode(', ', $items) . ' and ' . $last;
    }
}
