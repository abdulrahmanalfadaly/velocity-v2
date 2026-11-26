<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Review: the day view and "What changed" (each count with its items, compared with the period before). */
class ReviewExtrasTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Asia/Kuala_Lumpur';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Wednesday 11 Nov 2026, 10:00 in Kuala Lumpur.
        config(['velocity.fixed_now' => '2026-11-11 10:00', 'velocity.study_log' => false]);
        // History rows get their time from the database's clock: the same moment.
        $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00', self::TZ));
        $this->user = User::factory()->create(['timezone' => self::TZ]);
        $this->actingAs($this->user);
    }

    private function local(string $at): CarbonImmutable
    {
        return CarbonImmutable::parse($at, self::TZ)->utc();
    }

    private function report(array $q = []): array
    {
        $out = null;
        $this->get(route('review.index', $q))->assertOk()->assertInertia(function ($page) use (&$out) {
            $out = $page->toArray()['props']['report'];
        });

        return $out;
    }

    public function test_pick_dates_compares_with_the_same_number_of_days_before(): void
    {
        $done = fn (string $title, string $at) => $this->user->tasks()->create(['title' => $title, 'importance' => 3, 'urgency' => 3, 'est_hours' => 1, 'status' => 'done', 'completed_at' => $this->local($at)]);
        $done('Survey', '2026-09-01 09:00');
        $done('Chapter 2', '2026-10-20 15:00');
        $done('Proposal', '2026-08-31 20:00'); // the day before: in the stretch before
        $done('Old', '2026-06-15 10:00'); // too early for either

        // 1 Sep – 11 Nov (today): 72 days, compared with the 72 days before.
        $r = $this->report(['view' => 'range', 'from' => '2026-09-01', 'to' => '2026-11-11']);
        $this->assertSame(['range', '2026-09-01', '2026-11-11', 72, false], [$r['kind'], $r['start'], $r['last'], $r['length'], $r['inProgress']]);
        $this->assertSame(['start' => '2026-06-21', 'last' => '2026-08-31'], $r['prev']);
        $this->assertSame([2, 1], [$r['now']['tasks'], $r['before']['tasks']]);
        $this->assertCount(72, $r['days']);

        // The other way round is put in order; no dates: the last 30 days; never longer than a year.
        $this->assertSame(['2026-09-01', '2026-10-01'], [($x = $this->report(['view' => 'range', 'from' => '2026-10-01', 'to' => '2026-09-01']))['start'], $x['last']]);
        $this->assertSame(['2026-10-13', '2026-11-11'], [($x = $this->report(['view' => 'range']))['start'], $x['last']]);
        $this->assertSame(366, $this->report(['view' => 'range', 'from' => '2025-01-01', 'to' => '2026-11-11'])['length']);

        // A stretch running past today is "so far", compared with as many days before.
        $r = $this->report(['view' => 'range', 'from' => '2026-11-01', 'to' => '2026-11-30']);
        $this->assertTrue($r['inProgress']);
        $this->assertSame(['start' => '2026-10-02', 'last' => '2026-10-12'], $r['prev']);

        // The API: the same numbers.
        $key = $this->user->createToken('Mark', ['*'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/review?period=range&from=2026-09-01&to=2026-11-11', ['Authorization' => "Bearer {$key}"])
            ->assertOk()->assertJsonPath('data.length', 72)->assertJsonPath('data.now.tasks', 2);
        $this->getJson('/api/v1/review?period=range&from=2025-01-01&to=2026-11-11', ['Authorization' => "Bearer {$key}"])->assertStatus(422);
        $this->getJson('/api/v1/review?period=range', ['Authorization' => "Bearer {$key}"])->assertStatus(422);
    }

    public function test_what_changed_counts_each_kind_with_its_items(): void
    {
        // Added (a repeat's next copy is not "added"), cancelled with a reason, opened again, postponed twice.
        $this->post(route('tasks.store'), ['text' => 'Essay fri']);
        $essay = Task::where('title', 'Essay')->sole();
        $this->post(route('tasks.store'), ['text' => 'Gym every mon']);
        $this->post(route('tasks.complete', Task::where('title', 'Gym')->sole()));
        $this->post(route('tasks.store'), ['text' => 'Old idea']);
        $this->post(route('tasks.cancel', Task::where('title', 'Old idea')->sole()), ['reason' => 'Not needed']);
        $this->post(route('tasks.reopen', Task::where('title', 'Gym')->oldest('id')->first()));
        $this->patch(route('tasks.update', $essay), ['due_at' => '2026-11-16T23:59']); // Fri → Mon
        $this->post(route('tasks.bulk'), ['ids' => [$essay->id], 'action' => 'due', 'due_at' => '2026-11-18T23:59']); // Mon → Wed
        $this->patch(route('tasks.update', $essay), ['due_at' => '2026-11-12T23:59']); // earlier: not postponed

        // A project completed, a goal achieved, a milestone reached, a block moved twice.
        $fyp = $this->user->projects()->create(['name' => 'FYP']);
        $this->post(route('projects.complete', $fyp), ['open' => 'keep']);
        $goal = $this->user->goals()->create(['title' => 'Finish uni', 'color' => 1]);
        $this->patch(route('goals.update', $goal), ['status' => 'achieved']);
        $fyp->milestones()->create(['title' => 'Proposal', 'done_at' => now()]);
        $this->post(route('blocks.store'), ['task_id' => $essay->id, 'date' => '2026-11-12', 'start' => '09:00', 'minutes' => 60]);
        $block = $this->user->plannerBlocks()->sole();
        $this->patch(route('blocks.update', $block), ['date' => '2026-11-12', 'start' => '10:00']);
        $this->patch(route('blocks.update', $block), ['date' => '2026-11-12', 'start' => '10:00', 'minutes' => 90]);

        $r = $this->report();
        $a = $r['activity'];
        $this->assertSame(
            ['created' => 3, 'cancelled' => 1, 'reopened' => 1, 'postponed' => 2, 'projects' => 1, 'goals' => 1, 'milestones' => 1, 'moved' => 2],
            array_map(fn ($x) => $x['count'], $a),
        );
        $this->assertSame(1, $a['moved']['blocks']);
        $this->assertSame(['Old idea', 'Not needed'], [$a['cancelled']['items'][0]['title'], $a['cancelled']['items'][0]['detail']]);
        $this->assertSame(['Mon 16 Nov → Wed 18 Nov', 'Fri 13 Nov → Mon 16 Nov'], array_column($a['postponed']['items'], 'detail'));
        $this->assertSame("/tasks?task={$essay->id}", $a['postponed']['items'][0]['href']);
        $this->assertSame('/projects/'.$fyp->id, $a['projects']['items'][0]['href']);
        $this->assertSame('FYP', $a['milestones']['items'][0]['detail']);
        $this->assertSame(0, $r['activityBefore']['created']);
    }

    public function test_what_changed_leaves_out_what_was_undone(): void
    {
        // Added, then Undo: it is gone, so it isn't "added".
        $this->post(route('tasks.store'), ['text' => 'Typo'])->assertSessionHas('undo.action', 'delete-task');
        $this->delete(route('tasks.destroy', Task::where('title', 'Typo')->sole()));
        // Done, then Undo: not "opened again".
        $this->post(route('tasks.store'), ['text' => 'Essay fri']);
        $essay = Task::where('title', 'Essay')->sole();
        $this->post(route('tasks.complete', $essay))->assertSessionHas('undo', ['action' => 'reopen', 'id' => $essay->id, 'data' => ['undo' => true]]);
        $this->post(route('tasks.reopen', $essay), session('undo')['data']);
        // Cancelled, then Undo: neither "cancelled" nor "opened again".
        $this->post(route('tasks.cancel', $essay), ['reason' => 'No'])->assertSessionHas('undo.action', 'reopen');
        $this->post(route('tasks.reopen', $essay), session('undo')['data']);
        // Postponed, then Undo: from the panel, and from the selection bar.
        $this->patch(route('tasks.update', $essay), ['due_at' => '2026-11-16T23:59'])->assertSessionHas('undo.action', 'revert-task');
        $this->post(route('tasks.revert', $essay), session('undo')['data']);
        $this->post(route('tasks.bulk'), ['ids' => [$essay->id], 'action' => 'due', 'due_at' => '2026-11-18T23:59']);
        $this->post(route('tasks.bulk-undo'), session('undo')['data']);
        $this->assertSame('2026-11-13', $essay->fresh()->due_at->timezone(self::TZ)->toDateString());
        // Opened again on purpose, then Undo: done again, and not "opened again".
        $this->post(route('tasks.complete', $essay));
        $this->post(route('tasks.reopen', $essay))->assertSessionHas('undo.action', 'complete');
        $this->post(route('tasks.complete', $essay), session('undo')['data']);
        $this->assertSame(Task::DONE, $essay->fresh()->status);

        $a = $this->report()['activity'];
        $this->assertSame(['created' => 1, 'cancelled' => 0, 'reopened' => 0, 'postponed' => 0], array_map(fn ($x) => $x['count'], array_slice($a, 0, 4)));
        $this->assertSame('Essay', $a['created']['items'][0]['title']);

        // Opened again on purpose still counts, and so does a cancel that stayed.
        $this->post(route('tasks.reopen', $essay));
        $this->post(route('tasks.cancel', $essay), ['reason' => 'Dropped']);
        $a = $this->report()['activity'];
        $this->assertSame([1, 1], [$a['reopened']['count'], $a['cancelled']['count']]);
    }

    public function test_the_day_view_compares_with_the_day_before_and_counts_event_time(): void
    {
        $uni = $this->user->areas()->create(['name' => 'University', 'icon' => 'cap']);
        $work = $this->user->areas()->create(['name' => 'Work', 'icon' => 'briefcase']);
        $this->post(route('events.store'), [
            'title' => 'Lecture', 'area_id' => $uni->id, 'all_day' => false, 'repeat_weekly' => true, 'date' => '2026-11-10', 'start_time' => '09:00', 'end_time' => '11:00',
        ]);
        $this->post(route('events.store'), [
            'title' => 'Stand-up', 'area_id' => $work->id, 'all_day' => false, 'repeat_weekly' => false, 'date' => '2026-11-11', 'start_time' => '10:00', 'end_time' => '10:30',
        ]);
        $this->post(route('events.store'), ['title' => 'Holiday', 'all_day' => true, 'repeat_weekly' => false, 'date' => '2026-11-11']);
        $this->user->tasks()->create(['title' => 'Yesterday', 'importance' => 3, 'urgency' => 3, 'est_hours' => 1, 'status' => Task::DONE, 'completed_at' => $this->local('2026-11-10 15:00')]);

        $r = $this->report(['view' => 'day']);
        $this->assertSame(['day', '2026-11-11', '2026-11-11', '2026-11-10', 0, 1], [$r['kind'], $r['start'], $r['last'], $r['prev']['start'], $r['now']['tasks'], $r['before']['tasks']]);
        // Time by your areas, most first ("No area" would come last).
        $this->assertSame(['allDay' => 1, 'count' => 1, 'cancelled' => 0, 'byArea' => [['id' => $work->id, 'name' => 'Work', 'icon' => 'briefcase', 'minutes' => 30]]], $r['events']);

        $week = $this->report(['view' => 'week']);
        $this->assertSame([['University', 120], ['Work', 30]], array_map(fn ($a) => [$a['name'], $a['minutes']], $week['events']['byArea']));
        $this->assertSame([], $this->report(['view' => 'day', 'date' => '2026-11-10'])['highlights']);
    }
}
