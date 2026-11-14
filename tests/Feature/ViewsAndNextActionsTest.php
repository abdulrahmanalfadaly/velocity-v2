<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The views of the list in the Tasks menu (filters, never a new order) and the Next actions page. */
class ViewsAndNextActionsTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Asia/Kuala_Lumpur';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Wednesday 11 Nov 2026, 10:00 in Kuala Lumpur.
        config(['velocity.fixed_now' => '2026-11-11 10:00', 'velocity.study_log' => false]);
        $this->user = User::factory()->create(['timezone' => self::TZ]);
        $this->actingAs($this->user);
    }

    private function task(string $title, array $attrs = []): Task
    {
        return $this->user->tasks()->create($attrs + ['title' => $title, 'importance' => 3, 'urgency' => 3, 'est_hours' => 2]);
    }

    private function local(string $at): CarbonImmutable
    {
        return CarbonImmutable::parse($at, self::TZ)->utc();
    }

    public function test_the_menu_counts_each_view_and_the_address_keeps_it(): void
    {
        $this->task('Late essay', ['due_at' => $this->local('2026-11-10 23:59'), 'importance' => 5]);
        $this->task('Quiz prep', ['due_at' => $this->local('2026-11-11 18:00'), 'est_hours' => 1, 'status' => Task::DOING]);
        $this->task('Slides', ['due_at' => $this->local('2026-11-17 09:00'), 'repeat_unit' => 'week']);
        $this->task('Read paper', ['due_at' => $this->local('2026-11-30 09:00'), 'est_hours' => 0.5]);
        $this->task('Waiting one', ['status' => Task::WAITING, 'due_at' => $this->local('2026-11-11 12:00')]); // not ranked: in no view

        $this->get(route('tasks.index', ['show' => 'today']))->assertInertia(fn ($page) => $page
            ->where('show', 'today')
            ->where('nav.counts.views', ['today' => 1, 'week' => 3, 'overdue' => 1, 'quick' => 2, 'doing' => 1, 'important' => 1, 'repeating' => 1])
            ->count('tasks', 4)); // the view filters on screen; every task keeps its number
        $this->get(route('tasks.index', ['show' => 'nonsense']))->assertInertia(fn ($page) => $page->where('show', 'all'));
    }

    public function test_next_actions_show_each_projects_best_task_in_formula_order(): void
    {
        $fyp = $this->user->projects()->create(['name' => 'FYP']);
        $bakery = $this->user->projects()->create(['name' => 'Bakery website']);
        $empty = $this->user->projects()->create(['name' => 'Empty']);
        $this->task('Chapter 4', ['project_id' => $fyp->id, 'importance' => 5, 'urgency' => 5]);
        $this->task('Chapter 5', ['project_id' => $fyp->id, 'importance' => 2]);
        $this->task('Inbox thing', ['importance' => 4]);
        $this->task('Menu photos', ['project_id' => $bakery->id, 'status' => Task::WAITING]);
        $this->task('Logo', ['project_id' => $bakery->id, 'available_from' => $this->local('2026-11-20 09:00')]);

        $this->get(route('tasks.next'))->assertInertia(fn ($page) => $page->component('tasks/next')
            ->count('rows', 2)
            ->where('rows.0.task.title', 'Chapter 4')->where('rows.0.task.rank', 1)->where('rows.0.project.name', 'FYP')
            ->where('rows.1.task.title', 'Inbox thing')->where('rows.1.project', null)
            ->where('idle', [
                ['id' => $bakery->id, 'name' => 'Bakery website', 'goalColor' => null, 'why' => '1 waiting · 1 starting later'],
                ['id' => $empty->id, 'name' => 'Empty', 'goalColor' => null, 'why' => 'No open tasks'],
            ]));
    }
}
