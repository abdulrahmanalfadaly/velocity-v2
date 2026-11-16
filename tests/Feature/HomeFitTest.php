<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Phase 11: a task's energy (a filter only) and Home's "I have 30 min, low energy". */
class HomeFitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['velocity.fixed_now' => '2026-11-11 10:00', 'velocity.study_log' => false]);
        $this->user = User::factory()->create(['timezone' => 'Asia/Kuala_Lumpur']);
        $this->actingAs($this->user);
    }

    private function task(string $title, array $attrs = []): Task
    {
        return $this->user->tasks()->create($attrs + ['title' => $title, 'importance' => 3, 'urgency' => 3, 'est_hours' => 1]);
    }

    public function test_energy_comes_from_quick_add_and_never_changes_the_score(): void
    {
        $this->post(route('tasks.store'), ['text' => 'Tidy notes ~low 20m'])->assertSessionHasNoErrors();
        $tidy = $this->user->tasks()->where('title', 'Tidy notes')->sole();
        $this->assertSame('low', $tidy->energy);
        $preview = $this->postJson(route('tasks.preview'), ['text' => 'Essay ~high'])->json('parsed');
        $this->assertSame(['high', [['kind' => 'energy', 'text' => '~high']]], [$preview['energy'], $preview['picked']]);

        $score = fn () => collect($this->get(route('tasks.index'))->viewData('page')['props']['tasks'] ?? [])->firstWhere('id', $tidy->id)['score'] ?? null;
        $before = $score();
        $this->assertNotNull($before);
        $this->patch(route('tasks.update', $tidy), ['energy' => 'high'])->assertSessionHasNoErrors();
        $this->assertSame('high', $tidy->fresh()->energy);
        $this->assertSame($before, $score());
        $this->patch(route('tasks.update', $tidy), ['energy' => 'sleepy'])->assertSessionHasErrors('energy');
    }

    public function test_home_shows_the_top_tasks_that_fit_the_time_and_energy_in_the_same_order(): void
    {
        $this->task('Big report', ['importance' => 5, 'urgency' => 5, 'est_hours' => 3]);
        $this->task('Reply to Ali', ['importance' => 4, 'urgency' => 4, 'est_hours' => 0.25, 'energy' => 'low']);
        $this->task('Fix the chart', ['importance' => 4, 'urgency' => 3, 'est_hours' => 0.5, 'energy' => 'high']);
        $this->task('Book the room', ['importance' => 2, 'urgency' => 2, 'est_hours' => 0.25]); // no energy set: counts as normal

        $titles = fn (array $q) => collect($this->get(route('home', $q))->viewData('page')['props']['top'])->pluck('title')->all();
        $this->assertSame(['Big report', 'Reply to Ali', 'Fix the chart'], $titles([]));
        $this->assertSame(['Reply to Ali', 'Fix the chart', 'Book the room'], $titles(['time' => '30']));
        $this->assertSame(['Reply to Ali'], $titles(['time' => '30', 'energy' => 'low']));
        $this->assertSame(['Reply to Ali', 'Book the room'], $titles(['energy' => 'normal', 'time' => '15']));

        // The task keeps its own rank: #2 of 4.
        $this->get(route('home', ['time' => '15']))->assertInertia(fn (Assert $p) => $p
            ->where('top.0.rank', 2)
            ->where('fit', ['time' => '15', 'energy' => null, 'count' => 2]));
    }
}
