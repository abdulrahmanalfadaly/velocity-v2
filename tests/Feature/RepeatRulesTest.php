<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Repeats on several weekdays, until a day, and moving one copy without moving the rest. */
class RepeatRulesTest extends TestCase
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

    /** Ticks the task off and returns its next copy's due date ("Thu 19 Nov 09:00"), or null when there is none. */
    private function finish(Task $task): ?string
    {
        $this->post(route('tasks.complete', $task));
        $next = Task::where('repeated_from_id', $task->id)->first();

        return $next?->due_at->timezone(self::TZ)->format('D j M H:i');
    }

    private function nextOf(Task $task): Task
    {
        return Task::where('repeated_from_id', $task->id)->sole();
    }

    public function test_a_weekly_repeat_on_several_days(): void
    {
        $task = $this->task(['due_at' => $this->local('2026-11-16 09:00')]);
        $this->patch(route('tasks.update', $task), ['repeat_unit' => 'week', 'repeat_every' => 1, 'repeat_days' => [4, 4]])->assertSessionHasErrors('repeat_days.0');
        $this->patch(route('tasks.update', $task), ['repeat_unit' => 'week', 'repeat_every' => 1, 'repeat_days' => [4, 1]]);
        $this->assertSame([1, 4], $task->fresh()->repeat_days);
        $this->get(route('tasks.index', ['task' => $task->id]))->assertInertia(fn ($page) => $page->where('selected.repeat', ['unit' => 'week', 'every' => 1, 'days' => [1, 4]]));

        $this->assertSame('Thu 19 Nov 09:00', $this->finish($task));
        $this->assertSame('Mon 23 Nov 09:00', $this->finish($this->nextOf($task)));
    }

    public function test_every_two_weeks_on_mon_and_thu_skips_the_week_in_between(): void
    {
        $task = $this->task(['due_at' => $this->local('2026-11-16 09:00')]);
        $this->patch(route('tasks.update', $task), ['repeat_unit' => 'week', 'repeat_every' => 2, 'repeat_days' => [1, 4]]);
        $this->assertSame('Thu 19 Nov 09:00', $this->finish($task));
        $this->assertSame('Mon 30 Nov 09:00', $this->finish($this->nextOf($task)));
    }

    public function test_no_copy_after_the_last_day(): void
    {
        $task = $this->task(['due_at' => $this->local('2026-11-18 09:00')]);
        $this->patch(route('tasks.update', $task), ['repeat_unit' => 'week', 'repeat_every' => 1, 'repeat_until' => '2026-11-25']);
        $this->assertSame('Wed 25 Nov 09:00', $this->finish($task));
        $this->assertNull($this->finish($this->nextOf($task)));

        // Stop repeating: the days and the end go too.
        $other = $this->task(['due_at' => $this->local('2026-11-18 09:00')]);
        $this->patch(route('tasks.update', $other), ['repeat_unit' => 'week', 'repeat_days' => [1, 3], 'repeat_until' => '2026-12-31']);
        $this->patch(route('tasks.update', $other), ['repeat_unit' => null]);
        $other->refresh();
        $this->assertSame([null, null, null], [$other->repeat_days, $other->repeat_until, $other->repeat_anchor]);
    }

    public function test_moving_one_copy_doesnt_move_the_rest(): void
    {
        $task = $this->task(['due_at' => $this->local('2026-11-16 09:00')]);
        $this->patch(route('tasks.update', $task), ['repeat_unit' => 'week', 'repeat_every' => 1]);
        $this->patch(route('tasks.update', $task), ['due_at' => '2026-11-17T14:00']);
        $this->assertSame('Mon 23 Nov 09:00', $this->finish($task));

        // An older copy (made before copies remembered their day) keeps its series too.
        $old = $this->task(['title' => 'Old', 'due_at' => $this->local('2026-11-16 09:00'), 'repeat_unit' => 'week', 'repeat_every' => 1]);
        $this->patch(route('tasks.update', $old), ['due_at' => '2026-11-18T09:00']);
        $this->assertSame('Mon 23 Nov 09:00', $this->finish($old));

        // A new rule starts the series from where the task is now.
        $moved = $this->task(['title' => 'Moved', 'due_at' => $this->local('2026-11-16 09:00')]);
        $this->patch(route('tasks.update', $moved), ['repeat_unit' => 'week', 'repeat_every' => 1]);
        $this->patch(route('tasks.update', $moved), ['due_at' => '2026-11-18T09:00']);
        $this->patch(route('tasks.update', $moved), ['repeat_every' => 2]);
        $this->assertSame('Wed 2 Dec 09:00', $this->finish($moved));
    }

    public function test_quick_add_reads_several_days_and_until(): void
    {
        $preview = fn (string $text) => $this->postJson(route('tasks.preview'), ['text' => $text])->json('parsed');

        $gym = $preview('Gym every mon and thu 7am');
        $this->assertSame(['Gym', ['unit' => 'week', 'every' => 1, 'days' => [1, 4]], '2026-11-11T23:00:00Z'], [$gym['title'], $gym['repeat'], $gym['due']]);
        $this->assertSame(['unit' => 'week', 'every' => 1, 'days' => [1, 3, 5]], $preview('Report every mon, wed, fri')['repeat']);
        $this->assertSame('2026-11-11T15:59:00Z', $preview('Report every mon, wed, fri')['due']); // Wednesday: today
        $this->assertSame(['unit' => 'weekday', 'every' => 1, 'until' => '2026-12-20'], $preview('Stand-up every weekday until 20 dec')['repeat']);
        $this->assertSame('Stand-up', $preview('Stand-up every weekday until 20 dec')['title']);
        // "until" with no repeat stays a word.
        $this->assertSame(['Wait until', null], [$preview('Wait until friday')['title'], $preview('Wait until friday')['repeat']]);

        $this->post(route('tasks.store'), ['text' => 'Gym every mon and thu 7am until 31 dec']);
        $task = Task::where('title', 'Gym')->sole();
        $this->assertSame([[1, 4], '2026-12-31', '2026-11-12 07:00'], [$task->repeat_days, $task->repeat_until->toDateString(), $task->repeat_anchor->timezone(self::TZ)->format('Y-m-d H:i')]);

        $event = $this->postJson(route('events.preview'), ['text' => 'Gym every mon 6pm until 20 dec'])->json();
        $this->assertSame([true, '2026-12-20', '2026-11-16'], [$event['weekly'], $event['until'], $event['date']]);
    }
}
