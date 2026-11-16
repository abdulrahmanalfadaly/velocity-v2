<?php

namespace App\Http\Controllers;

use App\Models\CatchUp;
use App\Models\Person;
use App\Services\RankingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Keeping in touch: "Talked today" (or another day), and removing a wrong one. */
class CatchUpController extends Controller
{
    public function __construct(private readonly RankingService $ranking) {}

    public function store(Request $request, Person $person): RedirectResponse
    {
        $user = $request->user();
        $this->own($person, $user);
        $today = $this->ranking->now()->timezone($user->timezone)->toDateString();
        $d = $request->validate(self::rules($today), ['on.before_or_equal' => 'That day hasn’t happened yet.']);
        $c = $person->catchUps()->create(['on' => $d['on'] ?? $today, 'at' => $d['at'] ?? null, 'kind' => $d['kind'], 'note' => $d['note'] ?? null, 'counts' => $d['counts'] ?? true]);

        return back()->with('toast', $c->counts ? "In touch with {$person->name}" : 'Noted (doesn’t count)')->with('undo', $this->undo('delete-catch-up', $c->id));
    }

    /** Change one after saving it: the day, the time, how, the note, whether it counts. */
    public function update(Request $request, CatchUp $catchUp): RedirectResponse
    {
        $user = $request->user();
        $this->own($catchUp->person()->firstOrFail(), $user);
        $today = $this->ranking->now()->timezone($user->timezone)->toDateString();
        $d = $request->validate(self::rules($today), ['on.before_or_equal' => 'That day hasn’t happened yet.']);
        $old = ['on' => $catchUp->on->toDateString(), 'at' => $catchUp->at, 'kind' => $catchUp->kind, 'note' => $catchUp->note, 'counts' => $catchUp->counts];
        $catchUp->update(['on' => $d['on'] ?? $catchUp->on->toDateString(), 'at' => $d['at'] ?? null, 'kind' => $d['kind'], 'note' => $d['note'] ?? null, 'counts' => $d['counts'] ?? true]);

        return back()->with('toast', 'Saved')->with('undo', $this->undo('patch-catch-up', $catchUp->id, $old));
    }

    private static function rules(string $today): array
    {
        return [
            'on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'at' => ['nullable', 'date_format:H:i'],
            'kind' => ['required', Rule::in(Person::CATCH_UP_KINDS)],
            'note' => ['nullable', 'string', 'max:500'],
            // "Doesn't count": noted, but it doesn't reset the catch-up clock.
            'counts' => ['sometimes', 'boolean'],
        ];
    }

    public function destroy(Request $request, CatchUp $catchUp): RedirectResponse
    {
        $this->own($catchUp->person()->firstOrFail(), $request->user());
        $catchUp->delete();

        return back()->with('toast', 'Removed')->with('undo', $this->undo('restore-catch-up', $catchUp->id));
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $c = CatchUp::onlyTrashed()->findOrFail($id);
        $this->own($c->person()->firstOrFail(), $request->user());
        $c->restore();

        return back()->with('toast', 'Back in the history');
    }
}
