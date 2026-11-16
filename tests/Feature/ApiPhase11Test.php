<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\PlannerBlock;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** The API for Phase 11: project and reserved time, locks, events moved and cancelled, markers, focus subjects, energy and capture. */
class ApiPhase11Test extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Asia/Kuala_Lumpur';

    private User $user;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        config(['velocity.fixed_now' => '2026-11-11 10:00', 'velocity.study_log' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00', self::TZ));
        $this->user = User::factory()->create(['timezone' => self::TZ]);
        $this->key = $this->user->createToken('Mark', ['*'])->plainTextToken;
    }

    private function api(string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, "/api/v1/{$uri}", $data, ['Authorization' => 'Bearer '.$this->key]);
    }

    public function test_planner_blocks_kinds_locks_and_events(): void
    {
        $fyp = $this->user->projects()->create(['name' => 'FYP', 'due_date' => '2026-11-13']);
        $task = $this->user->tasks()->create(['title' => 'Essay', 'importance' => 3, 'urgency' => 3, 'est_hours' => 1]);
        $other = $this->user->tasks()->create(['title' => 'Slides', 'importance' => 3, 'urgency' => 3, 'est_hours' => 1]);

        $this->api('POST', 'planner/blocks', ['kind' => 'project', 'project_id' => $fyp->id, 'date' => '2026-11-12', 'start' => '14:00', 'minutes' => 120])
            ->assertCreated()->assertJsonPath('data.kind', 'project')->assertJsonPath('data.title', 'FYP');
        $this->api('POST', 'planner/blocks', ['kind' => 'reserved', 'label' => 'Gym', 'date' => '2026-11-12', 'start' => '18:00', 'minutes' => 60])
            ->assertCreated()->assertJsonPath('data.title', 'Gym');
        $id = $this->api('POST', 'planner/blocks', ['task_id' => $task->id, 'date' => '2026-11-12', 'start' => '09:00', 'minutes' => 60])->json('data.id');

        $this->api('PATCH', "planner/blocks/{$id}", ['locked' => true])->assertOk()->assertJsonPath('data.locked', true);
        $this->api('PATCH', "planner/blocks/{$id}", ['start' => '10:00'])->assertStatus(409);
        $this->api('PATCH', "planner/blocks/{$id}", ['start' => '10:00', 'anyway' => true])->assertOk();
        $this->api('PATCH', "planner/blocks/{$id}", ['task_id' => $other->id])->assertOk()->assertJsonPath('data.title', 'Slides');
        $this->assertSame('10:00', PlannerBlock::find($id)->starts_at->timezone(self::TZ)->format('H:i'));

        $this->api('POST', 'events', ['title' => 'Lecture', 'calendar' => 'class', 'date' => '2026-11-12', 'start_time' => '09:00', 'end_time' => '11:00', 'repeat_weekly' => true]);
        $event = Event::sole();
        $this->api('PATCH', "events/{$event->id}/move", ['occurrence' => '2026-11-19', 'date' => '2026-11-19', 'start' => '13:00', 'minutes' => 60, 'scope' => 'one'])
            ->assertOk()->assertJsonPath('data.copy.start_time', '13:00');
        $this->api('POST', "events/{$event->id}/cancel", ['date' => '2026-11-12'])->assertOk()->assertJsonPath('data.cancelled', ['2026-11-12']);

        $this->api('GET', 'planner?from=2026-11-12&to=2026-11-13')->assertOk()
            ->assertJsonPath('data.events.0.cancelled', true)
            ->assertJsonPath('data.markers.0.kind', 'project')
            ->assertJsonPath('data.booked.0.kind', 'task');
    }

    public function test_focus_subjects_energy_and_capture(): void
    {
        $goal = $this->user->goals()->create(['title' => 'Graduate with a First']);
        $this->api('POST', 'focus', ['goal_id' => $goal->id, 'minutes' => 25])->assertCreated()
            ->assertJsonPath('data.subject.kind', 'goal')->assertJsonPath('data.subject.title', 'Graduate with a First');

        $id = $this->api('POST', 'tasks', ['text' => 'Idea for the video ~low', 'capture' => true])->assertCreated()
            ->assertJsonPath('data.energy', 'low')->json('data.id');
        $this->assertNotNull(Task::find($id)->captured_at);
        $this->api('GET', 'tasks?view=captured')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->api('GET', 'tasks?view=upnext')->assertOk()->assertJsonCount(0, 'data');
        $this->api('POST', "tasks/{$id}/rank")->assertOk()->assertJsonPath('data.rank', 1)->assertJsonPath('data.capturedAt', null);
        $this->api('PATCH', "tasks/{$id}", ['energy' => 'high'])->assertOk()->assertJsonPath('data.energy', 'high');
    }
}
