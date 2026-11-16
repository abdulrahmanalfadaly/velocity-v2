<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\PersonDate;
use App\Support\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Birthdays, anniversaries and other dates, with an optional reminder before each. */
class PersonDateController extends Controller
{
    public function store(Request $request, Person $person): RedirectResponse
    {
        $this->own($person, $request->user());
        $date = $person->dates()->create($this->fields($request));

        return back()->with('toast', 'Added '.$date->title($person->name))->with('undo', $this->undo('delete-person-date', $date->id));
    }

    public function update(Request $request, PersonDate $date): RedirectResponse
    {
        $person = $this->owner($request, $date);
        $old = $this->form($date);
        // A changed date or reminder may need reminding again this year.
        $date->update($this->fields($request) + ['reminded_for' => null, 'reminded' => null, 'snoozed_until' => null]);

        return back()->with('toast', 'Saved '.$date->title($person->name))->with('undo', $this->undo('patch-person-date', $date->id, $old));
    }

    public function destroy(Request $request, PersonDate $date): RedirectResponse
    {
        $person = $this->owner($request, $date);
        $date->delete();

        return back()->with('toast', 'Removed '.$date->title($person->name))->with('undo', $this->undo('restore-person-date', $date->id));
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $date = PersonDate::onlyTrashed()->findOrFail($id);
        $person = $this->owner($request, $date);
        $date->restore();

        return back()->with('toast', $date->title($person->name).' is back');
    }

    private function owner(Request $request, PersonDate $date): Person
    {
        $person = $date->person()->firstOrFail();
        $this->own($person, $request->user());

        return $person;
    }

    private function form(PersonDate $d): array
    {
        return ['kind' => $d->kind, 'label' => $d->label, 'month' => $d->month, 'day' => $d->day, 'year' => $d->year, 'yearly' => $d->yearly, 'reminders' => $d->reminderList()];
    }

    private function fields(Request $request): array
    {
        $d = $request->validate([
            'kind' => ['required', Rule::in(PersonDate::KINDS)],
            'label' => ['nullable', 'string', 'max:80', 'required_if:kind,other'],
            'month' => ['required', 'integer', 'between:1,12'],
            'day' => ['required', 'integer', 'between:1,31'],
            'year' => ['nullable', 'integer', 'between:1900,2100'],
            'yearly' => ['sometimes', 'boolean'],
            'remind_days' => ['nullable', 'integer', Rule::in(PersonDate::BEFORE)],
            // Several reminders: how many days before, and at what time.
            'reminders' => ['sometimes', 'array', 'max:6'],
            'reminders.*.days' => ['required', 'integer', Rule::in(PersonDate::BEFORE)],
            'reminders.*.at' => ['required', 'date_format:H:i'],
        ], [
            'label.required_if' => 'Say what the date is.',
            'day.required' => 'Pick a day.', 'day.integer' => 'Pick a day.', 'day.between' => 'Pick a day.',
            'month.required' => 'Pick a month.', 'month.integer' => 'Pick a month.', 'month.between' => 'Pick a month.',
            'year.between' => 'A year between 1900 and 2100, or leave it empty.',
        ]);

        $yearly = $d['kind'] !== 'other' || ($d['yearly'] ?? true);
        // 29 February is fine without a year (it is kept on 28 February in other years), not in a year that hasn't one.
        if (! checkdate($d['month'], $d['day'], $d['year'] ?? 2000)) {
            throw ValidationException::withMessages(['day' => 'That day isn’t in that month.']);
        }
        if (! $yearly && empty($d['year'])) {
            throw ValidationException::withMessages(['year' => 'A date that happens once needs its year.']);
        }

        return [
            'kind' => $d['kind'],
            'label' => $d['label'] ?? null,
            'month' => (int) $d['month'],
            'day' => (int) $d['day'],
            'year' => isset($d['year']) ? (int) $d['year'] : null,
            'yearly' => $yearly,
        ] + self::reminders($request, $d);
    }

    /** The reminders sent ("reminders"), or the old single one ("remind_days" at 9:00); the earliest also kept in remind_days. */
    private static function reminders(Request $request, array $d): array
    {
        if (! $request->has('reminders') && ! $request->has('remind_days')) {
            return [];
        }
        $list = $request->has('reminders')
            ? collect($d['reminders'] ?? [])->map(fn ($r) => ['days' => (int) $r['days'], 'at' => $r['at']])
            : collect(isset($d['remind_days']) ? [['days' => (int) $d['remind_days'], 'at' => '09:00']] : []);
        $list = $list->unique(fn ($r) => $r['days'].$r['at'])->sortByDesc('days')->values();

        return ['reminders' => $list->all() ?: null, 'remind_days' => $list->max('days')];
    }

    /** "Snooze" on the reminder card: again in an hour, or tomorrow at nine. */
    public function snooze(Request $request, PersonDate $date): RedirectResponse
    {
        $person = $this->owner($request, $date);
        $for = $request->validate(['for' => ['required', Rule::in(['1h', 'tomorrow'])]])['for'];
        $now = app(Clock::class)->now();
        $until = $for === '1h' ? $now->addHour() : $now->timezone($request->user()->timezone)->addDay()->setTime(9, 0)->utc();
        $date->update(['snoozed_until' => $until]);

        return back()->with('toast', $date->title($person->name).($for === '1h' ? ': again in an hour' : ': again tomorrow at 9:00 am'));
    }
}
