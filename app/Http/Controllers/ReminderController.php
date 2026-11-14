<?php

namespace App\Http\Controllers;

use App\Models\PersonDate;
use App\Models\Task;
use App\Services\RankingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Reminders. Velocity runs on the user's own computer, so a reminder can only pop
 * up while the app is open: the open window asks here every 30 seconds. Ones that
 * came due while it was closed show up the next time it opens.
 */
class ReminderController extends Controller
{
    public function __construct(private readonly RankingService $ranking) {}

    /** Reminders that are due and not shown yet. Each is handed out once. */
    public function due(Request $request): JsonResponse
    {
        $user = $request->user();
        $now = $this->ranking->now();
        $tasks = $user->tasks()->open()
            ->whereNotNull('reminder_at')->where('reminder_at', '<=', $now)->whereNull('reminder_sent_at')
            ->orderBy('reminder_at')->limit(20)->get();
        if ($tasks->isNotEmpty()) {
            Task::whereKey($tasks->pluck('id'))->update(['reminder_sent_at' => $now]);
        }

        // Birthdays and other dates: at 9:00 on the day (or the days before that were asked for), once for each time it comes round.
        $today = $now->timezone($user->timezone)->startOfDay();
        $dates = PersonDate::whereNotNull('remind_days')
            ->whereHas('person', fn ($p) => $p->where('user_id', $user->id)->whereNull('archived_at')->whereNull('deleted_at'))
            ->with('person')->get()
            ->map(fn (PersonDate $d) => ['d' => $d, 'next' => $d->next($today)])
            ->filter(fn ($x) => $x['next']
                && $x['d']->reminded_for?->toDateString() !== $x['next']->toDateString()
                && $now >= $x['next']->subDays($x['d']->remind_days)->setTime(9, 0))
            ->take(20)->values();
        foreach ($dates as $x) {
            $x['d']->update(['reminded_for' => $x['next']->toDateString()]);
        }

        return response()->json([
            'reminders' => $tasks->map(fn (Task $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'remindAt' => $t->reminder_at->toIso8601ZuluString(),
                'dueAt' => $t->due_at?->toIso8601ZuluString(),
            ])->values(),
            'dates' => $dates->map(fn ($x) => [
                'id' => $x['d']->id,
                'personId' => $x['d']->person_id,
                'title' => $x['d']->title($x['d']->person->name),
                'date' => $x['next']->toDateString(),
                'days' => (int) round($today->diffInDays($x['next'])),
                'turns' => $x['d']->turns($x['next']),
            ]),
        ]);
    }

    /** "Snooze": remind again in a while. */
    public function snooze(Request $request, Task $task): RedirectResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        $minutes = $request->validate(['minutes' => ['required', 'integer', 'between:1,1440']])['minutes'];
        $task->update(['reminder_at' => $this->ranking->now()->addMinutes($minutes), 'reminder_sent_at' => null]);

        return back()->with('toast', $minutes >= 60 ? 'Reminder in '.intdiv($minutes, 60).' h' : "Reminder in {$minutes} min");
    }
}
