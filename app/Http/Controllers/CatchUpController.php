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
        $d = $request->validate([
            'on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'kind' => ['required', Rule::in(Person::CATCH_UP_KINDS)],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['on.before_or_equal' => 'That day hasn’t happened yet.']);
        $c = $person->catchUps()->create(['on' => $d['on'] ?? $today, 'kind' => $d['kind'], 'note' => $d['note'] ?? null]);

        return back()->with('toast', "In touch with {$person->name}")->with('undo', $this->undo('delete-catch-up', $c->id));
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
