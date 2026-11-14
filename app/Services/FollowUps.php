<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Follow-ups: a day to check on an open task (mostly a waiting one). It only
 * shows the task (Home, Not ready yet, the Planner); it never changes the order.
 */
class FollowUps
{
    /** Open tasks whose follow-up day has come (today or earlier), the oldest first. */
    public static function due(User $user, CarbonImmutable $today): Collection
    {
        return $user->tasks()->open()->whereNotNull('follow_up_on')->whereDate('follow_up_on', '<=', $today->toDateString())
            ->with('project.goal')->orderBy('follow_up_on')->orderBy('id')->get();
    }

    /** A follow-up row: the task, its rank if it has one, and "Follow up today · waiting for Dr. Lim, 5 days". */
    public static function present(Task $task, CarbonImmutable $today, ?int $rank = null): array
    {
        $late = (int) round(CarbonImmutable::parse($task->follow_up_on->toDateString(), $today->timezone)->diffInDays($today));
        $parts = [$late <= 0 ? 'Follow up today' : 'Follow up · '.($late === 1 ? '1 day late' : "{$late} days late")];
        if ($task->status === Task::WAITING) {
            $since = self::waitingDays($task, $today);
            $parts[] = trim('waiting'.($task->waiting_for ? " for {$task->waiting_for}" : '').($since ? ', '.self::days($since) : ''));
        }

        return TaskPresenter::base($task) + ['rank' => $rank, 'why' => implode(' · ', $parts)];
    }

    /** Whole days a waiting task has been waiting (0 = since today; null = not known). */
    public static function waitingDays(Task $task, CarbonImmutable $today): ?int
    {
        return $task->waiting_since ? (int) round($task->waiting_since->timezone($today->timezone)->startOfDay()->diffInDays($today)) : null;
    }

    public static function days(int $n): string
    {
        return $n === 1 ? '1 day' : "{$n} days";
    }
}
