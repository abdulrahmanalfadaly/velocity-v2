<?php

namespace Tests\Feature;

use App\Models\Habit;
use App\Models\Note;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** The API for Phase 12: notes in several projects, areas and archived; habits' goals and numbers; people's nicknames, memories and follow-ups. */
class ApiPhase12Test extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        config(['velocity.fixed_now' => '2026-11-11 10:00', 'velocity.study_log' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00', 'Asia/Kuala_Lumpur'));
        $this->user = User::factory()->create(['timezone' => 'Asia/Kuala_Lumpur']);
        $this->key = $this->user->createToken('Mark', ['*'])->plainTextToken;
    }

    private function api(string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, "/api/v1/{$uri}", $data, ['Authorization' => 'Bearer '.$this->key]);
    }

    public function test_notes_habits_and_people(): void
    {
        $fyp = $this->user->projects()->create(['name' => 'FYP']);
        $dm = $this->user->projects()->create(['name' => 'Data Mining']);
        $id = $this->api('POST', 'notes', ['title' => 'Survey method', 'text' => 'x'])->assertCreated()->json('data.id');
        $this->api('PATCH', "notes/{$id}", ['project_ids' => [$fyp->id, $dm->id], 'archived' => true])->assertOk()
            ->assertJsonPath('data.projectIds', [$fyp->id, $dm->id])->assertJsonPath('data.archivedAt', fn ($v) => $v !== null);
        $this->api('PATCH', "notes/{$id}", ['title' => 'Changed'])->assertStatus(422);
        $this->api('GET', 'notes')->assertJsonCount(0, 'data');
        $this->api('GET', "notes?in=archived&project_id={$dm->id}")->assertJsonPath('data.0.id', $id);
        $this->assertSame('Survey method', Note::find($id)->title);

        $goal = $this->user->goals()->create(['title' => 'Stay healthy']);
        $habitId = $this->api('POST', 'habits', ['name' => 'Walk', 'kind' => 'do', 'goal_ids' => [$goal->id], 'why' => 'Fresh air'])->assertCreated()->json('data.id');
        $this->api('GET', "habits/{$habitId}?range=90")->assertOk()
            ->assertJsonPath('data.goals.0.title', 'Stay healthy')->assertJsonPath('data.why', 'Fresh air')->assertJsonPath('data.stats.range', 90);
        $this->api('PATCH', "habits/{$habitId}", ['name' => 'Walk 30 min'])->assertOk();
        $this->assertSame(1, Habit::find($habitId)->goals()->count());

        $personId = $this->api('POST', 'people', ['name' => 'Sara Lim'])->assertCreated()->json('data.id');
        $this->api('PATCH', "people/{$personId}", ['nickname' => 'Saz', 'location' => 'Penang'])->assertOk()->assertJsonPath('data.nickname', 'Saz');
        $this->api('POST', "people/{$personId}/memories", ['text' => 'Loves matcha'])->assertCreated()->assertJsonPath('data.text', 'Loves matcha');
        $this->api('POST', "people/{$personId}/catch-ups", ['kind' => 'message', 'at' => '18:00', 'counts' => false])->assertCreated();
        $this->api('POST', "people/{$personId}/follow-up", ['on' => '2026-11-14'])->assertCreated()
            ->assertJsonPath('data.title', 'Follow up with Sara')->assertJsonPath('data.status', 'waiting');
        $this->api('GET', "people/{$personId}")->assertOk()
            ->assertJsonPath('data.memories.0.text', 'Loves matcha')
            ->assertJsonPath('data.keepInTouch.history.0.counts', false)
            ->assertJsonPath('data.keepInTouch.last', null);
    }
}
