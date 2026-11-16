<?php

namespace App\Http\Controllers;

use App\Models\PlannerBlock;
use App\Models\Task;
use App\Models\User;
use App\Services\PlanBuilder;
use App\Services\PlannerOverlaps;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Booked time on the Planner: plan a day or a week, book one task, place, move and
 * remove blocks, and the working hours planning keeps to. Every change has Undo.
 */
class PlannerBlockController extends Controller
{
    public function __construct(private readonly PlanBuilder $builder) {}

    /** "Plan day" / "Plan week", or "Book" for one task: free time filled with work, by rank. */
    public function plan(Request $request): RedirectResponse
    {
        $user = $request->user();
        $d = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'span' => ['required', Rule::in(['day', 'week'])],
            'task_id' => ['nullable', 'integer'],
        ]);
        $date = CarbonImmutable::parse($d['date'], $user->timezone);
        $first = $d['span'] === 'week' ? $date->startOfWeek(CarbonImmutable::MONDAY) : $date;
        $days = array_map(fn ($i) => $first->addDays($i)->toDateString(), range(0, $d['span'] === 'week' ? 6 : 0));
        $task = isset($d['task_id']) ? $user->tasks()->whereIn('status', Task::OPEN)->findOrFail($d['task_id']) : null;

        $r = $this->builder->plan($user, $days, $task?->id);
        $short = count($r['short']);
        // Work is only booked before it's due (an overdue task: as soon as possible).
        $dueBefore = $task?->due_at && $task->due_at > app(Clock::class)->now() && $task->due_at <= $first->utc();
        // The day (or every day of the week) already holds its hours of work a day: say that, not "no free time".
        $full = ($r['full'] ?? false) ? ($d['span'] === 'week' ? 'Every day this week' : $date->format('D')).' already has its '.self::dur($r['limit']).' of work' : null;
        $text = match (true) {
            $task !== null && $r['minutes'] > 0 => 'Booked '.self::dur($r['minutes'])." for “{$task->title}”",
            $task !== null && $dueBefore => "“{$task->title}” is due before then",
            $task !== null && $full !== null => "{$full}: no room for “{$task->title}”",
            $task !== null => "No free time left for “{$task->title}”",
            $r['minutes'] > 0 => 'Booked '.self::dur($r['minutes']).($short ? " · {$short} didn’t fit" : ''),
            $short > 0 && $full !== null => $full,
            $short > 0 => 'No free time left',
            default => 'Nothing to book',
        };
        $back = back()->with('toast', $text);

        return $r['added'] || $r['removed'] ? $back->with('undo', $this->undo('unplan', 0, ['added' => $r['added'], 'removed' => $r['removed']])) : $back;
    }

    /** Undo of planning: the blocks it placed go, the ones it replaced come back. */
    public function unplan(Request $request): RedirectResponse
    {
        $user = $request->user();
        $d = $request->validate(['added' => ['nullable', 'array'], 'added.*' => ['integer'], 'removed' => ['nullable', 'array'], 'removed.*' => ['integer']]);
        PlannerBlock::withTrashed()->where('user_id', $user->id)->whereIn('id', $d['added'] ?? [])->forceDelete();
        PlannerBlock::onlyTrashed()->where('user_id', $user->id)->whereIn('id', $d['removed'] ?? [])->restore();

        return back()->with('toast', 'Plan undone');
    }

    /**
     * A task dropped on the grid, time for a whole project, or reserved time ("Gym"):
     * booked there, and pinned (planning again leaves it alone and books around it).
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $kind = $request->validate(['kind' => ['nullable', Rule::in(PlannerBlock::KINDS)]])['kind'] ?? 'task';
        $what = match ($kind) {
            'task' => ['task_id' => $user->tasks()->whereIn('status', Task::OPEN)->findOrFail($request->validate(['task_id' => ['required', 'integer']])['task_id'])->id],
            'project' => $request->validate([
                'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->where('user_id', $user->id)->whereNull('archived_at')->whereNull('deleted_at')],
            ], ['project_id.required' => 'Choose a project.'], ['project_id' => 'project']),
            'reserved' => ['label' => trim($request->validate(['label' => ['required', 'string', 'max:80']], ['label.required' => 'Give it a name.'], ['label' => 'name'])['label'])],
        };
        $block = $user->plannerBlocks()->create(['kind' => $kind, ...$what, ...$this->times($request, $user), 'pinned' => true]);
        $verb = $kind === 'reserved' ? 'Reserved' : 'Booked';

        return back()->with('toast', "{$verb} “{$block->title()}”".$this->overlaps($user, $block))->with('undo', $this->undo('remove-block', $block->id));
    }

    /** Moved (or resized) by the user: it stays where they put it. A locked block moves only with "anyway". */
    public function update(Request $request, PlannerBlock $block): RedirectResponse
    {
        $user = $request->user();
        $this->own($block, $user);
        self::checkLock($request, $block);
        $local = $block->starts_at->timezone($user->timezone);
        $before = ['date' => $local->toDateString(), 'start' => $local->format('H:i'), 'minutes' => $block->minutes(), 'pinned' => $block->pinned, 'anyway' => true];
        $pinned = $request->validate(['pinned' => ['nullable', 'boolean']])['pinned'] ?? true;
        $block->update([...$this->times($request, $user, $block->minutes()), 'pinned' => $pinned]);
        $local = $block->starts_at->timezone($user->timezone);
        $resized = $local->toDateString() === $before['date'] && $local->format('H:i') === $before['start'] && $block->minutes() !== $before['minutes'];
        // For Review: how often planned time was moved.
        if ($block->wasChanged(['starts_at', 'ends_at'])) {
            DB::table('planner_moves')->insert(['user_id' => $user->id, 'planner_block_id' => $block->id, 'moved_at' => app(Clock::class)->now()->utc()]);
        }
        $verb = $resized ? 'Resized' : ($block->minutes() !== $before['minutes'] ? 'Changed' : 'Moved');

        return back()->with('toast', "{$verb} “{$block->title()}”".$this->overlaps($user, $block))->with('undo', $this->undo('patch-block', $block->id, $before));
    }

    /** A locked block stays put unless the move says "anyway" (the Planner asks first). */
    public static function checkLock(Request $request, PlannerBlock $block): void
    {
        if ($block->locked && ! $request->boolean('anyway')) {
            throw ValidationException::withMessages(['anyway' => "“{$block->title()}” is locked."]);
        }
    }

    /** Lock or unlock: a locked block asks before it is moved or resized. */
    public function lock(Request $request, PlannerBlock $block): RedirectResponse
    {
        $this->own($block, $request->user());
        $locked = $request->validate(['locked' => ['required', 'boolean']])['locked'];
        $block->update(['locked' => $locked]);

        return back()->with('toast', ($locked ? 'Locked' : 'Unlocked')." “{$block->title()}”")->with('undo', $this->undo('lock-block', $block->id, ['locked' => ! $locked]));
    }

    /** "Change task": the same time, for another open task. */
    public function retask(Request $request, PlannerBlock $block): RedirectResponse
    {
        $user = $request->user();
        $this->own($block, $user);
        if ($block->kind !== 'task') {
            throw ValidationException::withMessages(['task_id' => 'Only time booked for a task can change its task.']);
        }
        $task = $user->tasks()->whereIn('status', Task::OPEN)->findOrFail($request->validate(['task_id' => ['required', 'integer']])['task_id']);
        $before = $block->task_id;
        $block->update(['task_id' => $task->id]);

        return back()->with('toast', "{$block->starts_at->timezone($user->timezone)->format('D g:i a')} is now for “{$task->title}”")
            ->with('undo', $this->undo('retask-block', $block->id, ['task_id' => $before]));
    }

    public function destroy(Request $request, PlannerBlock $block): RedirectResponse
    {
        $this->own($block, $request->user());
        $block->delete();

        return back()->with('toast', "Removed “{$block->title()}” from the plan")->with('undo', $this->undo('restore-block', $block->id));
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $block = PlannerBlock::withTrashed()->findOrFail($id);
        $this->own($block, $request->user());
        $block->restore();

        return back()->with('toast', "“{$block->title()}” is back on the plan");
    }

    /** Working hours, Monday first: the time "Plan" may fill on each day (none = a day off). */
    public function hours(Request $request): RedirectResponse
    {
        $user = $request->user();
        $request->validate([
            'hours' => ['required', 'array', 'size:7'],
            'hours.*' => ['nullable', 'array'],
            'hours.*.start' => ['nullable', 'date_format:H:i'],
            'hours.*.end' => ['nullable', 'date_format:H:i'],
        ]);
        // Read by position: validated() can reorder a list that mixes days off (null) with times.
        $raw = $request->input('hours');
        $hours = array_map(fn ($i) => empty($raw[$i]) ? null : ['start' => $raw[$i]['start'] ?? '', 'end' => $raw[$i]['end'] ?? ''], range(0, 6));
        foreach ($hours as $i => $h) {
            if ($h && ($h['start'] === '' || $h['end'] <= $h['start'])) {
                throw ValidationException::withMessages(["hours.{$i}.end" => 'The end has to be after the start.']);
            }
        }
        $before = PlanBuilder::hours($user);
        $user->update(['planner_hours' => $hours]);

        return back()->with('toast', 'Working hours saved')->with('undo', $this->undo('put-hours', 0, ['hours' => $before]));
    }

    /** " · overlaps …" when the block now shares its time with something else (a warning only). */
    private function overlaps(User $user, PlannerBlock $block): string
    {
        return PlannerOverlaps::note($user, PlannerOverlaps::find($user, CarbonImmutable::instance($block->starts_at), CarbonImmutable::instance($block->ends_at), $block->id));
    }

    /** "date" + "start" (local) and "minutes" → the stored start and end. */
    private function times(Request $request, User $user, ?int $minutes = null): array
    {
        $d = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'start' => ['required', 'date_format:H:i'],
            'minutes' => [$minutes ? 'nullable' : 'required', 'integer', 'min:5', 'max:720'],
        ]);
        $start = CarbonImmutable::parse("{$d['date']} {$d['start']}", $user->timezone);

        return ['starts_at' => $start->utc(), 'ends_at' => $start->addMinutes((int) ($d['minutes'] ?? $minutes))->utc()];
    }

    /** 90 → "1 h 30 min". */
    public static function dur(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return trim(($h ? "{$h} h " : '').($m ? "{$m} min" : ''));
    }
}
