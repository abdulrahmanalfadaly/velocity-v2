<?php

namespace App\Http\Controllers;

use App\Actions\FocusActions;
use App\Actions\TaskActions;
use App\Models\FocusSession;
use App\Models\Task;
use App\Models\User;
use App\Services\RankingService;
use App\Support\FocusSettings;
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

    /**
     * The fields of "start focus": what it is on (a task, or a project, goal, habit or note;
     * none = nothing in particular), a timer (minutes, or none = no limit) or a Pomodoro,
     * and what you'll do.
     */
    public static function startRules(User $user): array
    {
        $own = fn (string $table) => ['nullable', 'integer', Rule::exists($table, 'id')->where('user_id', $user->id)->whereNull('deleted_at')];

        return [
            'task_id' => $own('tasks'),
            'project_id' => $own('projects'),
            'goal_id' => $own('goals'),
            'habit_id' => $own('habits'),
            'note_id' => $own('notes'),
            'minutes' => ['nullable', 'integer', 'between:1,600'],
            'mode' => ['nullable', Rule::in(['timer', 'pomodoro'])],
            'intention' => ['nullable', 'string', 'max:240'],
        ];
    }

    /** Starts focus; with neither a length nor a mode it starts the way Settings → Focus says. */
    public static function begin(FocusActions $actions, User $user, array $data): FocusSession
    {
        $task = isset($data['task_id']) ? Task::find($data['task_id']) : null;
        if (! array_key_exists('minutes', $data) && empty($data['mode'])) {
            $settings = FocusSettings::of($user);
            $data['mode'] = $settings['mode'];
            $data['minutes'] = $settings['length'];
        }
        $intention = trim($data['intention'] ?? '') ?: null;

        return $actions->start($user, $task, $data['minutes'] ?? null, $data['mode'] ?? 'timer', $intention, null, self::subjectOf($data));
    }

    /** One thing at a time: the first of project, goal, habit, note that was given ({project_id: 3}); a task wins over all of them. */
    public static function subjectOf(array $data): array
    {
        return collect(FocusSession::SUBJECTS)->map(fn ($column) => $data[$column] ?? null)->filter()->take(1)
            ->mapWithKeys(fn ($id, $kind) => [FocusSession::SUBJECTS[$kind] => $id])->all();
    }

    public function start(Request $request): RedirectResponse
    {
        $user = $request->user();
        $session = self::begin($this->actions, $user, $request->validate(self::startRules($user)));
        $subject = $session->subject();

        return back()->with('toast', $subject ? "Focus on “{$subject['title']}”" : 'Focus started')->with('undo', $this->undo('focus-cancel', $session->id));
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
        if ($session->habit && in_array($session->habit->kind ?? 'do', ['do', 'minutes'], true)) {
            $back->with('focusHabit', self::habitOffer($session, $seconds));
        }
        if (! $task || ! $task->isOpen()) {
            return $back->with('toast', 'Focused '.self::duration($seconds));
        }

        // Ask, never assume: the hours estimate feeds the formula's workload score.
        return $back->with('focusDone', [
            'sessionId' => $session->id,
            'taskId' => $task->id,
            'title' => $task->title,
            'seconds' => $seconds,
            'estHours' => $task->est_hours,
            'hoursLeft' => max(0.25, round(($task->est_hours - $seconds / 3600) * 4) / 4),
        ]);
    }

    /**
     * After focus on a habit: what checking it off today would set. A minutes habit adds
     * the minutes focused ("Add 25 min to Read?"); any other adds one ("Mark Read done?").
     */
    public static function habitOffer(FocusSession $session, int $seconds): array
    {
        $habit = $session->habit;
        $today = $session->ended_at->timezone($session->user->timezone)->toDateString();
        $entry = $habit->entries()->whereDate('day', $today)->first();
        $count = $entry && ! $entry->skipped ? $entry->count : 0;
        $minutes = max(1, (int) round($seconds / 60));
        $isMinutes = $habit->kind === 'minutes';

        return [
            'habitId' => $habit->id,
            'name' => $habit->name,
            'minutes' => $isMinutes ? $minutes : null,
            'count' => $isMinutes ? $count + $minutes : $count + 1,
            'doneAlready' => ! $isMinutes && $habit->achieved($count),
        ];
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
        $session->forceDelete();

        return back()->with('toast', 'Focus cancelled');
    }

    /** Pomodoro: start the break now (when breaks don't start by themselves). */
    public function takeBreak(Request $request, FocusSession $session): RedirectResponse
    {
        $this->own($session, $request->user());
        if ($session->break_minutes && ! $session->break_started_at) {
            $session->update(['break_started_at' => $this->now()]);
        }

        return back();
    }

    /** Pomodoro: the next round now ("Skip break", or "Start round 3" when the break is over). */
    public function next(Request $request, FocusSession $session): RedirectResponse
    {
        $user = $request->user();
        $this->own($session, $user);
        if (! $session->break_minutes || self::running($user)) {
            return back();
        }
        $next = $this->actions->nextRound($user, $session);

        return back()->with('toast', "Round {$next->round}");
    }

    /** Pomodoro: done for now (no more rounds). */
    public function end(Request $request, FocusSession $session): RedirectResponse
    {
        $user = $request->user();
        $this->own($session, $user);
        FocusActions::leaveBreaks($user);
        $rounds = $session->round ?? 1;

        return back()->with('toast', 'Pomodoro done · '.($rounds === 1 ? '1 round' : "{$rounds} rounds"));
    }

    /** "Task done" in the timer: the focus so far is kept and the task is ticked off (Undo opens it again). */
    public function doneTask(Request $request, FocusSession $session, TaskActions $tasks): RedirectResponse
    {
        $user = $request->user();
        $this->own($session, $user);
        $task = $session->task;
        if (! $task || ! $task->isOpen()) {
            return back();
        }
        $seconds = $session->ended_at ? null : $this->actions->finish($session);
        FocusActions::leaveBreaks($user);
        $tasks->complete($task, $user);
        $focused = $seconds !== null && $seconds >= self::MIN_SECONDS ? ' · focused '.self::duration($seconds) : '';

        return back()->with('toast', "Done: “{$task->title}”{$focused}")->with('undo', ['action' => 'reopen', 'id' => $task->id]);
    }

    /** The note after a session ("What got done?"), from the stop card or the history. */
    public function update(Request $request, FocusSession $session): RedirectResponse
    {
        $this->own($session, $request->user());
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000'], 'intention' => ['sometimes', 'nullable', 'string', 'max:240']]);
        $session->update(array_map(fn ($v) => is_string($v) ? (trim($v) ?: null) : $v, $data));

        return back()->with('toast', 'Saved');
    }

    /** Remove a session from the history (a mistake); Undo brings it back. */
    public function destroy(Request $request, FocusSession $session): RedirectResponse
    {
        $this->own($session, $request->user());
        if (! $session->ended_at) {
            return back();
        }
        $session->delete();

        return back()->with('toast', 'Session removed')->with('undo', $this->undo('focus-restore', $session->id));
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $session = FocusSession::onlyTrashed()->findOrFail($id);
        $this->own($session, $request->user());
        $session->restore();

        return back()->with('toast', 'Session back');
    }

    /** "1 h 20 min", "45 min". */
    public static function duration(int $seconds): string
    {
        $m = intdiv($seconds + 30, 60);

        return $m >= 60 ? intdiv($m, 60).' h'.($m % 60 ? ' '.($m % 60).' min' : '') : "{$m} min";
    }
}
