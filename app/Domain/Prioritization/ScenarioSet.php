<?php

declare(strict_types=1);

namespace App\Domain\Prioritization;

use DateInterval;
use DateTimeImmutable;

/**
 * The standard scenario set of the evaluation (Table 3.4): ten academic tasks
 * whose due dates are generated relative to the session start, so that every
 * participant sees the same ranking with the default parameters.
 */
final class ScenarioSet
{
    /**
     * code, title, description, importance, urgency, due in days, hours.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: float, 6: float}>
     */
    public const TASKS = [
        ['T1', 'Software Engineering assignment report (30% of module)', 'Individual report worth 30% of the module mark.', 5, 3, 6, 10],
        ['T2', 'Weekly reading for Database Systems', 'Chapter reading before the next lecture.', 2, 2, 2, 1.5],
        ['T3', 'Group project meeting slides', 'Slides for tomorrow\'s group meeting; teammates are waiting.', 3, 5, 1, 2],
        ['T4', 'Revise for Networks quiz (10%)', 'Quiz worth 10% of the module mark.', 3, 3, 4, 4],
        ['T5', 'Start revision for final examinations', 'Long-term revision plan for all modules.', 5, 1, 28, 30],
        ['T6', 'Reply to lecturer about consultation slot', 'Short email reply, needed this afternoon.', 2, 5, 0.5, 0.25],
        ['T7', 'Lab exercise 4 submission', 'Weekly lab exercise.', 3, 2, 3, 3],
        ['T8', 'Update CV for internship application', 'Tailor CV for the internship application.', 4, 2, 14, 3],
        ['T9', 'Discrete mathematics tutorial questions', 'Tutorial sheet for next week.', 2, 1, 5, 2],
        ['T10', 'Prepare questions for supervisor meeting', 'List of questions for the project supervisor.', 4, 4, 2, 1],
    ];

    /** Reference ranking of Table 3.4 (codes, best first) and its scores. */
    public const EXPECTED_ORDER = ['T1', 'T3', 'T10', 'T6', 'T4', 'T5', 'T7', 'T8', 'T2', 'T9'];

    public const EXPECTED_SCORES = [
        'T1' => 70.9, 'T3' => 70.1, 'T10' => 68.1, 'T6' => 55.7, 'T4' => 50.1,
        'T5' => 49.8, 'T7' => 45.9, 'T8' => 45.6, 'T2' => 36.4, 'T9' => 25.1,
    ];

    /** Due date d days after $start (fractional days allowed). */
    public static function dueDate(DateTimeImmutable $start, float $days): DateTimeImmutable
    {
        $seconds = (int) round($days * 86400);

        return $start->add(new DateInterval('PT' . $seconds . 'S'));
    }

    /**
     * Scenario tasks as engine inputs, with ids equal to their codes.
     *
     * @return list<TaskInput>
     */
    public static function inputs(DateTimeImmutable $start): array
    {
        $inputs = [];
        foreach (self::TASKS as $i => [$code, $title, , $importance, $urgency, $days, $hours]) {
            $inputs[] = new TaskInput(
                $code,
                $code . ' ' . $title,
                $importance,
                $urgency,
                self::dueDate($start, (float) $days),
                (float) $hours,
                $start->modify('-' . (count(self::TASKS) - $i) . ' minutes'),
            );
        }

        return $inputs;
    }
}
