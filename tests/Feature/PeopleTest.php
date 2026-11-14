<?php

namespace Tests\Feature;

use App\Models\Note;
use App\Models\Person;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * People: dates that come round (across New Year, 29 February), ages, reminders
 * once a year, keeping in touch, links to tasks, notes and projects, contact
 * checks, and nobody else seeing your people.
 */
class PeopleTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Asia/Kuala_Lumpur';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['velocity.timezone' => self::TZ, 'velocity.fixed_now' => '2026-11-12 07:00', 'velocity.study_log' => false]);
        $this->user = User::factory()->create(['timezone' => self::TZ]);
        $this->actingAs($this->user);
    }

    private function person(array $data = []): Person
    {
        $this->post(route('people.store'), $data + ['name' => 'Sara Ahmad', 'contacts' => [], 'group_ids' => []])->assertSessionHasNoErrors();

        return Person::latest('id')->firstOrFail();
    }

    private function date(Person $p, array $data): void
    {
        $this->post(route('person-dates.store', $p), $data + ['kind' => 'birthday'])->assertSessionHasNoErrors();
    }

    private function upcoming(string $view = 'dates'): array
    {
        $out = [];
        $this->get(route('people.index', ['view' => $view]))->assertInertia(function ($page) use (&$out) {
            $out = $page->toArray()['props'];
        });

        return $out;
    }

    public function test_a_birthday_comes_round_across_new_year_with_the_age_it_turns(): void
    {
        config(['velocity.fixed_now' => '2026-12-30 10:00']);
        $sara = $this->person();
        $this->date($sara, ['day' => 2, 'month' => 1, 'year' => 2004]);

        $d = $this->upcoming()['upcoming'][0];
        $this->assertSame('2027-01-02', $d['next']);
        $this->assertSame(3, $d['days']);
        $this->assertSame(23, $d['turns']);
        $this->assertSame('Sara Ahmad’s birthday', $d['title']);
    }

    public function test_29_february_is_kept_on_28_february_in_other_years_and_bad_days_are_refused(): void
    {
        $leo = $this->person(['name' => 'Leo']);
        $this->date($leo, ['day' => 29, 'month' => 2, 'year' => 2004]);
        $d = $leo->dates()->firstOrFail();

        $this->assertSame('2027-02-28', $d->next(CarbonImmutable::parse('2027-02-20', self::TZ))->toDateString());
        $this->assertSame('2028-02-29', $d->next(CarbonImmutable::parse('2028-02-20', self::TZ))->toDateString());
        $this->assertSame(24, $d->turns($d->next(CarbonImmutable::parse('2028-02-20', self::TZ))));

        $this->post(route('person-dates.store', $leo), ['kind' => 'birthday', 'day' => 29, 'month' => 2, 'year' => 2023])->assertSessionHasErrors('day');
        $this->post(route('person-dates.store', $leo), ['kind' => 'birthday', 'day' => 31, 'month' => 4])->assertSessionHasErrors('day');
        $this->post(route('person-dates.store', $leo), ['kind' => 'other', 'label' => 'Viva', 'day' => 3, 'month' => 6, 'yearly' => false])->assertSessionHasErrors('year');
    }

    public function test_a_date_reminder_pops_up_once_each_time_the_date_comes_round(): void
    {
        $sara = $this->person();
        $this->date($sara, ['day' => 14, 'month' => 11, 'year' => 2004, 'remind_days' => 1]); // the day before, from 9:00
        $due = fn () => $this->getJson(route('reminders.due'))->json('dates');

        config(['velocity.fixed_now' => '2026-11-13 08:30']);
        $this->assertSame([], $due());
        config(['velocity.fixed_now' => '2026-11-13 09:30']);
        $this->assertSame(['Sara Ahmad’s birthday'], array_column($due(), 'title'));
        $this->assertSame([], $due());               // shown once
        config(['velocity.fixed_now' => '2027-11-13 09:30']);
        $this->assertCount(1, $due());               // and again next year
    }

    public function test_keeping_in_touch_counts_from_the_last_catch_up(): void
    {
        $lee = $this->person(['name' => 'Dr Lee', 'catch_up_days' => 14]);
        $lee->forceFill(['created_at' => CarbonImmutable::parse('2026-10-01 09:00', self::TZ)->utc()])->save();

        // Never talked: due 14 days after being added (15 Oct), so 28 days late on 12 Nov.
        $row = collect($this->upcoming('attention')['people'])->firstWhere('id', $lee->id);
        $this->assertSame(['next' => '2026-10-15', 'late' => 28], $row['catchUp']);

        $this->post(route('catch-ups.store', $lee), ['kind' => 'call', 'note' => 'Chapter 4'])->assertSessionHas('toast', 'In touch with Dr Lee');
        $undo = session('undo');
        $this->assertSame([], $this->upcoming('attention')['people']);
        $this->get(route('people.index', ['person' => $lee->id]))->assertInertia(fn ($page) => $page
            ->where('person.keepInTouch.next', '2026-11-26')
            ->where('person.keepInTouch.last', '2026-11-12')
            ->where('person.keepInTouch.history.0.note', 'Chapter 4'));

        $this->delete(route('catch-ups.destroy', $undo['id']))->assertSessionHasNoErrors();
        $this->post(route('catch-ups.store', $lee), ['kind' => 'met', 'on' => '2026-11-20'])->assertSessionHasErrors('on');
    }

    public function test_people_link_to_tasks_notes_and_projects(): void
    {
        $lee = $this->person(['name' => 'Dr Lee']);
        $task = $this->user->tasks()->create(['title' => 'Feedback on chapter 4', 'importance' => 4, 'urgency' => 3, 'est_hours' => 1, 'status' => 'waiting']);
        $project = $this->user->projects()->create(['name' => 'FYP']);

        $this->post(route('people.link', $lee), ['type' => 'task', 'id' => $task->id])->assertSessionHasNoErrors();
        $this->post(route('people.link', $lee), ['type' => 'project', 'id' => $project->id])->assertSessionHasNoErrors();
        $this->post(route('people.new-task', $lee), ['title' => 'Book a meeting'])->assertSessionHas('undo.action', 'delete-task');
        $this->post(route('notes.store'), ['person_id' => $lee->id])->assertRedirect();
        $note = Note::latest('id')->firstOrFail();
        $this->assertSame('Dr Lee', $note->title);

        $this->get(route('people.index', ['person' => $lee->id]))->assertInertia(fn ($page) => $page
            ->where('person.tasks.0.title', 'Book a meeting')
            ->where('person.tasks.1.status', 'waiting')
            ->where('person.notes.0.id', $note->id)
            ->where('person.projects.0.name', 'FYP'));
        $this->get(route('tasks.index', ['task' => $task->id]))->assertInertia(fn ($page) => $page->where('selected.people.0.name', 'Dr Lee'));
        $this->get(route('projects.show', $project))->assertInertia(fn ($page) => $page->where('people.0.name', 'Dr Lee'));

        // Unlink, then Undo links it again.
        $this->post(route('people.unlink', $lee), ['type' => 'task', 'id' => $task->id])->assertSessionHas('toast', 'Unlinked “Feedback on chapter 4”');
        $this->post(route('people.link', $lee), session('undo')['data'])->assertSessionHasNoErrors();
        $this->assertTrue($lee->tasks()->whereKey($task->id)->exists());
    }

    public function test_contacts_are_checked_and_links_get_https(): void
    {
        $this->post(route('people.store'), ['name' => 'Adam', 'group_ids' => [], 'contacts' => [['kind' => 'email', 'value' => 'not an email']]])
            ->assertSessionHasErrors('contacts.0.value');

        $adam = $this->person(['name' => 'Adam', 'new_groups' => ['FYP team'], 'contacts' => [
            ['kind' => 'email', 'value' => 'adam@example.com'],
            ['kind' => 'link', 'value' => 'linkedin.com/in/adam', 'label' => 'LinkedIn'],
        ]]);
        $this->assertSame(['adam@example.com', 'https://linkedin.com/in/adam'], $adam->contacts()->pluck('value')->all());
        $this->assertSame(['FYP team'], $adam->groups()->pluck('name')->all());
        $this->getJson(route('search', ['q' => 'adam@example']))->assertJsonPath('groups.0.items.0.title', 'Adam');
    }

    public function test_no_one_else_can_see_or_change_your_people(): void
    {
        $sara = $this->person();
        $task = $this->user->tasks()->create(['title' => 'Mine', 'importance' => 3, 'urgency' => 3, 'est_hours' => 1]);
        $other = User::factory()->create();
        $theirs = $other->people()->create(['name' => 'Theirs']);

        // My task can't be linked to their person, nor theirs to mine.
        $this->post(route('people.link', $theirs), ['type' => 'task', 'id' => $task->id])->assertNotFound();
        $this->actingAs($other);
        $this->post(route('people.link', $theirs), ['type' => 'task', 'id' => $task->id])->assertNotFound();
        $this->patch(route('people.update', $sara), ['name' => 'Mine now', 'contacts' => [], 'group_ids' => []])->assertNotFound();
        $this->post(route('catch-ups.store', $sara), ['kind' => 'call'])->assertNotFound();
        $this->get(route('people.index', ['person' => $sara->id]))->assertInertia(fn ($page) => $page->where('person', null)->has('people', 1));
        $this->getJson(route('search', ['q' => 'Sara']))->assertJsonPath('groups', []);
    }
}
