<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** The API for Phase 13: lists page by page (tasks, notes, people), with a "next" marker. */
class ApiPhase13Test extends TestCase
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

    private function api(string $uri): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->json('GET', "/api/v1/{$uri}", [], ['Authorization' => 'Bearer '.$this->key]);
    }

    /** Walks every page; returns the titles (or names) in order. */
    private function all(string $uri, string $field): array
    {
        $out = [];
        $cursor = null;
        do {
            $r = $this->api($uri.(str_contains($uri, '?') ? '&' : '?').'limit=2'.($cursor ? "&cursor={$cursor}" : ''))->assertOk();
            $this->assertLessThanOrEqual(2, count($r->json('data')));
            $out = [...$out, ...array_column($r->json('data'), $field)];
            $cursor = $r->json('meta.next');
        } while ($cursor);

        return $out;
    }

    public function test_lists_come_page_by_page(): void
    {
        foreach (['Essay', 'Slides', 'Survey', 'Report', 'Poster'] as $i => $title) {
            $this->user->tasks()->create(['title' => $title, 'importance' => 5 - $i, 'urgency' => 3, 'est_hours' => 1]);
            $this->user->tasks()->create(['title' => "Old {$title}", 'importance' => 3, 'urgency' => 3, 'est_hours' => 1, 'status' => 'done', 'completed_at' => now()->subDays($i + 1)]);
            $this->user->notes()->create(['title' => "Note {$i}", 'body' => '<p>x</p>', 'body_text' => 'x']);
            $this->user->people()->create(['name' => "Person {$i}"]);
        }

        // Up next keeps its rank order across pages; the count is the whole list's.
        $first = $this->api('tasks?limit=2')->assertOk()->assertJsonPath('meta.count', 5)->assertJsonPath('meta.ranked', 5);
        $this->assertSame([1, 2], array_column($first->json('data'), 'rank'));
        $this->assertSame(['Essay', 'Slides', 'Survey', 'Report', 'Poster'], $this->all('tasks', 'title'));
        // Finished: newest first, read a page at a time.
        $this->assertSame(['Old Essay', 'Old Slides', 'Old Survey', 'Old Report', 'Old Poster'], $this->all('tasks?view=done', 'title'));
        $this->assertSame(['Old Survey'], $this->all('tasks?view=done&q=surv', 'title'));
        $this->assertCount(5, $this->all('notes', 'title'));
        $this->assertSame(['Person 0', 'Person 1', 'Person 2', 'Person 3', 'Person 4'], $this->all('people', 'name'));

        // The last page says there is no more; a made-up cursor is turned away.
        $this->api('people?limit=10')->assertJsonPath('meta.next', null)->assertJsonPath('meta.count', 5);
        $this->api('tasks?cursor=not-ours')->assertStatus(422);
    }
}
