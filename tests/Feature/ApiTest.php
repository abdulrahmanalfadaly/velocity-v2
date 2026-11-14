<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The API for Mark: access keys (areas, revoked, expired), someone else's
 * records, quick add landing where the screens put it, "via Mark", the parts of
 * /today a key may read, and the journal growing line by line.
 */
class ApiTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Asia/Kuala_Lumpur';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Thursday 12 Nov 2026, 09:00 in Kuala Lumpur.
        config(['velocity.timezone' => self::TZ, 'velocity.fixed_now' => '2026-11-12 01:00', 'velocity.study_log' => false]);
        $this->user = User::factory()->create(['timezone' => self::TZ, 'fyp_mode' => false]);
    }

    private function key(array $abilities = ['*'], ?User $user = null): string
    {
        return ($user ?? $this->user)->createToken('Mark', $abilities)->plainTextToken;
    }

    /** One request with a key (each as a fresh request, as Mark would make it). */
    private function api(string $method, string $uri, ?string $key, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, "/api/v1/{$uri}", $data, $key ? ['Authorization' => "Bearer {$key}"] : []);
    }

    public function test_a_key_only_opens_its_own_areas_and_stops_when_revoked_or_expired(): void
    {
        $this->api('GET', 'health', null)->assertOk()->assertJsonPath('data.status', 'ok');
        $this->api('GET', 'me', null)->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->api('GET', 'me', 'vel_made-up')->assertStatus(401);

        $key = $this->key(['tasks:read']);
        $this->api('GET', 'me', $key)->assertOk()->assertJsonPath('data.accessText', 'Tasks & focus: read');
        $this->api('GET', 'tasks', $key)->assertOk();
        $this->api('POST', 'tasks', $key, ['text' => 'Sneaky'])->assertStatus(403)
            ->assertJsonPath('error.message', 'This key needs Tasks & focus: write.');
        $this->api('GET', 'notes', $key)->assertStatus(403);
        $this->assertSame(0, Task::count());

        $this->user->tokens()->delete(); // revoked in Settings → Integrations
        $this->api('GET', 'tasks', $key)->assertStatus(401);

        $old = $this->user->createToken('Old', ['*'], now()->subMinute())->plainTextToken;
        $this->api('GET', 'me', $old)->assertStatus(401);
    }

    public function test_someone_elses_records_are_not_found_and_bad_input_says_which_field(): void
    {
        $other = User::factory()->create(['fyp_mode' => false]);
        $theirs = $other->tasks()->create(['title' => 'Private', 'importance' => 3, 'urgency' => 3, 'est_hours' => 1]);
        $key = $this->key();

        $this->api('GET', "tasks/{$theirs->id}", $key)->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        $this->api('PATCH', "tasks/{$theirs->id}", $key, ['title' => 'Mine now'])->assertNotFound();
        $this->api('POST', "tasks/{$theirs->id}/complete", $key)->assertNotFound();
        $this->assertSame('Private', $theirs->fresh()->title);

        $this->api('POST', 'tasks', $key, ['title' => 'Bad', 'importance' => 9])->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID')->assertJsonStructure(['error' => ['fields' => ['importance']]]);
    }

    public function test_quick_add_lands_where_the_screens_put_it_and_changes_say_via_mark(): void
    {
        $fyp = $this->user->projects()->create(['name' => 'Final Year Project']);
        $this->user->tasks()->create(['title' => 'Small thing', 'importance' => 2, 'urgency' => 2, 'est_hours' => 1]);
        $key = $this->key(['tasks:read', 'tasks:write']);

        $preview = $this->api('POST', 'tasks/preview', $key, ['text' => 'Ethics essay fri 3h', 'importance' => 5])->assertOk()->json('data');
        $made = $this->api('POST', 'tasks', $key, ['text' => 'Ethics essay fri 3h #fyp', 'importance' => 5])->assertCreated()->json('data');

        $this->assertSame('Ethics essay', $made['title']);
        $this->assertSame($fyp->id, $made['project']['id']);
        $this->assertSame(3, (int) $made['estHours']);
        $this->assertSame('2026-11-13T15:59:00Z', $made['dueAt']); // Friday 23:59 in Kuala Lumpur
        $this->assertSame(1, $made['rank']);
        $this->assertSame($preview['rank'], $made['rank']);
        $this->assertEquals($preview['score'], $made['score']);
        $this->assertEqualsWithDelta($made['score'], array_sum($made['parts']), 0.2);

        // A plain date for a due date means the end of that day, in the user's time zone.
        $this->api('PATCH', "tasks/{$made['id']}", $key, ['due_at' => '2026-11-20'])->assertOk()->assertJsonPath('data.dueAt', '2026-11-20T15:59:00Z');
        $this->api('POST', "tasks/{$made['id']}/complete", $key)->assertOk()->assertJsonPath('data.status', 'done');

        $history = $this->api('GET', "tasks/{$made['id']}", $key)->json('data.history');
        $this->assertSame(['completed', 'updated', 'created'], array_column($history, 'type'));
        $this->assertSame(['Mark'], array_values(array_unique(array_column($history, 'via'))));

        // The same task changed on the screen isn't "via" anything.
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->actingAs($this->user)->post(route('tasks.reopen', $made['id']))->assertRedirect();
        $this->assertNull(Task::find($made['id'])->events()->first()->via);
    }

    public function test_today_shows_only_the_parts_the_key_can_read(): void
    {
        $this->user->tasks()->create(['title' => 'Chapter 4', 'importance' => 5, 'urgency' => 4, 'est_hours' => 2]);
        $this->user->habits()->create(['name' => 'Read', 'target' => 1]);

        $all = $this->api('GET', 'today', $this->key())->assertOk()->json('data');
        $this->assertSame('2026-11-12', $all['date']);
        $this->assertSame('Chapter 4', $all['tasks']['top'][0]['title']);
        $this->assertSame('Read', $all['habits'][0]['name']);
        $this->assertSame([], $all['plan']['events']);

        $some = $this->api('GET', 'today', $this->key(['habits:read']))->assertOk()->json('data');
        $this->assertNull($some['tasks']);
        $this->assertNull($some['plan']);
        $this->assertNull($some['people']);
        $this->assertCount(1, $some['habits']);

        // Search leaves out what the key can't read, too.
        $found = $this->api('GET', 'search?q=read', $this->key(['tasks:read']))->assertOk()->json('data');
        $this->assertSame([], $found);
    }

    public function test_the_journal_grows_line_by_line_and_habits_tick_by_count(): void
    {
        $key = $this->key(['notes:read', 'notes:write', 'habits:read', 'habits:write']);

        $this->api('GET', 'journal', $key)->assertOk()->assertJsonPath('data', null);
        $this->api('POST', 'journal', $key, ['text' => 'Met Dr. Lim.'])->assertOk()->assertJsonPath('data.journalDate', '2026-11-12');
        $entry = $this->api('POST', 'journal', $key, ['text' => "Chapter 3 approved.\nStart chapter 4."])->assertOk()->json('data');
        $this->assertSame("Met Dr. Lim.\nChapter 3 approved.\nStart chapter 4.", trim($entry['text']));
        $this->assertSame(1, $this->user->notes()->count());

        $water = $this->user->habits()->create(['name' => 'Water', 'target' => 8, 'unit' => 'glasses']);
        $this->api('POST', "habits/{$water->id}/check", $key, ['count' => 3])->assertOk();
        $this->assertSame(3, $water->entries()->first()->count);
        $this->api('POST', "habits/{$water->id}/check", $key)->assertOk();
        $this->assertSame(8, $water->entries()->first()->count);
        $this->api('POST', "habits/{$water->id}/check", $key, ['date' => '2026-11-13'])->assertStatus(422);
    }

    public function test_every_endpoint_answers_with_the_demo_data(): void
    {
        config(['velocity.fixed_now' => '2026-11-09 09:00']);
        $this->seed(DemoSeeder::class);
        $demo = User::where('email', DemoSeeder::EMAIL)->firstOrFail();
        $key = $this->key(['*'], $demo);
        $task = $demo->tasks()->where('title', 'Write Chapter 4 – Implementation')->firstOrFail();
        $project = $demo->projects()->where('name', 'Final Year Project')->firstOrFail();
        $person = $demo->people()->where('name', 'Dr. Lim Wei')->firstOrFail();
        $habit = $demo->habits()->firstOrFail();

        foreach (['tasks', 'tasks?view=inbox', 'tasks?view=waiting', 'tasks?view=done', "tasks/{$task->id}", 'focus', 'projects', "projects/{$project->id}", 'goals',
            'planner', 'planner?from=2026-11-09&to=2026-11-15', 'notes', 'notes?in=journal', 'habits', 'people', 'people?view=attention', 'people?view=dates',
            "people/{$person->id}", 'review', 'review?period=month', 'review/weekly', 'search?q=chap', 'today'] as $uri) {
            $this->api('GET', $uri, $key)->assertOk();
        }
        $this->api('GET', 'tasks', $key)->assertJsonPath('data.0.title', 'Write Chapter 4 – Implementation')->assertJsonPath('data.0.score', 85.1);

        $this->api('POST', "tasks/{$task->id}/steps", $key, ['title' => 'Draw the diagram'])->assertCreated();
        $this->api('POST', 'focus', $key, ['task_id' => $task->id, 'minutes' => 50])->assertCreated()->assertJsonPath('data.taskId', $task->id);
        $this->api('POST', 'focus/stop', $key)->assertOk()->assertJsonPath('data.counted', false);
        $this->api('POST', 'projects', $key, ['name' => 'Side project'])->assertCreated();
        $this->api('POST', 'goals', $key, ['title' => 'Stay healthy'])->assertCreated();
        $this->api('POST', 'events', $key, ['title' => 'Viva', 'calendar' => 'personal', 'date' => '2026-11-20', 'start_time' => '10:00', 'end_time' => '11:00'])
            ->assertCreated()->assertJsonPath('data.start_time', '10:00');
        $event = $demo->events()->where('title', 'Viva')->firstOrFail();
        $this->api('PATCH', "events/{$event->id}", $key, ['location' => 'Room 2'])->assertOk()->assertJsonPath('data.start_time', '10:00')->assertJsonPath('data.location', 'Room 2');
        $this->api('POST', 'planner/plan', $key, ['span' => 'day'])->assertOk();
        $note = $this->api('POST', 'notes', $key, ['title' => 'Viva prep', 'text' => 'Slides', 'task_id' => $task->id])->assertCreated()->json('data');
        $this->api('PATCH', "notes/{$note['id']}", $key, ['append' => 'Demo video', 'pinned' => true])->assertOk()->assertJsonPath('data.text', "Slides\nDemo video");
        $this->api('POST', 'habits', $key, ['name' => 'Stretch'])->assertCreated();
        $this->api('POST', "habits/{$habit->id}/skip", $key)->assertOk();
        $this->api('POST', 'people', $key, ['name' => 'Aisha', 'catch_up_days' => 30])->assertCreated();
        $this->api('POST', "people/{$person->id}/catch-ups", $key, ['kind' => 'met'])->assertCreated();
        $this->api('DELETE', "tasks/{$task->id}", $key)->assertOk();
        $this->api('POST', "tasks/{$task->id}/restore", $key)->assertOk()->assertJsonPath('data.rank', 1);
    }

    public function test_keys_are_made_with_the_chosen_areas_shown_once_and_revoked(): void
    {
        $this->actingAs($this->user);
        $this->post(route('keys.store'), ['name' => 'Mark', 'full' => false, 'levels' => ['tasks' => 'write', 'review' => 'write', 'notes' => 'read'], 'expires' => '90'])
            ->assertRedirect(route('integrations.edit'))->assertSessionHas('newKey.key');
        $token = $this->user->tokens()->first();
        // Review can only be read, whatever is asked.
        $this->assertSame(['tasks:read', 'tasks:write', 'notes:read', 'review:read'], $token->abilities);
        $this->assertNotNull($token->expires_at);

        $this->post(route('keys.store'), ['name' => 'Nothing', 'full' => false, 'levels' => [], 'expires' => 'never'])->assertSessionHasErrors('levels');

        $this->get(route('integrations.edit'))->assertInertia(fn ($p) => $p->component('settings/integrations')
            ->where('keys.0.access', 'Tasks & focus: read & write · Notes: read · Review: read')->where('newKey', null));
        $this->delete(route('keys.destroy', $token->id))->assertRedirect();
        $this->assertSame(0, $this->user->tokens()->count());

        // FYP mode has no Integrations page.
        $this->user->update(['fyp_mode' => true]);
        $this->get(route('integrations.edit'))->assertRedirect(route('tasks.index'));
    }
}
