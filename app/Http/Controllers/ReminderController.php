<?php

namespace App\Http\Controllers;

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

        return response()->json(['reminders' => $tasks->map(fn (Task $t) => [
            'id' => $t->id,
            'title' => $t->title,
            'remindAt' => $t->reminder_at->toIso8601ZuluString(),
            'dueAt' => $t->due_at?->toIso8601ZuluString(),
        ])->values()]);
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
