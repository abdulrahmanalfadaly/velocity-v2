<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\User;
use App\Services\PeopleInfo;
use App\Services\RankingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** People: the list (all, needs attention, dates coming up, a group, archived) and the person's panel. */
class PersonController extends Controller
{
    public function __construct(private readonly RankingService $ranking) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $today = $this->today($user);
        $q = $request->validate(['view' => ['nullable', 'string', 'max:12'], 'person' => ['nullable', 'integer']]);
        $view = $q['view'] ?? 'all';
        $group = preg_match('/^g(\d+)$/', $view, $m) ? $user->personGroups()->find((int) $m[1]) : null;
        if (str_starts_with($view, 'g') && ! $group) {
            $view = 'all';
        }

        $people = $user->people()
            ->when($view === 'archived', fn ($b) => $b->whereNotNull('archived_at'), fn ($b) => $b->whereNull('archived_at'))
            ->when($group, fn ($b) => $b->whereHas('groups', fn ($g) => $g->whereKey($group->id)))
            ->with(['dates', 'groups', 'catchUps'])
            ->orderBy('name')->get();
        $rows = $people->map(fn (Person $p) => PeopleInfo::row($p, $today));
        if ($view === 'attention') {
            $rows = $rows->filter(fn ($r) => $r['attention'])
                ->sortBy(fn ($r) => min($r['catchUp'] && $r['catchUp']['late'] >= 0 ? -$r['catchUp']['late'] : 999, $r['date']['days'] ?? 999))->values();
        }
        $upcoming = PeopleInfo::upcoming($user, $today, 60);

        $open = isset($q['person']) ? $user->people()->with(['contacts', 'dates', 'groups', 'catchUps', 'tasks', 'notes.event', 'projects'])->find($q['person']) : null;
        // The side menu's counts are over everyone not archived, whatever the list shows.
        $activeRows = in_array($view, ['all', 'attention', 'dates'], true)
            ? $people->map(fn (Person $p) => PeopleInfo::row($p, $today))
            : $user->people()->whereNull('archived_at')->with(['dates', 'groups', 'catchUps'])->get()->map(fn (Person $p) => PeopleInfo::row($p, $today));

