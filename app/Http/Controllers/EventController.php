<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\User;
use App\Services\PlannerOverlaps;
use App\Services\QuickAddParser;
use App\Services\RankingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Classes and events: add, change, remove (one week of a weekly event, or all of it), each with Undo. */
class EventController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $event = $user->events()->create($this->fields($request, $user));

        return back()->with('toast', "Added “{$event->title}”".$this->overlaps($user, $event))->with('undo', $this->undo('delete-event', $event->id));
    }

    /** Quick add's Event tab: what the chosen day and times overlap ("Overlaps Data Mining lecture …"). */
    public function overlapsAt(Request $request): JsonResponse
    {
        $user = $request->user();
        $d = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'start_time' => ['required', 'date_format:H:i'], 'end_time' => ['required', 'date_format:H:i']]);
        $start = CarbonImmutable::parse("{$d['date']} {$d['start_time']}", $user->timezone);
        $end = CarbonImmutable::parse("{$d['date']} {$d['end_time']}", $user->timezone);
        $end = $end <= $start ? $end->addDay() : $end;

        return response()->json(['note' => ltrim(PlannerOverlaps::note($user, PlannerOverlaps::find($user, $start, $end)), ' ·')]);
    }

    /** " · overlaps …" for a timed event's (first) time; all-day events take no time. */
    private function overlaps(User $user, Event $event): string
    {
        if ($event->all_day) {
            return '';
        }

        return PlannerOverlaps::note($user, PlannerOverlaps::find($user, CarbonImmutable::instance($event->starts_at), CarbonImmutable::instance($event->ends_at), null, $event->id));
    }

    /** Quick add's Event tab: what a typed line means ("Dentist tomorrow 3-4pm #personal"), before it is saved. */
    public function preview(Request $request, QuickAddParser $parser, RankingService $ranking): JsonResponse
    {
        $text = $request->validate(['text' => ['required', 'string', 'max:255']])['text'];

        return response()->json($parser->parseEvent($text, $ranking->now()->timezone($request->user()->timezone)));
    }

    public function update(Request $request, Event $event): RedirectResponse
    {
        $user = $request->user();
        $this->own($event, $user);
        $before = $event->formValues($user->timezone);
        $event->update($this->fields($request, $user));

        return back()->with('toast', 'Event saved'.$this->overlaps($user, $event))->with('undo', $this->undo('patch-event', $event->id, $before));
    }

    /** Remove the whole event, or (for a weekly one) only the week of `date`. */
    public function destroy(Request $request, Event $event): RedirectResponse
    {
        $this->own($event, $request->user());
        $data = $request->validate(['scope' => ['nullable', Rule::in(['one', 'all'])], 'date' => ['nullable', 'date_format:Y-m-d']]);
        if ($event->repeat_weekly && ($data['scope'] ?? 'all') === 'one' && isset($data['date'])) {
            $event->update(['skipped' => array_values(array_unique([...($event->skipped ?? []), $data['date']]))]);

            return back()->with('toast', "Removed “{$event->title}” on ".CarbonImmutable::parse($data['date'])->format('D j M'))
                ->with('undo', $this->undo('unskip-event', $event->id, ['date' => $data['date']]));
        }
        $event->delete();

        return back()->with('toast', "Removed “{$event->title}”")->with('undo', $this->undo('restore-event', $event->id));
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $event = Event::withTrashed()->findOrFail($id);
        $this->own($event, $request->user());
        $event->restore();

        return back()->with('toast', "“{$event->title}” is back");
    }

    /** Undo of removing one week: that week happens again. */
    public function unskip(Request $request, Event $event): RedirectResponse
    {
        $this->own($event, $request->user());
        $date = $request->validate(['date' => ['required', 'date_format:Y-m-d']])['date'];
        $event->update(['skipped' => array_values(array_diff($event->skipped ?? [], [$date])) ?: null]);

        return back()->with('toast', "“{$event->title}” is back on ".CarbonImmutable::parse($date)->format('D j M'));
    }

    /** The dialog's fields (dates and times as the user typed them, in their time zone) → what is stored. */
    public static function fields(Request $request, User $user): array
    {
        $d = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'calendar' => ['required', Rule::in(Event::CALENDARS)],
            'location' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'all_day' => ['boolean'],
            'date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'repeat_weekly' => ['boolean'],
            'repeat_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date'],
        ], [], ['end_date' => 'last day', 'repeat_until' => 'last week', 'start_time' => 'start', 'end_time' => 'end']);
        $tz = $user->timezone;
        $allDay = (bool) ($d['all_day'] ?? false);

        if ($allDay) {
            $start = CarbonImmutable::parse($d['date'], $tz)->startOfDay();
            $end = CarbonImmutable::parse($d['end_date'] ?? $d['date'], $tz)->startOfDay()->addDay();
        } else {
            if (empty($d['start_time']) || empty($d['end_time'])) {
                throw ValidationException::withMessages(['start_time' => 'Give a start and an end time, or make it all day.']);
            }
            $start = CarbonImmutable::parse("{$d['date']} {$d['start_time']}", $tz);
            $end = CarbonImmutable::parse("{$d['date']} {$d['end_time']}", $tz);
            if ($end->equalTo($start)) {
                throw ValidationException::withMessages(['end_time' => 'The end has to be after the start.']);
            }
            if ($end < $start) {
                $end = $end->addDay(); // ends after midnight (a night shift)
            }
        }
        $weekly = (bool) ($d['repeat_weekly'] ?? false);

        return [
            'title' => $d['title'],
            'calendar' => $d['calendar'],
            'location' => $d['location'] ?? null,
            'notes' => $d['notes'] ?? null,
            'all_day' => $allDay,
            'starts_at' => $start->utc(),
            'ends_at' => $end->utc(),
            'repeat_weekly' => $weekly,
            'repeat_until' => $weekly ? ($d['repeat_until'] ?? null) : null,
        ] + ($request->has('locked') ? ['locked' => $request->boolean('locked')] : []);
    }

    /**
     * Dragged on the Planner: the time it happens on `occurrence` (a local date) moves to
     * `date` + `start`, `minutes` long. A weekly event moves every week (`scope` all) or
     * only that week (`one`: that week is skipped and a one-off copy takes its place).
     * A locked event moves only with "anyway".
     */
    public function move(Request $request, Event $event): RedirectResponse
    {
        $user = $request->user();
        $this->own($event, $user);
        if ($event->locked && ! $request->boolean('anyway')) {
            throw ValidationException::withMessages(['anyway' => "“{$event->title}” is locked."]);
        }
        if ($event->all_day) {
            throw ValidationException::withMessages(['date' => 'An all-day event is changed in its dialog.']);
        }
        $d = $request->validate([
            'occurrence' => ['required', 'date_format:Y-m-d'],
            'date' => ['required', 'date_format:Y-m-d'],
            'start' => ['required', 'date_format:H:i'],
            'minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'scope' => ['nullable', Rule::in(['one', 'all'])],
        ]);
        $tz = $user->timezone;
        $was = $event->starts_at->timezone($tz);
        $wasMinutes = intdiv($event->ends_at->getTimestamp() - $event->starts_at->getTimestamp(), 60);
        $newStart = CarbonImmutable::parse("{$d['date']} {$d['start']}", $tz);
        $label = $newStart->format('D j M');

        if ($event->repeat_weekly && ($d['scope'] ?? 'all') === 'one') {
            $copy = $user->events()->create([
                ...$event->only(['calendar', 'title', 'location', 'notes', 'locked']),
                'all_day' => false, 'repeat_weekly' => false,
                'starts_at' => $newStart->utc(), 'ends_at' => $newStart->addMinutes($d['minutes'])->utc(),
            ]);
            $event->update(['skipped' => array_values(array_unique([...($event->skipped ?? []), $d['occurrence']]))]);

            return back()->with('toast', "Moved “{$event->title}” on {$label}".$this->overlaps($user, $copy))
                ->with('undo', $this->undo('unmove-event', $event->id, ['copy' => $copy->id, 'date' => $d['occurrence']]));
        }

        // The whole event (every week of a weekly one) moves by the same number of days, to the new time.
        $days = (int) round(CarbonImmutable::parse($d['occurrence'], $tz)->diffInDays($newStart->startOfDay(), false));
        $shift = fn (?array $dates) => $dates ? array_map(fn ($x) => CarbonImmutable::parse($x)->addDays($days)->toDateString(), $dates) : $dates;
        $start = $was->addDays($days)->setTime($newStart->hour, $newStart->minute);
        $event->update([
            'starts_at' => $start->utc(),
            'ends_at' => $start->addMinutes($d['minutes'])->utc(),
            'repeat_until' => $event->repeat_until?->addDays($days),
            'skipped' => $shift($event->skipped),
            'cancelled' => $shift($event->cancelled),
        ]);
        $back = ['occurrence' => $d['date'], 'date' => $d['occurrence'], 'start' => $was->format('H:i'), 'minutes' => $wasMinutes, 'scope' => 'all', 'anyway' => true];
        $verb = $days === 0 && $start->format('H:i') === $was->format('H:i') ? 'Changed' : 'Moved';

        return back()->with('toast', "{$verb} “{$event->title}”".($event->repeat_weekly ? ' every week' : '').$this->overlaps($user, $event))
            ->with('undo', $this->undo('move-event', $event->id, $back));
    }

    /** Undo of moving one week: the copy goes and that week of the weekly event is back. */
    public function unmove(Request $request, Event $event): RedirectResponse
    {
        $user = $request->user();
        $this->own($event, $user);
        $d = $request->validate(['copy' => ['required', 'integer'], 'date' => ['required', 'date_format:Y-m-d']]);
        $user->events()->whereKey($d['copy'])->first()?->forceDelete();
        $event->update(['skipped' => array_values(array_diff($event->skipped ?? [], [$d['date']])) ?: null]);

        return back()->with('toast', "“{$event->title}” is back at its usual time");
    }

    /** Cancel the day it happens on `date` (it stays on the Planner, crossed out), or take that back. */
    public function cancel(Request $request, Event $event): RedirectResponse
    {
        $this->own($event, $request->user());
        $d = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'cancelled' => ['required', 'boolean']]);
        $dates = array_values(array_diff($event->cancelled ?? [], [$d['date']]));
        $event->update(['cancelled' => $d['cancelled'] ? [...$dates, $d['date']] : ($dates ?: null)]);
        $on = $event->repeat_weekly ? ' on '.CarbonImmutable::parse($d['date'])->format('D j M') : '';

        return back()->with('toast', ($d['cancelled'] ? 'Cancelled' : 'Not cancelled any more:')." “{$event->title}”{$on}")
            ->with('undo', $this->undo('cancel-event', $event->id, ['date' => $d['date'], 'cancelled' => ! $d['cancelled']]));
    }
}
