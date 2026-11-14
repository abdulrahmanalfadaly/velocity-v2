<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\PersonDate;
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
        $date->update($this->fields($request) + ['reminded_for' => null]);

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
        return ['kind' => $d->kind, 'label' => $d->label, 'month' => $d->month, 'day' => $d->day, 'year' => $d->year, 'yearly' => $d->yearly, 'remind_days' => $d->remind_days];
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
            'remind_days' => ['nullable', 'integer', Rule::in([0, 1, 3, 7, 14])],
        ], ['label.required_if' => 'Say what the date is.']);

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
            'remind_days' => isset($d['remind_days']) ? (int) $d['remind_days'] : null,
        ];
    }
}
