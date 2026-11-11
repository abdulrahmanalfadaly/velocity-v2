<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Repeating tasks: finishing one creates the next copy with the next due date. */
class RepeatingTasksTest extends TestCase
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

    private function task(array $attrs = []): Task
    {
        return $this->user->tasks()->create($attrs + ['title' => 'Gym', 'importance' => 3, 'urgency' => 3, 'est_hours' => 1]);
    }

    private function local(string $at): CarbonImmutable
    {
        return CarbonImmutable::parse($at, self::TZ)->utc();
    }

    private function copyOf(Task $task): ?Task
    {
        return Task::where('repeated_from_id', $task->id)->first();
    }

    public function test_finishing_a_repeating_task_creates_the_next_one(): void
    {
        $task = $this->task(['due_at' => $this->local('2026-11-16 07:00'), 'repeat_unit' => 'week', 'notes' => 'Legs day']);
        $task->steps()->create(['title' => 'Warm up', 'position' => 0, 'done_at' => now()]);
        $task->steps()->create(['title' => 'Stretch', 'position' => 1]);

        $this->post(route('tasks.complete', $task))->assertSessionHas('toast', 'Done: “Gym” · next one Mon 23 Nov');

        $this->assertSame(Task::DONE, $task->fresh()->status);
        $next = $this->copyOf($task);
        $this->assertNotNull($next);
        $this->assertSame(Task::TODO, $next->status);
        $this->assertSame('2026-11-23 07:00', $next->due_at->timezone(self::TZ)->format('Y-m-d H:i'));
        $this->assertSame(['Gym', 'Legs day', 3, 3, 1.0, 'week', 1], [$next->title, $next->notes, $next->importance, $next->urgency, $next->est_hours, $next->repeat_unit, $next->repeat_every]);
        $this->assertSame([['Warm up', null], ['Stretch', null]], $next->steps->map(fn ($s) => [$s->title, $s->done_at])->all());
        // The copy is an ordinary ranked task.
        $this->get(route('tasks.index'))->assertInertia(fn ($page) => $page->where('tasks.0.id', $next->id)->where('tasks.0.repeat', ['unit' => 'week', 'every' => 1]));
    }

    public function test_an_overdue_repeating_task_skips_dates_already_past(): void
    {
        $task = $this->task(['due_at' => $this->local('2026-11-02 09:00'), 'repeat_unit' => 'day']);
        $this->post(route('tasks.complete', $task));

        // Mon 2 Nov was overdue; Tue 10 and Wed 11 at 9:00 are already past at Wed 10:00, so the next one is Thursday.
        $this->assertSame('2026-11-12 09:00', $this->copyOf($task)->due_at->timezone(self::TZ)->format('Y-m-d H:i'));
    }

    public function test_weekdays_skip_the_weekend_and_months_keep_to_the_month_end(): void
    {
        $friday = $this->task(['due_at' => $this->local('2026-11-13 09:00'), 'repeat_unit' => 'weekday']);
        $january = $this->task(['due_at' => $this->local('2027-01-31 12:00'), 'repeat_unit' => 'month']);
        $everyThird = $this->task(['repeat_unit' => 'day', 'repeat_every' => 3]);
        $this->post(route('tasks.complete', $friday));
        $this->post(route('tasks.complete', $january));
        $this->post(route('tasks.complete', $everyThird));

        $this->assertSame('Mon 16 Nov 09:00', $this->copyOf($friday)->due_at->timezone(self::TZ)->format('D j M H:i'));
        $this->assertSame('Sun 28 Feb 12:00', $this->copyOf($january)->due_at->timezone(self::TZ)->format('D j M H:i'));
        // No due date: it repeats from the end of today.
        $this->assertSame('Sat 14 Nov 23:59', $this->copyOf($everyThird)->due_at->timezone(self::TZ)->format('D j M H:i'));
    }

    public function test_undo_takes_back_the_copy_and_finishing_again_makes_only_one(): void
    {
        $task = $this->task(['due_at' => $this->local('2026-11-16 07:00'), 'repeat_unit' => 'week']);
        $this->post(route('tasks.complete', $task));
        $first = $this->copyOf($task);

        $this->post(route('tasks.reopen', $task));
        $this->assertSame(Task::TODO, $task->fresh()->status);
        $this->assertSoftDeleted($first);

        $this->post(route('tasks.complete', $task));
        $this->post(route('tasks.complete', $task)); // a second click must not make a second copy
        $this->assertSame(1, Task::where('repeated_from_id', $task->id)->count());
    }

    public function test_a_task_that_does_not_repeat_makes_no_copy(): void
    {
        $task = $this->task(['due_at' => $this->local('2026-11-16 07:00')]);
        $this->post(route('tasks.complete', $task))->assertSessionHas('toast', 'Done: “Gym”');
        $this->assertNull($this->copyOf($task));
    }

    public function test_the_repeat_can_be_set_and_cleared_from_the_task_panel(): void
    {
        $task = $this->task();
        $this->patch(route('tasks.update', $task), ['repeat_unit' => 'week', 'repeat_every' => 2])->assertSessionHasNoErrors();
        $this->assertSame(['week', 2], [$task->fresh()->repeat_unit, $task->fresh()->repeat_every]);
        $this->get(route('tasks.index', ['task' => $task->id]))->assertInertia(fn ($page) => $page->where('selected.repeat', ['unit' => 'week', 'every' => 2]));

        $this->patch(route('tasks.update', $task), ['repeat_unit' => 'fortnight'])->assertSessionHasErrors('repeat_unit');
        $this->patch(route('tasks.update', $task), ['repeat_every' => 0])->assertSessionHasErrors('repeat_every');

        $this->patch(route('tasks.update', $task), ['repeat_unit' => null, 'repeat_every' => 1]);
        $this->assertNull($task->fresh()->repeat_unit);
    }

    public function test_quick_add_reads_every_but_leaves_other_words_alone(): void
    {
        $this->post(route('tasks.store'), ['text' => 'Gym every monday 1h']);
        $gym = Task::where('title', 'Gym')->firstOrFail();
        $this->assertSame(['week', 1, 'Mon 16 Nov 23:59', 1.0], [$gym->repeat_unit, $gym->repeat_every, $gym->due_at->timezone(self::TZ)->format('D j M H:i'), $gym->est_hours]);

        $this->post(route('tasks.store'), ['text' => 'Water plants every 3 days']);
        $plants = Task::where('title', 'Water plants')->firstOrFail();
        $this->assertSame(['day', 3], [$plants->repeat_unit, $plants->repeat_every]);

        $this->post(route('tasks.store'), ['text' => 'Weekly report fri']);
        $this->post(route('tasks.store'), ['text' => 'Read every chapter']);
        $this->assertNull(Task::where('title', 'Weekly report')->firstOrFail()->repeat_unit);
        $this->assertNull(Task::where('title', 'Read every chapter')->firstOrFail()->repeat_unit);

        $this->postJson(route('tasks.preview'), ['text' => 'Review every other week'])
            ->assertJsonPath('parsed.title', 'Review')
            ->assertJsonPath('parsed.repeat', ['unit' => 'week', 'every' => 2]);
    }
}
