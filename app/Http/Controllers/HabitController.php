<?php

namespace App\Http\Controllers;

use App\Models\Habit;
use App\Models\HabitEntry;
use App\Models\Routine;
use App\Models\User;
use App\Services\HabitStats;
use App\Services\RankingService;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Habits: Today, a week at a time, all habits, routines, archived; add, edit,
 * archive, delete; tick today, fix any past day, skip a day or a holiday.
 */
class HabitController extends Controller
{
    public function __construct(private readonly RankingService $ranking) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $today = $this->today($user);
        $q = $request->validate(['view' => ['nullable', 'string', 'max:12'], 'week' => ['nullable', 'date_format:Y-m-d'], 'habit' => ['nullable', 'integer']]);
        $view = $q['view'] ?? 'today';
        $thisMonday = $today->startOfWeek(CarbonImmutable::MONDAY);
        $monday = isset($q['week']) ? CarbonImmutable::parse($q['week'], $user->timezone)->startOfWeek(CarbonImmutable::MONDAY)->min($thisMonday) : $thisMonday;

        $routine = preg_match('/^r(\d+)$/', $view, $m) ? $user->routines()->find((int) $m[1]) : null;
        if (str_starts_with($view, 'r') && ! $routine) {
            $view = 'today';
        }
        $habits = $user->habits()
            ->when($view === 'archived', fn ($b) => $b->whereNotNull('archived_at'), fn ($b) => $b->whereNull('archived_at'))
            ->when($routine, fn ($b) => $b->whereIn('id', $routine->habits()->pluck('habits.id')))
            ->with(['entries' => fn ($e) => $e->where('day', '>=', $today->subDays(400)->toDateString()), 'project', 'pauses'])
            ->get();
        $order = $routine ? $routine->habits()->pluck('habits.id')->flip() : null;
        $rows = $habits
            ->sortBy(fn (Habit $h) => $order ? $order[$h->id] : ($h->at_time ? "0{$h->at_time}" : '1').mb_strtolower($h->name))
            ->map(function (Habit $h) use ($today, $view, $monday) {
                $s = new HabitStats($h, $today);

                return $s->row() + ['days' => $view === 'week' ? $s->span($monday, 7) : $s->span($today->subDays(6), 7)];
            })->values();

        $open = isset($q['habit']) ? $user->habits()->with(['entries', 'project', 'routines', 'pauses'])->find($q['habit']) : null;
        $active = $user->habits()->whereNull('archived_at')->with(['entries' => fn ($e) => $e->where('day', '>=', $today->subDays(14)->toDateString()), 'pauses'])->get();
        $groups = $active->mapWithKeys(fn (Habit $h) => [$h->id => (new HabitStats($h, $today))->row()['group']]);

