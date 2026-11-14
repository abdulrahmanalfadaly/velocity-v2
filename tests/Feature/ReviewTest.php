<?php

namespace Tests\Feature;

use App\Models\FocusSession;
use App\Models\Task;
use App\Models\User;
use App\Services\ReviewService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Review: weeks and months in the user's own days, the comparison with the same
 * stretch before, focus by day and project, first guess vs time focused, the
 * weekly review tick, and Ctrl K's classes, #1 task and "See all results".
 */
class ReviewTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Asia/Kuala_Lumpur';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Thursday 12 Nov 2026, 07:00 in Kuala Lumpur.
        config(['velocity.timezone' => self::TZ, 'velocity.fixed_now' => '2026-11-12 07:00', 'velocity.study_log' => false]);
        $this->user = User::factory()->create(['timezone' => self::TZ]);
        $this->actingAs($this->user);
    }

    private function local(string $at): CarbonImmutable
    {
        return CarbonImmutable::parse($at, self::TZ)->utc();
    }

    private function done(string $title, string $at, array $extra = []): Task
    {
        return $this->user->tasks()->create($extra + ['title' => $title, 'importance' => 3, 'urgency' => 3, 'est_hours' => 1, 'status' => 'done', 'completed_at' => $this->local($at)]);
    }

    private function focus(?Task $task, string $at, int $minutes): void
    {
        $start = $this->local($at);
        FocusSession::forceCreate(['user_id' => $this->user->id, 'task_id' => $task?->id, 'started_at' => $start, 'ended_at' => $start->addMinutes($minutes), 'seconds' => $minutes * 60]);
    }

    private function report(array $q = []): array
    {
        $out = [];
        $this->get(route('review.index', $q))->assertInertia(function ($page) use (&$out) {
            $out = $page->toArray()['props'];
        });

        return $out;
    }

    public function test_a_week_still_going_is_compared_with_the_same_days_of_last_week_and_months_with_last_month(): void
    {
        $today = CarbonImmutable::parse('2026-11-12', self::TZ);
        $w = ReviewService::period('week', $today, $today);
        $this->assertSame(['2026-11-09', '2026-11-16', '2026-11-13', '2026-11-02', '2026-11-06'], [
            $w['start']->toDateString(), $w['end']->toDateString(), $w['until']->toDateString(), $w['prevStart']->toDateString(), $w['prevUntil']->toDateString(),
        ]);
        $this->assertTrue($w['inProgress']);

        // The last day of March is compared with the whole of February, no further.
        $march = CarbonImmutable::parse('2027-03-31', self::TZ);
        $m = ReviewService::period('month', $march, $march);
        $this->assertSame(['2027-03-01', '2027-04-01', '2027-02-01', '2027-03-01'], [
            $m['start']->toDateString(), $m['until']->toDateString(), $m['prevStart']->toDateString(), $m['prevUntil']->toDateString(),
        ]);
    }

    public function test_tasks_count_on_the_users_own_day_and_against_the_same_days_before(): void
    {
        $this->done('Just after midnight Monday', '2026-11-09 01:00'); // Sunday evening in UTC
        $this->done('Late Sunday', '2026-11-08 23:00');                // last week, after the compared days
        $this->done('Last Tuesday', '2026-11-03 10:00', ['due_at' => $this->local('2026-11-04 12:00')]);

        $r = $this->report()['report'];
        $this->assertSame(1, $r['now']['tasks']);
        $this->assertSame(1, $r['days'][0]['done']);
        $this->assertSame(1, $r['before']['tasks']);       // Mon–Thu of last week: only Tuesday's
        $this->assertSame(100, $r['before']['onTime']);
        $this->assertTrue($r['days'][4]['future']);       // Friday hasn't come yet

        $last = $this->report(['view' => 'week', 'date' => '2026-11-05'])['report'];
        $this->assertSame(2, $last['now']['tasks']);
        $this->assertFalse($last['inProgress']);
    }

    public function test_focus_is_split_by_day_and_by_project(): void
    {
        $fyp = $this->user->projects()->create(['name' => 'FYP']);
        $task = $this->user->tasks()->create(['title' => 'Chapter 4', 'importance' => 4, 'urgency' => 3, 'est_hours' => 3, 'project_id' => $fyp->id]);
        $this->focus($task, '2026-11-09 10:00', 50);
        $this->focus(null, '2026-11-10 21:00', 30);
        $this->focus($task, '2026-11-02 10:00', 20); // last week

        $r = $this->report()['report'];
        $this->assertSame(80, $r['now']['focus']);
        $this->assertSame(20, $r['before']['focus']);
        $this->assertSame([50, 30, 0, 0], array_slice(array_column($r['days'], 'focus'), 0, 4));
        $this->assertSame([['FYP', 50], ['No project', 30]], array_map(fn ($p) => [$p['name'], $p['minutes']], $r['byProject']));
    }

    public function test_the_first_guess_is_kept_when_hours_left_changed_it_later(): void
    {
        $task = $this->user->tasks()->create(['title' => 'Literature review', 'importance' => 3, 'urgency' => 3, 'est_hours' => 2]);
        $this->focus($task, '2026-11-10 09:00', 120);
        $this->patch(route('tasks.update', $task), ['est_hours' => 0.5])->assertSessionHasNoErrors(); // "1/2 h left" after focusing
        $this->focus($task, '2026-11-11 09:00', 60);
        $this->post(route('tasks.complete', $task))->assertSessionHasNoErrors();

        $e = $this->report()['report']['estimates'];
        $this->assertEquals([['Literature review', 2, 180]], array_map(fn ($x) => [$x['title'], $x['guess'], $x['focused']], $e));
    }

    public function test_the_weekly_review_can_be_ticked_off_and_undone(): void
    {
        $this->user->tasks()->create(['title' => 'Sort me', 'importance' => 3, 'urgency' => 3, 'est_hours' => 1]);
        $this->user->tasks()->create(['title' => 'Due next week', 'importance' => 3, 'urgency' => 3, 'est_hours' => 1, 'due_at' => $this->local('2026-11-18 17:00')]);

        $w = $this->report(['view' => 'weekly'])['weekly'];
        $this->assertNull($w['doneAt']);
        $this->assertSame(2, $w['inbox']);
        $this->assertSame('Due next week', $w['deadlines'][0]['title']);
        $this->assertSame('2026-11-16', $w['nextWeek']);

        $this->post(route('review.done'))->assertSessionHas('undo.action', 'undo-weekly-review');
        $this->assertNotNull($this->report(['view' => 'weekly'])['weekly']['doneAt']);
        $this->delete(route('review.undone'))->assertSessionHasNoErrors();
        $this->assertNull($this->report(['view' => 'weekly'])['weekly']['doneAt']);
    }

    public function test_ctrl_k_finds_classes_offers_the_top_task_and_shows_all_results_with_note_words(): void
    {
        $this->post(route('events.store'), [
            'title' => 'Data Mining', 'calendar' => 'class', 'all_day' => false, 'repeat_weekly' => true,
            'date' => '2026-11-09', 'start_time' => '09:00', 'end_time' => '11:00', 'location' => 'Lab 3',
        ])->assertSessionHasNoErrors();
        $this->user->tasks()->create(['title' => 'Mining report', 'importance' => 5, 'urgency' => 5, 'est_hours' => 1]);
        $note = $this->user->notes()->create(['title' => 'Lecture', 'body_text' => 'Today we covered data mining with decision trees and pruning.']);

        $palette = $this->getJson(route('search', ['q' => 'mining']))->json();
        $this->assertSame('Mining report', $palette['top']['title']);
        $events = collect($palette['groups'])->firstWhere('label', 'Classes and events')['items'];
        $this->assertSame('Data Mining', $events[0]['title']);
        $this->assertStringContainsString('next Mon 16 Nov', $events[0]['sub']);
        $this->assertStringContainsString('date=2026-11-16', $events[0]['url']);

        $this->get(route('search.all', ['q' => 'decision trees']))->assertInertia(fn ($page) => $page
            ->component('search/index')
            ->where('groups.0.label', 'Notes')
            ->where('groups.0.items.0.id', $note->id)
            ->where('groups.0.items.0.snippet', 'Today we covered data mining with decision trees and pruning.'));
    }
}
