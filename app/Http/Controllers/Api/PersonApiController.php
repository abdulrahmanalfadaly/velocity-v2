<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\PersonController;
use App\Models\Person;
use App\Models\Task;
use App\Services\PeopleInfo;
use App\Support\ApiPresent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
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
        $person->load(['contacts', 'dates', 'groups', 'catchUps', 'tasks', 'notes.event', 'projects', 'memories']);

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

    /** Change the basics: name, relationship ("who they are"), organisation ("where"), catch_up_days. */
    public function update(Request $request, Person $person): JsonResponse
    {
        $user = $request->user();
        $this->own($person, $user);
        $person->update($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'nickname' => ['sometimes', 'nullable', 'string', 'max:60'],
            'relationship' => ['sometimes', 'nullable', 'string', 'max:80'],
            'organisation' => ['sometimes', 'nullable', 'string', 'max:120'],
            'location' => ['sometimes', 'nullable', 'string', 'max:120'],
            'catch_up_days' => ['sometimes', 'nullable', 'integer', 'between:1,365'],
        ]));

        return $this->ok(PeopleInfo::row($person->fresh(['dates', 'groups', 'catchUps']), $this->today($user)));
    }

    /** Archive ({"undo": true} brings them back). */
    public function archive(Request $request, Person $person): JsonResponse
    {
        $this->own($person, $request->user());
        $person->update(['archived_at' => $request->boolean('undo') ? null : now()]);

        return $this->ok(['id' => $person->id, 'archived' => $person->archived_at !== null]);
    }

    /** Were in touch: {"kind": "call", "on"?: "2026-11-13", "note"?: "…"}. */
    public function catchUp(Request $request, Person $person): JsonResponse
    {
        $user = $request->user();
        $this->own($person, $user);
        $today = $this->today($user)->toDateString();
        $d = $request->validate([
            'on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'at' => ['nullable', 'date_format:H:i'],
            'kind' => ['required', Rule::in(Person::CATCH_UP_KINDS)],
            'note' => ['nullable', 'string', 'max:500'],
            'counts' => ['sometimes', 'boolean'],
        ], ['on.before_or_equal' => 'That day hasn’t happened yet.']);
        $person->catchUps()->create(['on' => $d['on'] ?? $today, 'at' => $d['at'] ?? null, 'kind' => $d['kind'], 'note' => $d['note'] ?? null, 'counts' => $d['counts'] ?? true]);

        return $this->ok(PeopleInfo::row($person->fresh(['dates', 'groups', 'catchUps']), $this->today($user)), 201);
    }

    /** A memory: {"text": "Loves matcha", "on"?: "2025-08-09"}. */
    public function memory(Request $request, Person $person): JsonResponse
    {
        $this->own($person, $request->user());
        $d = $request->validate(['text' => ['required', 'string', 'max:500'], 'on' => ['nullable', 'date_format:Y-m-d']]);
        $memory = $person->memories()->create(['text' => trim($d['text']), 'on' => $d['on'] ?? null]);

        return $this->ok($memory->present(), 201);
    }

    /** "Follow up": {"on": "2026-11-20", "title"?: "Ask Sara about the survey"}: a task waiting on them until then. */
    public function followUp(Request $request, Person $person): JsonResponse
    {
        $user = $request->user();
        $this->own($person, $user);
        $today = $this->today($user)->toDateString();
        $d = $request->validate(['title' => ['nullable', 'string', 'max:255'], 'on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today]]);
        $first = Str::before($person->name, ' ');
        $task = $user->tasks()->create([
            'title' => Str::limit(trim($d['title'] ?? '') ?: "Follow up with {$first}", 255, ''),
            'importance' => 3, 'urgency' => 3, 'est_hours' => 0.25,
            'status' => Task::WAITING, 'waiting_for' => $person->name, 'waiting_since' => $this->ranking->now(), 'follow_up_on' => $d['on'],
        ]);
        $task->events()->create(['user_id' => $user->id, 'type' => 'created', 'via' => 'api']);
        $person->tasks()->attach($task->id);

        return $this->ok(ApiPresent::task($task->fresh('project.goal')), 201);
    }
}
