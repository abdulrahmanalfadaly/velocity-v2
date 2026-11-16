<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\PersonMemory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** A person's memories: add, change, remove (with Undo). */
class PersonMemoryController extends Controller
{
    public function store(Request $request, Person $person): RedirectResponse
    {
        $this->own($person, $request->user());
        $memory = $person->memories()->create($this->fields($request));

        return back()->with('toast', 'Memory added')->with('undo', $this->undo('delete-memory', $memory->id));
    }

    public function update(Request $request, PersonMemory $memory): RedirectResponse
    {
        $this->own($memory->person()->firstOrFail(), $request->user());
        $old = ['text' => $memory->text, 'on' => $memory->on?->toDateString()];
        $memory->update($this->fields($request));

        return back()->with('toast', 'Saved')->with('undo', $this->undo('patch-memory', $memory->id, $old));
    }

    public function destroy(Request $request, PersonMemory $memory): RedirectResponse
    {
        $this->own($memory->person()->firstOrFail(), $request->user());
        $memory->delete();

        return back()->with('toast', 'Memory removed')->with('undo', $this->undo('restore-memory', $memory->id));
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $memory = PersonMemory::onlyTrashed()->findOrFail($id);
        $this->own($memory->person()->firstOrFail(), $request->user());
        $memory->restore();

        return back()->with('toast', 'Memory back');
    }

    private function fields(Request $request): array
    {
        $d = $request->validate(['text' => ['required', 'string', 'max:500'], 'on' => ['nullable', 'date_format:Y-m-d']], [], ['text' => 'memory']);

        return ['text' => trim($d['text']), 'on' => $d['on'] ?? null];
    }
}
