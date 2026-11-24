<?php

namespace App\Http\Controllers;

use App\Actions\TaskActions;
use App\Models\Task;
use App\Models\User;
use App\Services\FollowUps;
use App\Services\RankingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Follow-ups from Home's card, Not ready and the task panel: a new one, "Followed up"
 * (counted; again on a day or no next day) and "Got a reply" (it stops waiting). Each with Undo.
 */
class FollowUpController extends Controller
{
    public function __construct(private readonly RankingService $ranking, private readonly TaskActions $actions) {}

    /** POST follow-ups {title, who?, on}: a task waiting on someone, to check back on the day picked. */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $today = $this->today($user);
        $d = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'who' => ['nullable', 'string', 'max:160'],
            'on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today->toDateString()],
        ], ['on.after_or_equal' => 'Pick today or a day ahead.']);
        $who = trim($d['who'] ?? '') ?: null;
        $task = $this->actions->create($user, [
            'title' => trim($d['title']), 'importance' => 3, 'urgency' => 3, 'est_hours' => 0.25,
            'status' => Task::WAITING, 'waiting_for' => $who, 'waiting_since' => $this->ranking->now(), 'follow_up_on' => $d['on'],
        ]);
        if ($who) {
            FollowUps::linkPerson($task, $user, $who);
        }

        return back()->with('toast', 'Follow up '.$this->when($d['on'], $today))->with('undo', $this->undo('delete-task', $task->id));
    }

    /** POST tasks/{task}/followed-up {again?}: you checked on it; again on a day, or no next day (it keeps waiting). */
    public function done(Request $request, Task $task): RedirectResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        abort_unless($task->isOpen(), 422, 'Only an open task can be followed up.');
        $today = $this->today($user);
        $again = $request->validate(['again' => ['nullable', 'date_format:Y-m-d', 'after:'.$today->toDateString()]], ['again.after' => 'Pick a day after today.'])['again'] ?? null;
        $before = ['follow_up_on' => $task->follow_up_on?->toDateString()];
        $task->update(['follow_up_on' => $again]);
        $event = $task->events()->create(['user_id' => $user->id, 'type' => 'followed_up', 'snapshot' => ['before' => $before, 'after' => ['follow_up_on' => $again]]]);
        $times = $task->events()->where('type', 'followed_up')->count();

        return back()
            ->with('toast', 'Followed up'.($times > 1 ? " · {$times} times" : '').' · '.($again ? 'again '.$this->when($again, $today) : 'no next day'))
            ->with('undo', $this->undo('revert-task', $task->id, ['event' => $event->id]));
    }

    /** POST tasks/{task}/replied: the answer came. It stops waiting (back to To do, so it is ranked) and has no follow-up day. */
    public function replied(Request $request, Task $task): RedirectResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        abort_unless($task->isOpen(), 422, 'Only an open task can get a reply.');
        $before = ['status' => $task->status, 'waiting_since' => $task->waiting_since?->toIso8601ZuluString(), 'follow_up_on' => $task->follow_up_on?->toDateString()];
        $after = ['follow_up_on' => null] + ($task->status === Task::WAITING ? ['status' => Task::TODO, 'waiting_since' => null] : []);
        $task->update($after);
        $event = $task->events()->create(['user_id' => $user->id, 'type' => 'replied', 'snapshot' => ['before' => $before, 'after' => $after]]);
        $rank = $this->ranking->rank($user)->rankOf($task->id);

        return back()
            ->with('toast', "Got a reply · “{$task->title}”".($rank ? " is #{$rank}" : ' is back in your list'))
            ->with('undo', $this->undo('revert-task', $task->id, ['event' => $event->id]));
    }

    private function today(User $user): CarbonImmutable
    {
        return $this->ranking->now()->timezone($user->timezone)->startOfDay();
    }

    /** "today", "tomorrow" or "Fri 27 Nov". */
    private function when(string $day, CarbonImmutable $today): string
    {
        return match ($day) {
            $today->toDateString() => 'today',
            $today->addDay()->toDateString() => 'tomorrow',
            default => CarbonImmutable::parse($day)->format('D j M'),
        };
    }
}
