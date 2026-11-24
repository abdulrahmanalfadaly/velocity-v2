<?php

namespace App\Services;

use App\Models\Person;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Follow-ups: a day to check on an open task (mostly a waiting one). It only
 * shows the task (Home, Not ready yet, the Planner); it never changes the order.
 * "Followed up" (a nudge) is a task event of its own, so the times are counted.
 */
class FollowUps
{
    /** Days Home's card looks ahead under "Coming up". */
    public const SOON = 7;

    /** Open tasks whose follow-up day has come (today or earlier), the oldest first. */
    public static function due(User $user, CarbonImmutable $today): Collection
    {
        return $user->tasks()->open()->whereNotNull('follow_up_on')->whereDate('follow_up_on', '<=', $today->toDateString())
            ->with('project.goal')->withCount(self::nudgeCount())->orderBy('follow_up_on')->orderBy('id')->get();
    }

    /**
     * Home's Follow-ups card: due (today or late), waiting with no follow-up day, and the
     * next seven days; plus how many are further off.
     */
    public static function card(User $user, CarbonImmutable $today, RankedList $list): array
    {
        $day = $today->toDateString();
        $soon = $today->addDays(self::SOON)->toDateString();
        $open = $user->tasks()->open()->where(fn ($q) => $q->whereNotNull('follow_up_on')->orWhere('status', Task::WAITING))
            ->with(['project.goal', 'people:id,name'])->withCount(self::nudgeCount())->orderBy('follow_up_on')->orderBy('id')->get();
        $on = fn (Task $t) => $t->follow_up_on?->toDateString();
        $row = fn (Task $t) => self::present($t, $today, $list->rankOf($t->id));

        return [
            'due' => $open->filter(fn ($t) => $on($t) && $on($t) <= $day)->map($row)->values(),
            'waiting' => $open->filter(fn ($t) => ! $on($t) && $t->status === Task::WAITING)->sortBy(fn ($t) => $t->waiting_since?->getTimestamp() ?? 0)->map($row)->values(),
            'soon' => $open->filter(fn ($t) => $on($t) && $on($t) > $day && $on($t) <= $soon)->map($row)->values(),
            'later' => $open->filter(fn ($t) => $on($t) && $on($t) > $soon)->count(),
            // For "+": your People to pick "Who?" from, and the day it suggests (Settings → Tasks).
            'people' => $user->people()->whereNull('archived_at')->orderBy('name')->pluck('name'),
            'after' => (int) $user->follow_up_days,
        ];
    }

    /**
     * A follow-up row: the task, its rank if it has one, "Follow up today · waiting for Dr. Lim, 5 days",
     * and the parts on their own (days late, days waiting, who, times followed up).
     */
    public static function present(Task $task, CarbonImmutable $today, ?int $rank = null): array
    {
        $late = $task->follow_up_on ? (int) round(CarbonImmutable::parse($task->follow_up_on->toDateString(), $today->timezone)->diffInDays($today, false)) : null;
        $since = $task->status === Task::WAITING ? self::waitingDays($task, $today) : null;
        $parts = $late === null ? [] : [$late <= 0 ? ($late === 0 ? 'Follow up today' : 'Follow up '.CarbonImmutable::parse($task->follow_up_on->toDateString())->format('D j M')) : 'Follow up · '.($late === 1 ? '1 day late' : "{$late} days late")];
        if ($task->status === Task::WAITING) {
            $parts[] = trim('waiting'.($task->waiting_for ? " for {$task->waiting_for}" : '').($since ? ', '.self::days($since) : ''));
        }
        $person = $task->relationLoaded('people') && $task->waiting_for
            ? $task->people->first(fn (Person $p) => mb_strtolower($p->name) === mb_strtolower($task->waiting_for))
            : null;

        return TaskPresenter::base($task) + [
            'rank' => $rank,
            'why' => ucfirst(implode(' · ', $parts)),
            'late' => $late,
            'waitingDays' => $since,
            'who' => $task->waiting_for,
            // Initials for someone in People, or a short name ("Dr. Lim", "HR"); a longer "what" gets the icon.
            'initials' => $person ? $person->initials() : ($task->waiting_for && count(preg_split('/\s+/u', trim($task->waiting_for)) ?: []) <= 3 ? self::initials($task->waiting_for) : null),
            'personId' => $person?->id,
            'nudges' => (int) ($task->nudges ?? 0),
        ];
    }

    /** Times it was followed up (each "Followed up" is an event of its own). */
    public static function nudgeCount(): array
    {
        return ['events as nudges' => fn ($q) => $q->where('type', 'followed_up')];
    }

    /** Waiting for someone in People (by name or nickname): link the task to them. */
    public static function linkPerson(Task $task, User $user, string $name): ?Person
    {
        $name = mb_strtolower(trim($name));
        $person = $user->people()->whereNull('archived_at')->get(['id', 'name', 'nickname'])
            ->first(fn (Person $p) => mb_strtolower($p->name) === $name || ($p->nickname && mb_strtolower($p->nickname) === $name));
        $person?->tasks()->syncWithoutDetaching([$task->id]);

        return $person;
    }

    /** "Dr. Lim" → "DL", "Aisha Rahman" → "AR", "HR" → "HR". */
    public static function initials(string $name): string
    {
        $words = array_values(array_filter(preg_split('/[\s.]+/u', trim($name)) ?: []));
        if (count($words) === 1 && mb_strlen($words[0]) <= 3 && mb_strtoupper($words[0]) === $words[0]) {
            return $words[0];
        }

        return mb_strtoupper(mb_substr($words[0] ?? '?', 0, 1).(count($words) > 1 ? mb_substr(end($words), 0, 1) : ''));
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
