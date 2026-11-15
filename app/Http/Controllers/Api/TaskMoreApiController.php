<?php

namespace App\Http\Controllers\Api;

use App\Actions\FocusActions;
use App\Actions\TaskActions;
use App\Models\FocusSession;
use App\Models\Task;
use App\Models\TaskReminder;
use App\Models\TaskTemplate;
use App\Services\NextActions;
use App\Services\RankingService;
use App\Services\Reminders;
use App\Services\ReviewService;
use App\Support\ApiPresent;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tasks, more: cancel, archive, reminders, the selection bar's actions, Next
 * actions, templates, and the rest of the focus timer (pause, Pomodoro
 * rounds, history, the note after).
 */
class TaskMoreApiController extends ApiController
{
    public function __construct(RankingService $ranking, private readonly TaskActions $actions, private readonly FocusActions $focus)
    {
        parent::__construct($ranking);
    }

    /** {"reason": "…", "skip": true}: a repeating task with skip makes its next copy. */
    public function cancel(Request $request, Task $task): JsonResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        $d = $request->validate(['reason' => ['nullable', 'string', 'max:300'], 'skip' => ['sometimes', 'boolean']]);
        if (! $task->isOpen()) {
            throw ValidationException::withMessages(['task' => 'Only an open task can be cancelled.']);
        }
        $skip = ($d['skip'] ?? false) && $task->repeat_unit;
        $next = $this->actions->cancel($task, $user, trim($d['reason'] ?? '') ?: ($skip ? 'Skipped' : null), (bool) $skip);
        $list = $this->ranking->rank($user);

