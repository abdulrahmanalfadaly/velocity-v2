<?php

namespace App\Http\Controllers;

use App\Models\Area;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Areas in the Tasks menu: add, rename, change the icon, reorder, remove (its projects move to Other), each with Undo. */
class AreaController extends Controller
{
    public const ICONS = ['folder', 'cap', 'briefcase', 'heart', 'home', 'book', 'target', 'flame'];

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'icon' => ['nullable', Rule::in(self::ICONS)]]);
        $area = $user->areas()->create($data + ['position' => (int) $user->areas()->max('position') + 1]);

        return back()->with('toast', "Added area “{$area->name}”")->with('undo', $this->undo('delete-area', $area->id));
    }

    public function update(Request $request, Area $area): RedirectResponse
    {
        $this->own($area, $request->user());
        $data = $request->validate(['name' => ['sometimes', 'required', 'string', 'max:80'], 'icon' => ['sometimes', 'nullable', Rule::in(self::ICONS)]]);
        $before = $area->only(array_keys($data));
        $area->update($data);

        return back()->with('toast', 'Area saved')->with('undo', $this->undo('patch-area', $area->id, $before));
    }

    /** A new order for the areas (dragged in the Tasks menu, or Move up / down). Undo puts the old order back. */
    public function order(Request $request): RedirectResponse
    {
        $user = $request->user();
        $ids = $request->validate(['ids' => ['required', 'array', 'max:200'], 'ids.*' => ['integer']])['ids'];
        $before = $user->areas()->orderBy('position')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        // Only your own areas count; any left out keep their order after the ones given.
        $given = array_values(array_intersect(array_unique(array_map('intval', $ids)), $before));
        $after = [...$given, ...array_values(array_diff($before, $given))];
        if ($after === $before) {
            return back();
        }
        foreach ($after as $i => $id) {
            Area::whereKey($id)->update(['position' => $i + 1]);
        }

        return back()->with('toast', 'Areas moved')->with('undo', $this->undo('order-areas', 0, ['ids' => $before]));
    }

    /** Remove an area: its projects stay, under Other. Undo puts them back in it. */
    public function destroy(Request $request, Area $area): RedirectResponse
    {
        $this->own($area, $request->user());
        $projects = $area->projects()->pluck('id')->all();
        $area->projects()->update(['area_id' => null]);
        $area->delete();

        return back()->with('toast', "Removed area “{$area->name}”")->with('undo', $this->undo('restore-area', $area->id, ['projects' => $projects]));
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $user = $request->user();
        $area = Area::onlyTrashed()->findOrFail($id);
        $this->own($area, $user);
        $area->restore();
        $ids = $request->validate(['projects' => ['nullable', 'array'], 'projects.*' => ['integer']])['projects'] ?? [];
        $user->projects()->whereIn('id', $ids)->whereNull('area_id')->update(['area_id' => $area->id]);

        return back()->with('toast', "“{$area->name}” is back");
    }
}
