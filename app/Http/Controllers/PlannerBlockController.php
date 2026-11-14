<?php

namespace App\Http\Controllers;

use App\Models\PlannerBlock;
use App\Models\Task;
use App\Models\User;
use App\Services\PlanBuilder;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $text = match (true) {
            $task !== null && $r['minutes'] > 0 => 'Booked '.self::dur($r['minutes'])." for “{$task->title}”",
            $task !== null && $dueBefore => "“{$task->title}” is due before then",
            $task !== null => "No free time left for “{$task->title}”",
            $r['minutes'] > 0 => 'Booked '.self::dur($r['minutes']).($short ? " · {$short} didn’t fit" : ''),
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

    /** A task dropped on the grid: booked there, and pinned (planning again leaves it alone). */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $task = $user->tasks()->whereIn('status', Task::OPEN)->findOrFail($request->validate(['task_id' => ['required', 'integer']])['task_id']);
        $block = $user->plannerBlocks()->create(['task_id' => $task->id, ...$this->times($request, $user), 'pinned' => true]);

        return back()->with('toast', "Booked “{$task->title}”")->with('undo', $this->undo('remove-block', $block->id));
    }

    /** Moved (or resized) by the user: it stays where they put it. */
    public function update(Request $request, PlannerBlock $block): RedirectResponse
    {
        $user = $request->user();
        $this->own($block, $user);
        $local = $block->starts_at->timezone($user->timezone);
        $before = ['date' => $local->toDateString(), 'start' => $local->format('H:i'), 'minutes' => $block->minutes(), 'pinned' => $block->pinned];
        $pinned = $request->validate(['pinned' => ['nullable', 'boolean']])['pinned'] ?? true;
        $block->update([...$this->times($request, $user, $block->minutes()), 'pinned' => $pinned]);

        return back()->with('toast', "Moved “{$block->title()}”")->with('undo', $this->undo('patch-block', $block->id, $before));
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
