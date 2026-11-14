<?php

namespace App\Http\Controllers;

use App\Actions\FocusActions;
use App\Models\FocusSession;
use App\Models\Task;
use App\Models\User;
use App\Services\RankingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The focus timer. One session runs at a time. Starting focus on a task also marks
 * it "Doing". Focused time never changes a task's rank by itself: after stopping,
 * the user is asked whether to update the hours left.
 */
class FocusController extends Controller
{
    /** Shorter than this is a false start: not kept. */
    public const MIN_SECONDS = FocusActions::MIN_SECONDS;

    public function __construct(private readonly RankingService $ranking, private readonly FocusActions $actions) {}

    private function now(): CarbonImmutable
    {
        return $this->ranking->now();
    }

    public static function running(User $user): ?FocusSession
    {
        return FocusActions::running($user);
    }

    /** The fields of "start focus": an optional task and planned minutes. */
    public static function startRules(User $user): array
    {
        return [
            'task_id' => ['nullable', 'integer', Rule::exists('tasks', 'id')->where('user_id', $user->id)->whereNull('deleted_at')],
            'minutes' => ['nullable', 'integer', 'between:1,600'],
        ];
    }

    public function start(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate(self::startRules($user));
        $task = isset($data['task_id']) ? Task::find($data['task_id']) : null;
        $session = $this->actions->start($user, $task, $data['minutes'] ?? null);

        return back()->with('toast', $task ? "Focus on “{$task->title}”" : 'Focus started')->with('undo', $this->undo('focus-cancel', $session->id));
    }

    public function pause(Request $request, FocusSession $session): RedirectResponse
    {
        $this->own($session, $request->user());
        if (! $session->ended_at && ! $session->paused_at) {
            $session->update(['paused_at' => $this->now()]);
        }

        return back();
    }

    public function resume(Request $request, FocusSession $session): RedirectResponse
    {
        $this->own($session, $request->user());
        if (! $session->ended_at && $session->paused_at) {
            $session->update(['paused_seconds' => $session->paused_seconds + $this->now()->getTimestamp() - $session->paused_at->getTimestamp(), 'paused_at' => null]);
        }

        return back();
    }

    /** Stop: keep the time and offer to update the task's hours left. */
    public function stop(Request $request, FocusSession $session): RedirectResponse
    {
        $this->own($session, $request->user());
        if ($session->ended_at) {
            return back();
        }
        $seconds = $this->actions->finish($session);
        if ($seconds < self::MIN_SECONDS) {
            return back()->with('toast', 'Under a minute – not counted');
        }
        $task = $session->task;
        $back = back()->with('undo', $this->undo('focus-resume', $session->id));
        if (! $task || ! $task->isOpen()) {
            return $back->with('toast', 'Focused '.self::duration($seconds));
        }

        // Ask, never assume: the hours estimate feeds the formula's workload score.
        return $back->with('focusDone', [
            'taskId' => $task->id,
            'title' => $task->title,
            'seconds' => $seconds,
            'estHours' => $task->est_hours,
            'hoursLeft' => max(0.25, round(($task->est_hours - $seconds / 3600) * 4) / 4),
        ]);
    }

    /** Undo of Stop: carry on, as if it hadn't been stopped (the moments in between count as a pause). */
    public function undoStop(Request $request, FocusSession $session): RedirectResponse
    {
        $user = $request->user();
        $this->own($session, $user);
        if (! $session->ended_at || self::running($user)) {
            return back();
        }
        $gap = max(0, $this->now()->getTimestamp() - $session->ended_at->getTimestamp());
        $session->update(['ended_at' => null, 'seconds' => null, 'paused_seconds' => $session->paused_seconds + $gap]);

        return back()->with('toast', 'Focus resumed');
    }

    /** Undo of Start: as if it never began (the task gets its old status back). */
    public function cancel(Request $request, FocusSession $session): RedirectResponse
    {
        $this->own($session, $request->user());
        $task = $session->task;
        if ($task && $session->was_status && $task->status === Task::DOING) {
            $task->update(['status' => $session->was_status]);
        }
        $session->delete();

        return back()->with('toast', 'Focus cancelled');
    }

    /** "1 h 20 min", "45 min". */
    public static function duration(int $seconds): string
    {
        $m = intdiv($seconds + 30, 60);

        return $m >= 60 ? intdiv($m, 60).' h'.($m % 60 ? ' '.($m % 60).' min' : '') : "{$m} min";
    }
}
