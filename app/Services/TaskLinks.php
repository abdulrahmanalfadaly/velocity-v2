<?php

namespace App\Services;

use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Tasks that wait on other tasks ("Starts after"), seen from both sides: what a
 * task still waits on, and what finishing or reopening it means for the tasks
 * waiting on it. Only words for the screens; the ranking reads the links itself.
 */
class TaskLinks
{
    /** The first task this one still waits on (not done or cancelled), if any. */
    public static function waitingOn(Task $task): ?Task
    {
        return $task->dependencies()->whereNotIn('status', Task::FINISHED)->orderBy('title')->first(['tasks.id', 'tasks.title']);
    }

    /** Open tasks waiting on this one that have nothing else to wait for now (after it was done or cancelled). */
    public static function freed(Task $task, CarbonImmutable $now): Collection
    {
        return $task->dependents()->whereIn('status', Task::OPEN)
            ->whereDoesntHave('dependencies', fn ($q) => $q->whereNotIn('status', Task::FINISHED))
            ->where(fn ($q) => $q->whereNull('available_from')->orWhere('available_from', '<=', $now))
            ->orderBy('title')->get(['tasks.id', 'tasks.title']);
    }

    /** Open tasks waiting on this one (after it was opened again, they wait again). */
    public static function waitingAgain(Task $task): Collection
    {
        return $task->dependents()->whereIn('status', Task::OPEN)->orderBy('title')->get(['tasks.id', 'tasks.title']);
    }

    /** " · “Slides” can start now", " · 3 tasks wait on it again", or nothing. */
    public static function words(Collection $tasks, string $one, string $many): string
    {
        return match ($tasks->count()) {
            0 => '',
            1 => ' · “'.$tasks->first()->title.'” '.$one,
            default => ' · '.$tasks->count().' tasks '.$many,
        };
    }
}
