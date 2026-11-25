<?php

namespace App\Http\Controllers;

use App\Models\PersonDate;
use App\Models\Task;
use App\Models\TaskReminder;
use App\Services\RankingService;
use App\Services\Reminders;
use Carbon\CarbonImmutable;
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

    /** Reminders that are due and not shown for their time yet. "Bug me" ones come back every so often. */
    public function due(Request $request): JsonResponse
    {
        $user = $request->user();
        $now = $this->ranking->now();
        $due = TaskReminder::where('user_id', $user->id)
            ->whereNotNull('next_at')->where('next_at', '<=', $now)
            ->where(fn ($q) => $q->whereNull('sent_at')->orWhereColumn('sent_at', '<', 'next_at'))
            ->whereHas('task', fn ($t) => $t->open())
            ->with('task')->orderBy('next_at')->limit(20)->get();
        foreach ($due as $r) {
            // A "Bug me" one is next due a while from now, so missed ones never pile up.
            $r->update($r->kind === 'bug' ? ['sent_at' => $now, 'next_at' => $now->addMinutes($r->every_minutes ?: 60)] : ['sent_at' => $now]);
        }

        // Birthdays and other dates: each reminder at its time, the days before that were asked for, once for each
        // time the date comes round (several gone by while Velocity was closed: one card). A snoozed one comes back later.
        $tz = $user->timezone;
        $today = $now->timezone($tz)->startOfDay();
        $dates = PersonDate::where(fn ($q) => $q->whereNotNull('remind_days')->orWhereNotNull('reminders'))
            ->whereHas('person', fn ($p) => $p->where('user_id', $user->id)->whereNull('archived_at')->whereNull('deleted_at'))
            ->with('person')->get()
            ->map(function (PersonDate $d) use ($today, $now, $tz) {
                $next = $d->next($today);
                if (! $next) {
                    return null;
                }
                if ($d->snoozed_until) {
                    return $now >= $d->snoozed_until ? ['d' => $d, 'next' => $next, 'keys' => []] : null;
                }
                $keys = [];
                foreach ($d->reminderList() as $r) {
                    $key = $next->toDateString().'#'.$r['days'];
                    $at = CarbonImmutable::parse($next->subDays($r['days'])->toDateString().' '.$r['at'], $tz);
                    if ($now >= $at && ! in_array($key, $d->reminded ?? [], true)) {
                        $keys[] = $key;
                    }
                }

                return $keys ? ['d' => $d, 'next' => $next, 'keys' => $keys] : null;
            })
            ->filter()->take(20)->values();
        foreach ($dates as $x) {
            $x['d']->update([
                'reminded' => array_slice(array_values(array_unique([...($x['d']->reminded ?? []), ...$x['keys']])), -24),
                'reminded_for' => $x['next']->toDateString(),
                'snoozed_until' => null,
            ]);
        }

        // Follow-ups: once for each follow-up day, from the time picked on (one gone by while Velocity was closed: once, late).
        $followUps = collect();
        if ($user->follow_up_remind_at && ! $user->fyp_mode) {
            $day = $today->toDateString();
            $followUps = $user->tasks()->open()->whereNotNull('follow_up_on')->whereDate('follow_up_on', '<=', $day)
                ->whereDate('follow_up_on', '>=', $today->subDays(14)->toDateString())
                ->where(fn ($q) => $q->whereNull('follow_up_reminded_on')->orWhereColumn('follow_up_reminded_on', '!=', 'follow_up_on'))
                ->orderBy('follow_up_on')->limit(20)->get()
                ->filter(fn (Task $t) => $t->follow_up_on->toDateString() < $day || $now >= CarbonImmutable::parse("{$day} {$user->follow_up_remind_at}", $tz))
                ->values();
            foreach ($followUps as $t) {
                $t->update(['follow_up_reminded_on' => $t->follow_up_on]);
            }
        }

        return response()->json([
            'followUps' => $followUps->map(fn (Task $t) => [
                'taskId' => $t->id,
                'title' => $t->title,
                'who' => $t->waiting_for,
                'late' => (int) round(CarbonImmutable::parse($t->follow_up_on->toDateString(), $tz)->diffInDays($today, false)),
            ])->values(),
            'reminders' => $due->map(fn (TaskReminder $r) => [
                'id' => $r->id,
                'taskId' => $r->task_id,
                'kind' => $r->kind,
                'title' => $r->task->title,
                'remindAt' => $r->sent_at->toIso8601ZuluString(),
                'dueAt' => $r->task->due_at?->toIso8601ZuluString(),
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

    /** Add a reminder from the task panel: at a time, before the due date, or "Bug me". */
    public function store(Request $request, Task $task): RedirectResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        $data = $request->validate(Reminders::rules());
        if ($data['kind'] === 'at') {
            $data['at'] = $this->fromLocal($data['at'], $user);
        }
        $reminder = Reminders::add($task, $data, $this->ranking->now());

        return back()->with('toast', $reminder->kind === 'bug' ? 'Bugging you about it' : 'Reminder added')
            ->with('undo', $this->undo('delete-reminder', $reminder->id));
    }

    /** Remove a reminder ("Stop bugging" for a "Bug me" one). */
    public function destroy(Request $request, TaskReminder $reminder): RedirectResponse
    {
        $this->own($reminder, $request->user());
        $reminder->delete();

        return back()->with('toast', $reminder->kind === 'bug' ? 'Stopped bugging' : 'Reminder removed')
            ->with('undo', $this->undo('restore-reminder', $reminder->id));
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $reminder = TaskReminder::onlyTrashed()->findOrFail($id);
        $this->own($reminder, $request->user());
        $reminder->restore();

        return back()->with('toast', 'Reminder back');
    }

    /** "Snooze": show it again in a while, or at a time picked ("tomorrow 9:00 am"). */
    public function snooze(Request $request, TaskReminder $reminder): RedirectResponse
    {
        $user = $request->user();
        $this->own($reminder, $user);
        $data = $request->validate(['minutes' => ['required_without:until', 'nullable', 'integer', 'between:1,1440'], 'until' => ['nullable', 'date']]);
        $now = $this->ranking->now();
        $at = isset($data['until']) ? $this->fromLocal($data['until'], $user) : $now->addMinutes((int) $data['minutes']);
        if ($at <= $now) {
            return back()->withErrors(['until' => 'Pick a time that hasn’t passed.']);
        }
        Reminders::snooze($reminder, $at);
        $local = $at->timezone($user->timezone);
        $minutes = (int) round($now->diffInMinutes($at));
        $toast = $minutes < 60 ? "Reminder in {$minutes} min"
            : ($minutes < 24 * 60 && $local->isSameDay($now->timezone($user->timezone)) ? 'Reminder in '.round($minutes / 60, 1).' h' : 'Reminder '.$local->format('D j M, g:i a'));

        return back()->with('toast', str_replace('.0 h', ' h', $toast));
    }
}