        return Inertia::render('people/index', [
            'view' => $view,
            'today' => $today->toDateString(),
            'people' => $view === 'dates' ? [] : $rows->values(),
            'upcoming' => $view === 'dates' ? $upcoming : [],
            'group' => $group ? ['id' => $group->id, 'name' => $group->name] : null,
            'person' => $open ? PeopleInfo::detail($open, $today, $this->ranking->rank($user)) : null,
            'menu' => [
                'all' => $activeRows->count(),
                'attention' => $activeRows->filter(fn ($r) => $r['attention'])->count(),
                'dates' => $upcoming->count(),
                'archived' => $user->people()->whereNotNull('archived_at')->count(),
                'groups' => $user->personGroups()->withCount(['people' => fn ($p) => $p->whereNull('archived_at')])->get()
                    ->map(fn ($g) => ['id' => $g->id, 'name' => $g->name, 'count' => $g->people_count])->values(),
            ],
            'groups' => $user->personGroups()->get(['id', 'name']),
            'taskOptions' => $open ? $user->tasks()->open()->orderBy('title')->limit(400)->get(['id', 'title']) : [],
            'noteOptions' => $open ? $user->notes()->with('event')->latest('updated_at')->limit(200)->get()->map(fn ($n) => ['id' => $n->id, 'title' => $n->displayTitle()]) : [],
            'projects' => $user->projects()->whereNull('archived_at')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        [$fields, $contacts, $groups] = $this->fields($request, $user);
        $person = $user->people()->create($fields);
        $this->sync($person, $contacts, $groups);

        return redirect()->route('people.index', array_filter(['view' => $request->input('view'), 'person' => $person->id]))
            ->with('toast', "Added “{$person->name}”")->with('undo', $this->undo('delete-person', $person->id));
    }

    public function update(Request $request, Person $person): RedirectResponse
    {
        $user = $request->user();
        $this->own($person, $user);
        $old = PeopleInfo::form($person->load(['contacts', 'groups']));
        [$fields, $contacts, $groups] = $this->fields($request, $user);
        $person->update($fields);
        $this->sync($person, $contacts, $groups);

        return back()->with('toast', "Saved “{$person->name}”")->with('undo', $this->undo('patch-person', $person->id, $old));
    }

    /** The "About" box: things to remember about them. */
    public function about(Request $request, Person $person): RedirectResponse
    {
        $this->own($person, $request->user());
        $old = $person->about;
        $person->update(['about' => $request->validate(['about' => ['nullable', 'string', 'max:5000']])['about'] ?? null]);

        return back()->with('toast', 'Saved')->with('undo', $this->undo('put-person-about', $person->id, ['about' => $old]));
    }

    public function archive(Request $request, Person $person): RedirectResponse
    {
        $this->own($person, $request->user());
        $person->update(['archived_at' => now()]);

        return back()->with('toast', "Archived “{$person->name}”")->with('undo', $this->undo('unarchive-person', $person->id));
    }

    public function unarchive(Request $request, Person $person): RedirectResponse
    {
        $this->own($person, $request->user());
        $person->update(['archived_at' => null]);

        return back()->with('toast', "“{$person->name}” is back")->with('undo', $this->undo('archive-person', $person->id));
    }

    /** Deleted people keep their dates, catch-ups and links, so Undo brings everything back. */
    public function destroy(Request $request, Person $person): RedirectResponse
    {
        $this->own($person, $request->user());
        $person->delete();

        return back()->with('toast', "Deleted “{$person->name}”")->with('undo', $this->undo('restore-person', $person->id));
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $person = Person::onlyTrashed()->findOrFail($id);
        $this->own($person, $request->user());
        $person->restore();

        return back()->with('toast', "“{$person->name}” is back");
    }

    /** @return array{0: array, 1: list<array>, 2: list<int>} */
    public static function fields(Request $request, User $user): array
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'relationship' => ['nullable', 'string', 'max:80'],
            'organisation' => ['nullable', 'string', 'max:120'],
            'catch_up_days' => ['nullable', 'integer', 'between:1,365'],
            'contacts' => ['present', 'array', 'max:20'],
            'contacts.*.kind' => ['required', Rule::in(Person::CONTACT_KINDS)],
            'contacts.*.label' => ['nullable', 'string', 'max:40'],
            'contacts.*.value' => ['required', 'string', 'max:255'],
            'group_ids' => ['present', 'array', 'max:30'],
            'group_ids.*' => ['integer', Rule::exists('person_groups', 'id')->where('user_id', $user->id)->whereNull('deleted_at')],
            'new_groups' => ['sometimes', 'array', 'max:5'],
            'new_groups.*' => ['string', 'max:60'],
        ], [], ['catch_up_days' => 'catch up every', 'contacts.*.value' => 'contact']);

        $contacts = [];
        $errors = [];
        foreach ($d['contacts'] as $i => $c) {
            $value = trim($c['value']);
            if ($c['kind'] === 'email' && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors["contacts.{$i}.value"] = 'That isn’t an email address.';
            } elseif ($c['kind'] === 'phone' && ! preg_match('/^[0-9+()\-.\s\/]{3,40}$/', $value)) {
                $errors["contacts.{$i}.value"] = 'A phone number has digits, spaces and + ( ) - only.';
            } elseif ($c['kind'] === 'link') {
                $value = preg_match('~^https?://~i', $value) ? $value : "https://{$value}";
                if (! filter_var($value, FILTER_VALIDATE_URL) || preg_match('~^https?://[^/]*@~i', $value)) {
                    $errors["contacts.{$i}.value"] = 'That isn’t a web address.';
                }
            }
            $contacts[] = ['kind' => $c['kind'], 'label' => $c['label'] ?? null, 'value' => $value];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $groups = array_map('intval', $d['group_ids']);
        foreach ($d['new_groups'] ?? [] as $name) {
            if (trim($name) !== '') {
                $groups[] = $user->personGroups()->firstOrCreate(['name' => trim($name)])->id;
            }
        }

        return [[
            'name' => $d['name'],
            'relationship' => $d['relationship'] ?? null,
            'organisation' => $d['organisation'] ?? null,
            'catch_up_days' => $d['catch_up_days'] ?? null,
        ], $contacts, array_values(array_unique($groups))];
    }

    public static function sync(Person $person, array $contacts, array $groups): void
    {
        $person->contacts()->delete();
        foreach ($contacts as $i => $c) {
            $person->contacts()->create($c + ['position' => $i]);
        }
        $person->groups()->sync($groups);
    }

    private function today(User $user): CarbonImmutable
    {
        return $this->ranking->now()->timezone($user->timezone)->startOfDay();
    }
}
