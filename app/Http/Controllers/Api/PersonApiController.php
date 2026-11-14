<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\PersonController;
use App\Models\Person;
use App\Services\PeopleInfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/** People: who they are, their dates, keeping in touch. */
class PersonApiController extends ApiController
{
    /** view=all (default) | attention (a catch-up due or a date soon) | dates (dates in the next 60 days). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = $this->today($user);
        $view = $request->validate(['view' => ['nullable', Rule::in(['all', 'attention', 'dates'])]])['view'] ?? 'all';
        if ($view === 'dates') {
            return $this->ok(PeopleInfo::upcoming($user, $today, 60), meta: ['view' => $view]);
        }
        $rows = $user->people()->whereNull('archived_at')->with(['dates', 'groups', 'catchUps'])->orderBy('name')->get()
            ->map(fn (Person $p) => PeopleInfo::row($p, $today));
        if ($view === 'attention') {
            $rows = $rows->filter(fn ($r) => $r['attention']);
        }

        return $this->ok($rows->values(), meta: ['view' => $view]);
    }

    public function show(Request $request, Person $person): JsonResponse
    {
        $user = $request->user();
        $this->own($person, $user);
        $person->load(['contacts', 'dates', 'groups', 'catchUps', 'tasks', 'notes.event', 'projects']);

        return $this->ok(Arr::except(PeopleInfo::detail($person, $this->today($user), $this->ranking->rank($user)), ['form']));
    }

    /** {"name": "Daniel Tan", "relationship": "Classmate", "catch_up_days": 30, "contacts": [{"kind": "email", "value": "…"}]} */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->mergeIfMissing(['contacts' => [], 'group_ids' => []]);
        [$fields, $contacts, $groups] = PersonController::fields($request, $user);
        $person = $user->people()->create($fields);
        PersonController::sync($person, $contacts, $groups);

        return $this->ok(PeopleInfo::row($person->load(['dates', 'groups', 'catchUps']), $this->today($user)), 201);
    }

    /** Were in touch: {"kind": "call", "on"?: "2026-11-13", "note"?: "…"}. */
    public function catchUp(Request $request, Person $person): JsonResponse
    {
        $user = $request->user();
        $this->own($person, $user);
        $today = $this->today($user)->toDateString();
        $d = $request->validate([
            'on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'kind' => ['required', Rule::in(Person::CATCH_UP_KINDS)],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['on.before_or_equal' => 'That day hasn’t happened yet.']);
        $person->catchUps()->create(['on' => $d['on'] ?? $today, 'kind' => $d['kind'], 'note' => $d['note'] ?? null]);

        return $this->ok(PeopleInfo::row($person->fresh(['dates', 'groups', 'catchUps']), $this->today($user)), 201);
    }
}