        return Inertia::render('habits/index', [
            'view' => $view,
            'today' => $today->toDateString(),
            'week' => ['start' => $monday->toDateString(), 'isThisWeek' => $monday->equalTo($thisMonday), 'days' => collect(range(0, 6))->map(fn ($i) => $monday->addDays($i)->toDateString())],
            'habits' => $rows,
            'routine' => $routine ? ['id' => $routine->id, 'name' => $routine->name, 'habitIds' => $routine->habits()->pluck('habits.id')] : null,
            'habit' => $open ? (new HabitStats($open, $today))->detail() : null,
            'menu' => [
                'today' => $groups->filter(fn ($g) => $g === 'todo')->count(),
                'all' => $active->count(),
                'archived' => $user->habits()->whereNotNull('archived_at')->count(),
                'routines' => $user->routines()->with('habits:id')->get()->map(fn (Routine $r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'done' => $r->habits->filter(fn ($h) => ($groups[$h->id] ?? null) === 'done')->count(),
                    'of' => $r->habits->filter(fn ($h) => in_array($groups[$h->id] ?? 'off', ['todo', 'done'], true))->count(),
                ])->values(),
            ],
            'choices' => $active->sortBy(fn ($h) => mb_strtolower($h->name))->map(fn ($h) => ['id' => $h->id, 'name' => $h->name])->values(),
            'projects' => $user->projects()->whereNull('archived_at')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $habit = $user->habits()->create($this->fields($request, $user));
        // Back where the dialog was opened, minus "?new=1" (Ctrl K's New habit), or a reload would open it again.
        $back = rtrim(preg_replace('/([?&])new=1(&|$)/', '$1', url()->previous()), '?&');

        return redirect()->to($back)->with('toast', "Added “{$habit->name}”")->with('undo', $this->undo('delete-habit', $habit->id));
    }

    public function update(Request $request, Habit $habit): RedirectResponse
    {
        $user = $request->user();
        $this->own($habit, $user);
        $old = HabitStats::form($habit);
        $habit->update($this->fields($request, $user));

        return back()->with('toast', "Saved “{$habit->name}”")->with('undo', $this->undo('patch-habit', $habit->id, $old));
    }

    public function archive(Request $request, Habit $habit): RedirectResponse
    {
        $this->own($habit, $request->user());
        $habit->update(['archived_at' => now()]);

        return back()->with('toast', "Archived “{$habit->name}”")->with('undo', $this->undo('unarchive-habit', $habit->id));
    }

    public function unarchive(Request $request, Habit $habit): RedirectResponse
    {
        $this->own($habit, $request->user());
        $habit->update(['archived_at' => null]);

        return back()->with('toast', "“{$habit->name}” is back")->with('undo', $this->undo('archive-habit', $habit->id));
    }

    /** Deleted habits keep their days, so Undo brings back the streak too. */
    public function destroy(Request $request, Habit $habit): RedirectResponse
    {
        $this->own($habit, $request->user());
        $habit->delete();

        return back()->with('toast', "Deleted “{$habit->name}”")->with('undo', $this->undo('restore-habit', $habit->id));
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $habit = Habit::onlyTrashed()->findOrFail($id);
        $this->own($habit, $request->user());
        $habit->restore();

        return back()->with('toast', "“{$habit->name}” is back");
    }

    /**
     * One tap on a habit today. A once-a-day habit is ticked or unticked; a counted
     * habit (8 glasses of water) goes up by one, and back to 0 after the target; a
     * minutes habit is filled (or an amount is given); a limit goes up by one; an
     * avoid habit flips between "kept" and "it happened".
     */
    public function check(Request $request, Habit $habit): RedirectResponse
    {
        $user = $request->user();
        $this->own($habit, $user);
        $today = $this->today($user)->toDateString();
        $entry = $habit->entries()->whereDate('day', $today)->first();
        $before = $this->dayValues($entry, $today);
        $count = $entry && ! $entry->skipped ? $entry->count : 0;
        $next = $request->has('count') ? max(0, min(10000, $request->integer('count'))) : match ($habit->kind) {
            'limit' => $count + 1,
            'avoid' => $count ? 0 : 1,
            'minutes' => $count >= $habit->target ? 0 : $habit->target,
            default => $count >= $habit->target ? 0 : $count + 1,
        };
        $this->setDay($habit, $today, $next, false);

        $toast = match (true) {
            $habit->kind === 'avoid' => $next ? "{$habit->name}: it happened today" : "{$habit->name}: kept today",
            $habit->kind === 'limit' => "{$habit->name}: {$next} of at most {$habit->target}".($next > $habit->target ? ' · over the limit' : ''),
            $habit->kind === 'minutes' => "{$habit->name}: {$next} of {$habit->target} min",
            $habit->target > 1 => "{$habit->name}: {$next} of {$habit->target}",
            default => $next ? "Done: {$habit->name}" : "Not done: {$habit->name}",
        };

        return back()->with('toast', $toast)->with('undo', $this->undo('put-habit-day', $habit->id, $before));
    }

    /** Pause from today, until a day or until resumed: paused days are neither due nor missed, so the streak waits. */
    public function pause(Request $request, Habit $habit): RedirectResponse
    {
        $user = $request->user();
        $this->own($habit, $user);
        $today = $this->today($user);
        $until = $request->validate(['until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$today->toDateString()]])['until'] ?? null;
        if ($habit->pauseOn($today->toDateString())) {
            return back();
        }
        $pause = $habit->pauses()->create(['from' => $today->toDateString(), 'until' => $until]);

        return back()->with('toast', "Paused “{$habit->name}”".($until ? ' until '.CarbonImmutable::parse($until)->format('D j M') : ''))
            ->with('undo', $this->undo('put-habit-pause', $habit->id, ['pause_id' => $pause->id, 'remove' => true]));
    }

    /** Resume: the pause ends yesterday (or goes, if it began today). */
    public function resume(Request $request, Habit $habit): RedirectResponse
    {
        $user = $request->user();
        $this->own($habit, $user);
        $today = $this->today($user);
        $pause = $habit->pauseOn($today->toDateString());
        if (! $pause) {
            return back();
        }
        $before = ['pause_id' => $pause->id, 'from' => $pause->from->toDateString(), 'until' => $pause->until?->toDateString()];
        if ($pause->from->toDateString() === $today->toDateString()) {
            $pause->delete();
        } else {
            $pause->update(['until' => $today->subDay()->toDateString()]);
        }

        return back()->with('toast', "“{$habit->name}” is back")->with('undo', $this->undo('put-habit-pause', $habit->id, $before));
    }

    /** Undo of pause / resume: the pause as it was (or gone). */
    public function putPause(Request $request, Habit $habit): RedirectResponse
    {
        $this->own($habit, $request->user());
        $d = $request->validate([
            'pause_id' => ['nullable', 'integer'], 'remove' => ['sometimes', 'boolean'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $pause = isset($d['pause_id']) ? $habit->pauses()->find($d['pause_id']) : null;
        if (! empty($d['remove'])) {
            $pause?->delete();
        } elseif (isset($d['from'])) {
            $pause ? $pause->update(['from' => $d['from'], 'until' => $d['until'] ?? null]) : $habit->pauses()->create(['from' => $d['from'], 'until' => $d['until'] ?? null]);
        }

        return back()->with('toast', 'Undone');
    }

    /** Any day up to today, set exactly: how many (0 = not done), or skipped. Fixes a forgotten tick. */
    public function day(Request $request, Habit $habit): RedirectResponse
    {
        $user = $request->user();
        $this->own($habit, $user);
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$this->today($user)->addDays(60)->toDateString()],
            'count' => ['required', 'integer', 'between:0,10000'],
            'skipped' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);
        $skipped = (bool) ($data['skipped'] ?? false);
        if (! $skipped && $data['date'] > $this->today($user)->toDateString()) {
            return back()->withErrors(['date' => 'Only skipping can be set ahead.']);
        }
        $entry = $habit->entries()->whereDate('day', $data['date'])->first();
        $before = $this->dayValues($entry, $data['date']);
        $note = array_key_exists('note', $data) ? (trim((string) $data['note']) ?: null) : false;
        $this->setDay($habit, $data['date'], $skipped ? 0 : (int) $data['count'], $skipped, $note);
        $when = CarbonImmutable::parse($data['date'])->format('D j M');
        $what = match (true) {
            $skipped => 'skipped',
            $habit->less() => $habit->achieved((int) $data['count']) ? 'kept' : ($habit->kind === 'avoid' ? 'it happened' : 'over the limit'),
            $habit->achieved((int) $data['count']) => 'done',
            $data['count'] > 0 => "{$data['count']} of {$habit->target}",
            default => 'not done',
        };

        return back()->with('toast', "{$habit->name} · {$when}: {$what}")->with('undo', $this->undo('put-habit-day', $habit->id, $before));
    }

    /** Skip today, or every due day from today until a date (a holiday). Days already done stay done. */
    public function skip(Request $request, Habit $habit): RedirectResponse
    {
        $user = $request->user();
        $this->own($habit, $user);
        $today = $this->today($user);
        $until = $request->validate([
            'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$today->toDateString(), 'before_or_equal:'.$today->addDays(90)->toDateString()],
        ])['until'] ?? $today->toDateString();
        $last = CarbonImmutable::parse($until, $user->timezone);

        $days = [];
        $taken = $habit->entries()->where('day', '>=', $today->toDateString())->get()->keyBy(fn ($e) => $e->day->toDateString());
        for ($d = $today; $d <= $last; $d = $d->addDay()) {
            $e = $taken[$d->toDateString()] ?? null;
            if (! $habit->isDue($d) || ($e && ($e->skipped || $e->count > 0))) {
                continue;
            }
            $this->setDay($habit, $d->toDateString(), 0, true);
            $days[] = $d->toDateString();
        }
        $toast = $until === $today->toDateString() ? "Skipped “{$habit->name}” today" : "Skipped “{$habit->name}” until ".$last->format('D j M');

        return back()->with('toast', $toast)->with('undo', $this->undo('unskip-habit', $habit->id, ['days' => $days]));
    }

    /** Undo of a skip: those days are open again. */
    public function unskip(Request $request, Habit $habit): RedirectResponse
    {
        $this->own($habit, $request->user());
        $days = $request->validate(['days' => ['present', 'array', 'max:120'], 'days.*' => ['date_format:Y-m-d']])['days'];
        $habit->entries()->where('skipped', true)->get()->filter(fn ($e) => in_array($e->day->toDateString(), $days, true))->each->delete();

        return back()->with('toast', 'Undone');
    }

    /** A day set exactly. $note: a new note, null to clear it, false to leave it as it is. A day with nothing (no count, not skipped, no note) has no entry. */
    public static function setDay(Habit $habit, string $date, int $count, bool $skipped, string|null|false $note = false): void
    {
        $entry = $habit->entries()->whereDate('day', $date)->first();
        $note = $note === false ? $entry?->note : $note;
        if ($count === 0 && ! $skipped && $note === null) {
            $entry?->delete();
        } elseif ($entry) {
            $entry->update(['count' => $count, 'skipped' => $skipped, 'note' => $note]);
        } else {
            $habit->entries()->create(['day' => $date, 'count' => $count, 'skipped' => $skipped, 'note' => $note]);
        }
    }

    /** A day as the "put it back" of Undo sends it. */
    private function dayValues(?HabitEntry $entry, string $date): array
    {
        return ['date' => $date, 'count' => $entry && ! $entry->skipped ? $entry->count : 0, 'skipped' => (bool) $entry?->skipped, 'note' => $entry?->note];
    }

    /** The habit dialog: name, kind, how often, how much a day, an optional time on the Planner, project. */
    public static function fields(Request $request, User $user): array
    {
        $request->mergeIfMissing(['kind' => 'do']);
        $d = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(Habit::KINDS)],
            'detail' => ['nullable', 'string', 'max:120'],
            // A limit or something to avoid is a matter of each day, not of runs a week or month.
            'schedule' => ['required', Rule::in(['daily', 'days', 'weekly', 'monthly', 'every']), Rule::when(in_array($request->input('kind'), ['limit', 'avoid'], true), Rule::in(['daily', 'days', 'every']))],
            'days' => ['exclude_unless:schedule,days', 'required', 'array', 'min:1', 'max:7'],
            'days.*' => ['integer', 'between:1,7', 'distinct'],
            'per_week' => ['exclude_unless:schedule,weekly', 'required', 'integer', 'between:1,7'],
            'per_month' => ['exclude_unless:schedule,monthly', 'required', 'integer', 'between:1,31'],
            'every_days' => ['exclude_unless:schedule,every', 'required', 'integer', 'between:2,365'],
            'starts_on' => ['exclude_unless:schedule,every', 'nullable', 'date_format:Y-m-d'],
            'target' => ['exclude_if:kind,avoid', 'required', 'integer', Rule::when($request->input('kind') === 'minutes', 'between:1,1440', 'between:1,1000')],
            'unit' => ['nullable', 'string', 'max:24'],
            'at_time' => ['nullable', 'date_format:H:i', 'prohibited_if:schedule,weekly', 'prohibited_if:schedule,monthly', 'prohibited_if:kind,limit', 'prohibited_if:kind,avoid'],
            'minutes' => ['nullable', 'required_with:at_time', 'integer', 'between:5,240'],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('user_id', $user->id)],
        ], [
            'at_time.prohibited_if' => 'Only a habit for set days can have a time on the Planner.',
            'schedule.in' => 'A limit or something to avoid is for every day, set days, or every few days.',
        ], ['per_week' => 'times a week', 'per_month' => 'times a month', 'every_days' => 'every how many days', 'at_time' => 'time', 'target' => 'amount a day']);

        $days = $d['schedule'] === 'days' ? array_values(array_unique(array_map('intval', $d['days']))) : null;
        if ($days) {
            sort($days);
        }
        $kind = $d['kind'];
        $target = $kind === 'avoid' ? 1 : (int) $d['target'];

        return [
            'name' => $d['name'],
            'kind' => $kind,
            'detail' => $d['detail'] ?? null,
            'days' => $days && count($days) < 7 ? $days : null,
            'per_week' => $d['schedule'] === 'weekly' ? (int) $d['per_week'] : null,
            'per_month' => $d['schedule'] === 'monthly' ? (int) $d['per_month'] : null,
            'every_days' => $d['schedule'] === 'every' ? (int) $d['every_days'] : null,
            'starts_on' => $d['schedule'] === 'every' ? ($d['starts_on'] ?? app(Clock::class)->now()->timezone($user->timezone)->toDateString()) : null,
            'target' => $target,
            'unit' => $kind === 'minutes' ? 'min' : ($kind !== 'avoid' && ($target > 1 || $kind === 'limit') ? ($d['unit'] ?? null) : null),
            'at_time' => $d['at_time'] ?? null,
            'minutes' => isset($d['at_time']) ? (int) $d['minutes'] : null,
            'project_id' => $d['project_id'] ?? null,
        ];
    }

    private function today(User $user): CarbonImmutable
    {
        return $this->ranking->now()->timezone($user->timezone)->startOfDay();
    }
}
