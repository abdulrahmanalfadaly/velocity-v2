<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use App\Services\RankingService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The real app must rank exactly like the approved mockups, which were scored
 * by the same engine at Monday 9 Nov 2026, 9:00 am.
 */
class RankingServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['velocity.fixed_now' => '2026-11-09 09:00', 'velocity.study_log' => false]);
        $this->seed(DemoSeeder::class);
        $this->user = User::where('email', DemoSeeder::EMAIL)->firstOrFail();
    }

    public function test_demo_ranking_matches_the_approved_mockups(): void
    {
        $list = app(RankingService::class)->rank($this->user)->toArray();

        $this->assertSame(
            [
                ['Write Chapter 4 – Implementation', 85.1], ['Test plan for Assignment 2', 78.7],
                ['Lab report 3 – Mobile App Dev', 67.5], ['Email Dr. Lim about the ethics form', 61.0],
                ['FYP progress presentation slides', 60.6], ['Revise for Data Mining quiz 3', 55.9],
                ['Homepage mockup for Pak Ali’s bakery', 46.9], ['Submit internship logbook', 45.8],
                ['Past-year paper with study group', 44.0], ['Update CV for internship applications', 43.4],
                ['Plan meals and groceries', 43.1], ['Renew library books', 29.0],
                ['Read: rank reversal in TOPSIS', 27.0], ['Book a dentist appointment', 16.3],
            ],
            array_map(fn ($t) => [$t['title'], $t['score']], $list),
        );
        $this->assertSame(['I' => 42.0, 'U' => 17.3, 'D' => 16.7, 'P' => 9.1], $list[0]['contrib']);
        $this->assertStringStartsWith('Above Test plan for Assignment 2 mainly because it is more important (+10.5 points)', $list[0]['contrast']);
        $this->assertTrue($list[3]['nearTie'], '#4 and #5 are 0.4 apart');
        $this->assertFalse($list[0]['nearTie']);
    }

    public function test_waiting_blocked_and_later_tasks_are_left_out_not_scored_lower(): void
    {
        $service = app(RankingService::class);
        $ranked = collect($service->rank($this->user)->toArray())->pluck('title');
        $notReady = $service->notReady($this->user)->mapWithKeys(fn ($r) => [$r['task']->title => $r['kind']]);

        $this->assertSame([
            'Revise Chapter 3 after feedback' => 'waiting',
            'Pilot the survey with 5 friends' => 'blocked',
            'Sign up for the Nilai 5K' => 'later',
        ], $notReady->all());
        foreach ($notReady->keys() as $title) {
            $this->assertNotContains($title, $ranked);
        }
    }

    public function test_finishing_the_blocking_task_releases_the_blocked_one(): void
    {
        $service = app(RankingService::class);
        Task::where('title', 'Email Dr. Lim about the ethics form')->update(['status' => Task::DONE]);

        $this->assertContains('Pilot the survey with 5 friends', collect($service->rank($this->user)->toArray())->pluck('title'));
    }

    public function test_rank_stability_adding_a_task_never_reorders_the_others(): void
    {
        $service = app(RankingService::class);
        $before = collect($service->rank($this->user)->toArray())->pluck('title')->all();

        $this->user->tasks()->create(['title' => 'Brand new task', 'importance' => 4, 'urgency' => 4, 'est_hours' => 2, 'due_at' => now()->addDays(3)]);
        $after = collect($service->rank($this->user)->toArray())->pluck('title')->reject(fn ($t) => $t === 'Brand new task')->values()->all();

        $this->assertSame($before, $after);
    }

    public function test_tasks_of_other_users_never_leak_into_a_ranking(): void
    {
        $other = User::factory()->create();
        $other->tasks()->create(['title' => 'Someone else’s task', 'importance' => 5, 'urgency' => 5, 'est_hours' => 1]);

        $this->assertNotContains('Someone else’s task', collect(app(RankingService::class)->rank($this->user)->toArray())->pluck('title'));
    }
}
