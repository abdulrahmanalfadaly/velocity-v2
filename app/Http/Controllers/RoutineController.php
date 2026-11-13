<?php

namespace App\Http\Controllers;

use App\Models\Routine;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Routines: a name and habits in order. A routine has no ticks of its own; its habits do. */
class RoutineController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        [$name, $ids] = $this->fields($request, $user);
        $routine = $user->routines()->create(['name' => $name]);
        $this->order($routine, $ids);

        return redirect()->route('habits.index', ['view' => "r{$routine->id}"])
            ->with('toast', "Added “{$routine->name}”")->with('undo', $this->undo('delete-routine', $routine->id));
    }

    public function update(Request $request, Routine $routine): RedirectResponse
    {
        $user = $request->user();
        $this->own($routine, $user);
        $old = ['name' => $routine->name, 'habit_ids' => $routine->habits()->pluck('habits.id')->all()];
        [$name, $ids] = $this->fields($request, $user);
        $routine->update(['name' => $name]);
        $this->order($routine, $ids);

        return back()->with('toast', "Saved “{$routine->name}”")->with('undo', $this->undo('patch-routine', $routine->id, $old));
    }

    public function destroy(Request $request, Routine $routine): RedirectResponse
    {
        $this->own($routine, $request->user());
        $routine->delete();

        return redirect()->route('habits.index')->with('toast', "Deleted “{$routine->name}”")->with('undo', $this->undo('restore-routine', $routine->id));
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $routine = Routine::onlyTrashed()->findOrFail($id);
        $this->own($routine, $request->user());
        $routine->restore();

        return redirect()->route('habits.index', ['view' => "r{$routine->id}"])->with('toast', "“{$routine->name}” is back");
    }

    /** @return array{0: string, 1: list<int>} */
    private function fields(Request $request, User $user): array
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'habit_ids' => ['present', 'array', 'max:40'],
            'habit_ids.*' => ['integer', 'distinct', Rule::exists('habits', 'id')->where('user_id', $user->id)->whereNull('deleted_at')],
        ]);

        return [$d['name'], array_map('intval', $d['habit_ids'])];
    }

    private function order(Routine $routine, array $ids): void
    {
        $routine->habits()->sync(collect($ids)->mapWithKeys(fn ($id, $i) => [$id => ['position' => $i]])->all());
    }
}