        return $this->ok(ApiPresent::task($task->fresh(['project.goal'])) + ['next' => $next ? ApiPresent::task($next->fresh(['project.goal']), $list) : null]);
    }

    public function archive(Request $request, Task $task): JsonResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        if (! in_array($task->status, Task::FINISHED, true)) {
            throw ValidationException::withMessages(['task' => 'Only a done or cancelled task can be archived.']);
        }
        if (! $task->archived_at) {
            $this->actions->archive($task, $user);
        }

        return $this->ok(ApiPresent::task($task->fresh(['project.goal'])));
    }

    public function unarchive(Request $request, Task $task): JsonResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        if ($task->archived_at) {
            $this->actions->unarchive($task, $user);
        }

        return $this->ok(ApiPresent::task($task->fresh(['project.goal'])));
    }

    public function reminders(Request $request, Task $task): JsonResponse
    {
        $this->own($task, $request->user());

        return $this->ok($task->reminders->map(fn (TaskReminder $r) => $r->present())->values());
    }

    /** {"kind": "at", "at": "2026-11-20 09:00"} · {"kind": "before", "before": "1d"} · {"kind": "bug", "every": 60} */
    public function addReminder(Request $request, Task $task): JsonResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        $data = $request->validate(Reminders::rules());
        if ($data['kind'] === 'at') {
            $data['at'] = $this->at($data['at'], $user);
        }

        return $this->ok(Reminders::add($task, $data, $this->ranking->now())->present(), 201);
    }

    public function removeReminder(Request $request, TaskReminder $reminder): JsonResponse
    {
        $this->own($reminder, $request->user());
        $reminder->delete();

        return $this->ok(['deleted' => true]);
    }

    /** {"minutes": 30} or {"until": "2026-11-12 09:00"}. */
    public function snoozeReminder(Request $request, TaskReminder $reminder): JsonResponse
    {
        $user = $request->user();
        $this->own($reminder, $user);
        $d = $request->validate(['minutes' => ['required_without:until', 'nullable', 'integer', 'between:1,1440'], 'until' => ['nullable', 'date']]);
        $now = $this->ranking->now();
        $at = isset($d['until']) ? $this->at($d['until'], $user) : $now->addMinutes((int) $d['minutes']);
        if ($at <= $now) {
            throw ValidationException::withMessages(['until' => 'Pick a time that hasn’t passed.']);
        }
        Reminders::snooze($reminder, $at);

        return $this->ok($reminder->fresh()->present());
    }

    /** One action on up to 100 open tasks: complete, cancel, delete, move (project_id) or due (due_at). */
    public function bulk(Request $request): JsonResponse
    {
        $user = $request->user();
        $d = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct'],
            'action' => ['required', Rule::in(['complete', 'cancel', 'delete', 'move', 'due', 'reopen', 'archive', 'unarchive', 'importance', 'urgency', 'status', 'follow_up'])],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('user_id', $user->id)],
            'due_at' => ['nullable', 'date'],
            'importance' => ['required_if:action,importance', 'integer', 'between:1,5'],
            'urgency' => ['required_if:action,urgency', 'integer', 'between:1,5'],
            'status' => ['required_if:action,status', Rule::in(Task::OPEN)],
            'follow_up_on' => ['nullable', 'date_format:Y-m-d'],
        ]);
        // The same tasks the selection bar would take: open ones to finish or change, finished ones to reopen or archive.
        $query = $user->tasks()->whereIn('id', $d['ids']);
        $tasks = (match ($d['action']) {
            'reopen' => $query->whereIn('status', Task::FINISHED),
            'archive' => $query->whereIn('status', Task::FINISHED)->whereNull('archived_at'),
            'unarchive' => $query->whereNotNull('archived_at'),
            'delete', 'move' => $query,
            default => $query->open(),
        })->get();
        $list = $this->ranking->rank($user);
        $due = $this->at($d['due_at'] ?? null, $user, true);
        foreach ($tasks as $t) {
            match ($d['action']) {
                'complete' => $this->actions->complete($t, $user, $list->scoreOf($t->id), false),
                'cancel' => $this->actions->cancel($t, $user),
                'delete' => $this->actions->delete($t, $user),
                'move' => $this->actions->update($t, $user, ['project_id' => $d['project_id'] ?? null]),
                'reopen' => $this->actions->reopen($t, $user),
                'archive' => $this->actions->archive($t, $user),
                'unarchive' => $this->actions->unarchive($t, $user),
                'importance', 'urgency', 'status' => $this->actions->update($t, $user, [$d['action'] => $d[$d['action']]]),
                'follow_up' => $this->actions->update($t, $user, ['follow_up_on' => $d['follow_up_on'] ?? null]),
                default => $this->actions->update($t, $user, ['due_at' => $due]),
            };
        }

        return $this->ok(['action' => $d['action'], 'ids' => $tasks->pluck('id')->values()]);
    }

    /** {"title"}: a subtask, ranked like any task (it starts with the parent's importance, urgency and due date). */
    public function subtask(Request $request, Task $task): JsonResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        $title = trim($request->validate(['title' => ['required', 'string', 'max:255']])['title']);
        $kid = $this->actions->create($user, [
            'title' => $title, 'parent_id' => $task->id, 'project_id' => $task->project_id, 'goal_id' => $task->project_id ? null : $task->goal_id,
            'importance' => $task->importance, 'urgency' => $task->urgency, 'due_at' => $task->due_at, 'est_hours' => 1,
        ]);

        return $this->ok(ApiPresent::task($kid->fresh(['project.goal', 'parent:id,title']), $this->ranking->rank($user)), 201);
    }

    /** {"add": id} or {"remove": id}: a task that waits on this one (it is set to start after it). */
    public function blocks(Request $request, Task $task): JsonResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        $d = $request->validate([
            'add' => ['required_without:remove', 'integer', Rule::exists('tasks', 'id')->where('user_id', $user->id)->whereNull('deleted_at')],
            'remove' => ['required_without:add', 'integer'],
        ]);
        $other = Task::where('user_id', $user->id)->findOrFail($d['add'] ?? $d['remove']);
        $ids = $other->dependencies()->pluck('tasks.id')->all();
        $this->actions->update($other, $user, ['depends_on' => isset($d['add']) ? array_values(array_unique([...$ids, $task->id])) : array_values(array_diff($ids, [$task->id]))]);

        return $this->ok($task->dependents()->get()->map(fn (Task $t) => ['id' => $t->id, 'title' => $t->title, 'status' => $t->status])->values());
    }

    /** Each project's best task by the formula, and the projects with nothing ready. */
    public function next(Request $request, NextActions $next): JsonResponse
    {
        return $this->ok($next->for($request->user(), fn (Task $t, $list) => ApiPresent::task($t, $list)));
    }

    public function templates(Request $request): JsonResponse
    {
        return $this->ok($request->user()->templates()->orderBy('name')->get()->map(fn (TaskTemplate $t) => [
            'id' => $t->id, 'name' => $t->name, 'title' => $t->title, 'steps' => $t->steps ?? [], 'importance' => $t->importance, 'urgency' => $t->urgency,
            'estHours' => $t->est_hours, 'projectId' => $t->project_id, 'used' => $t->used,
            'dueDays' => $t->due_days, 'dueTime' => $t->due_time, 'startDays' => $t->start_days,
        ])->values());
    }

    /** {"due_at": "2026-11-20", "project_id": 3}: a new task from the template (its steps undone). */
    public function useTemplate(Request $request, TaskTemplate $template): JsonResponse
    {
        $user = $request->user();
        $this->own($template, $user);
        $d = $request->validate(['due_at' => ['nullable', 'date'], 'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('user_id', $user->id)]]);
        $task = $this->actions->create($user, [
            'title' => $template->title, 'notes' => $template->notes, 'importance' => $template->importance, 'urgency' => $template->urgency,
            'est_hours' => $template->est_hours, 'project_id' => array_key_exists('project_id', $d) ? $d['project_id'] : $template->project_id,
            // The template's own dates count from today, unless a due date is sent.
            'due_at' => array_key_exists('due_at', $d) ? $this->at($d['due_at'], $user, true) : $template->datesFrom($this->today($user))['due']?->utc(),
            'available_from' => $template->datesFrom($this->today($user))['start']?->utc(),
            'repeat_unit' => $template->repeat_unit, 'repeat_every' => $template->repeat_every,
        ]);
        foreach ($template->steps ?? [] as $i => $title) {
            $task->steps()->create(['title' => $title, 'position' => $i]);
        }
        $template->increment('used');

        return $this->ok(ApiPresent::task($task->fresh(['project.goal', 'steps']), $this->ranking->rank($user)), 201);
    }

    // ---- The focus timer ----

    public function pauseFocus(Request $request): JsonResponse
    {
        $session = $this->running($request);
        if (! $session->paused_at) {
            $session->update(['paused_at' => $this->ranking->now()]);
        }

        return $this->ok($this->focus->state($request->user()));
    }

    public function resumeFocus(Request $request): JsonResponse
    {
        $session = $this->running($request);
        if ($session->paused_at) {
            $session->update(['paused_seconds' => $session->paused_seconds + $this->ranking->now()->getTimestamp() - $session->paused_at->getTimestamp(), 'paused_at' => null]);
        }

        return $this->ok($this->focus->state($request->user()));
    }

    /** Pomodoro: the next round now (skipping what is left of the break). */
    public function nextRound(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->focus->state($user); // a round that has run its time ends first
        $last = FocusActions::between($user);
        abort_unless($last && ! FocusActions::running($user), 404, 'No Pomodoro is between rounds.');
        $this->focus->nextRound($user, $last);

        return $this->ok($this->focus->state($user), 201);
    }

    /** Pomodoro: done for now. */
    public function endPomodoro(Request $request): JsonResponse
    {
        FocusActions::leaveBreaks($request->user());

        return $this->ok(['ended' => true]);
    }

    /** ?range=week|month&date=2026-11-11: sessions, and focus by hour of the day and by weekday. */
    public function history(Request $request, ReviewService $review): JsonResponse
    {
        $user = $request->user();
        $d = $request->validate(['range' => ['nullable', Rule::in(['week', 'month'])], 'date' => ['nullable', 'date_format:Y-m-d']]);
        $day = isset($d['date']) ? CarbonImmutable::parse($d['date'], $user->timezone)->startOfDay() : $this->today($user);

        return $this->ok($review->focus($user, $d['range'] ?? 'week', $day));
    }

    /** {"note": "Wrote 4.2"} (and/or "intention") on a session. */
    public function updateSession(Request $request, FocusSession $session): JsonResponse
    {
        $this->own($session, $request->user());
        $d = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:1000'], 'intention' => ['sometimes', 'nullable', 'string', 'max:240']]);
        $session->update(array_map(fn ($v) => is_string($v) ? (trim($v) ?: null) : $v, $d));

        return $this->ok(['id' => $session->id, 'note' => $session->note, 'intention' => $session->intention]);
    }

    private function running(Request $request): FocusSession
    {
        $session = FocusActions::running($request->user());
        abort_unless($session, 404, 'No focus session is running.');

        return $session;
    }
}
