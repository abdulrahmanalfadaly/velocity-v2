<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
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

        return back()->with('toast', "Added “{$event->title}”")->with('undo', $this->undo('delete-event', $event->id));
    }

    public function update(Request $request, Event $event): RedirectResponse
    {
        $user = $request->user();
        $this->own($event, $user);
        $before = $event->formValues($user->timezone);
        $event->update($this->fields($request, $user));

        return back()->with('toast', 'Event saved')->with('undo', $this->undo('patch-event', $event->id, $before));
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
    private function fields(Request $request, User $user): array
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
        ];
    }
}
