<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Follow-ups (24 Nov 2026): Home's own card, "Followed up" (counted, again on a day or none),
 * "Got a reply", a follow-up day set by itself on starting to wait, and waiting for someone in People.
 */
class FollowUpsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Wednesday 11 Nov 2026, 10:00 in Kuala Lumpur.
        config(['velocity.fixed_now' => '2026-11-11 10:00', 'velocity.study_log' => false]);
        $this->user = User::factory()->create(['timezone' => 'Asia/Kuala_Lumpur']);
        $this->actingAs($this->user);
    }

    private function task(array $attrs = []): Task
    {
        return $this->user->tasks()->create($attrs + ['title' => 'Essay', 'importance' => 3, 'urgency' => 3, 'est_hours' => 1]);
    }

    private function undo(?array $undo = null): void
    {
        $undo ??= session('undo');
        $this->assertSame('revert-task', $undo['action']);
        $this->post(route('tasks.revert', $undo['id']), $undo['data'])->assertSessionHas('toast', 'Change undone');
    }

    public function test_homes_card_has_today_and_late_waiting_with_no_day_and_the_coming_week(): void
    {
        $late = $this->task(['title' => 'Ethics form', 'status' => Task::WAITING, 'waiting_for' => 'Dr. Lim', 'waiting_since' => '2026-11-02 02:00', 'follow_up_on' => '2026-11-09']);
        $today = $this->task(['title' => 'Survey', 'follow_up_on' => '2026-11-11']);
        $noDay = $this->task(['title' => 'Logbook', 'status' => Task::WAITING, 'waiting_for' => 'HR']);
        $soon = $this->task(['title' => 'Quiz marks', 'status' => Task::WAITING, 'follow_up_on' => '2026-11-13']);
        $this->task(['title' => 'Grant', 'status' => Task::WAITING, 'follow_up_on' => '2026-12-01']);
        $this->task(['title' => 'Done one', 'status' => Task::DONE, 'follow_up_on' => '2026-11-10']);

        $this->get(route('home'))->assertInertia(fn (Assert $p) => $p
            ->where('followUps.due', fn ($rows) => collect($rows)->pluck('id')->all() === [$late->id, $today->id])
            ->where('followUps.due.0.late', 2)->where('followUps.due.0.waitingDays', 9)->where('followUps.due.0.initials', 'DL')
            ->where('followUps.due.0.why', 'Follow up · 2 days late · waiting for Dr. Lim, 9 days')
            ->where('followUps.due.1.late', 0)
            ->where('followUps.waiting', fn ($rows) => collect($rows)->pluck('id')->all() === [$noDay->id])
            ->where('followUps.waiting.0.initials', 'HR')
            ->where('followUps.soon', fn ($rows) => collect($rows)->pluck('id')->all() === [$soon->id])
            ->where('followUps.later', 1)->where('followUps.after', 3)
            // Not in Needs attention as well.
            ->missing('attention.followUps')
            ->where('attention.notReady', fn ($rows) => collect($rows)->where('kind', 'waiting')->isEmpty()));
    }

    public function test_followed_up_again_on_a_day_or_none_is_counted_and_undo_takes_it_back(): void
    {
        $task = $this->task(['title' => 'Ethics form', 'status' => Task::WAITING, 'follow_up_on' => '2026-11-10']);

        $this->post(route('follow-ups.done', $task), ['again' => '2026-11-13'])->assertSessionHas('toast', 'Followed up · again Fri 13 Nov');
        $this->assertSame('2026-11-13', $task->fresh()->follow_up_on->toDateString());
        $this->post(route('follow-ups.done', $task), ['again' => '2026-11-12'])->assertSessionHas('toast', 'Followed up · 2 times · again tomorrow');
        $undo = session('undo');
        $this->get(route('tasks.index', ['task' => $task->id]))->assertInertia(fn (Assert $p) => $p
            ->where('selected.nudges', 2)
            ->where('selected.activity.0.text', 'Followed up · again Thu 12 Nov'));

        // Undo: the day it had, and it isn't counted.
        $this->undo($undo);
        $this->assertSame('2026-11-13', $task->fresh()->follow_up_on->toDateString());
        $this->assertSame(1, $task->events()->where('type', 'followed_up')->count());

        // No next day: it keeps waiting, with no follow-up day (Home lists it under "no day set").
        $this->post(route('follow-ups.done', $task), ['again' => null])->assertSessionHas('toast', 'Followed up · 2 times · no next day');
        $this->assertSame([Task::WAITING, null], [$task->fresh()->status, $task->fresh()->follow_up_on]);
        $this->post(route('follow-ups.done', $task), ['again' => '2026-11-11'])->assertSessionHasErrors('again');
    }

    public function test_a_reply_stops_the_waiting_and_puts_it_in_the_ranking(): void
    {
        $task = $this->task(['title' => 'Ethics form', 'status' => Task::WAITING, 'waiting_since' => '2026-11-05 02:00', 'follow_up_on' => '2026-11-11']);

        $this->post(route('follow-ups.replied', $task))->assertSessionHas('toast', 'Got a reply · “Ethics form” is #1');
        $fresh = $task->fresh();
        $this->assertSame([Task::TODO, null, null], [$fresh->status, $fresh->waiting_since, $fresh->follow_up_on]);

        $this->undo();
        $fresh = $task->fresh();
        $this->assertSame([Task::WAITING, '2026-11-11', '2026-11-05'], [$fresh->status, $fresh->follow_up_on->toDateString(), $fresh->waiting_since->toDateString()]);
    }

    public function test_starting_to_wait_sets_a_follow_up_day_by_itself_unless_it_is_off(): void
    {
        $a = $this->task(['title' => 'A']);
        $this->patch(route('tasks.update', $a), ['status' => 'waiting']);
        $this->assertSame('2026-11-14', $a->fresh()->follow_up_on->toDateString());

        // A day it already had stays; one sent with the change wins.
        $b = $this->task(['title' => 'B', 'follow_up_on' => '2026-11-20']);
        $this->patch(route('tasks.update', $b), ['status' => 'waiting']);
        $this->assertSame('2026-11-20', $b->fresh()->follow_up_on->toDateString());
        $c = $this->task(['title' => 'C']);
        $this->patch(route('tasks.update', $c), ['status' => 'waiting', 'follow_up_on' => '2026-11-12']);
        $this->assertSame('2026-11-12', $c->fresh()->follow_up_on->toDateString());

        // Undo of the change takes the automatic day back too.
        $this->undo();
        $this->assertNull($c->fresh()->follow_up_on);

        $this->patch(route('task-settings.update'), ['follow_up_days' => 4])->assertSessionHasErrors('follow_up_days');
        $this->patch(route('task-settings.update'), ['follow_up_days' => 0])->assertSessionHasNoErrors();
        $this->get(route('task-settings.edit'))->assertInertia(fn (Assert $p) => $p->where('followUpDays', 0));
        $d = $this->task(['title' => 'D']);
        $this->patch(route('tasks.update', $d), ['status' => 'waiting']);
        $this->assertNull($d->fresh()->follow_up_on);
    }

    public function test_waiting_for_someone_in_people_links_them_and_their_page_shows_it(): void
    {
        $lim = $this->user->people()->create(['name' => 'Dr. Lim Wei', 'nickname' => 'Dr. Lim']);
        $task = $this->task(['title' => 'Ethics form']);
        $this->patch(route('tasks.update', $task), ['status' => 'waiting', 'waiting_for' => 'dr. lim']);
        $this->assertTrue($lim->tasks()->whereKey($task->id)->exists());

        $this->get(route('people.index', ['person' => $lim->id]))->assertInertia(fn (Assert $p) => $p
            ->where('people.0.waiting', 1)
            ->where('person.waitingOn.0.id', $task->id)
            ->where('person.waitingOn.0.followUpOn', '2026-11-14'));

        // A new follow-up from Home: waiting on them until the day picked.
        $this->post(route('follow-ups.store'), ['title' => 'Quiz marks', 'who' => 'Dr. Lim Wei', 'on' => '2026-11-12'])->assertSessionHas('toast', 'Follow up tomorrow');
        $made = Task::latest('id')->first();
        $this->assertSame(['Quiz marks', Task::WAITING, 'Dr. Lim Wei', '2026-11-12'], [$made->title, $made->status, $made->waiting_for, $made->follow_up_on->toDateString()]);
        $this->assertTrue($lim->tasks()->whereKey($made->id)->exists());
        $this->post(route('follow-ups.store'), ['title' => 'Old', 'on' => '2026-11-10'])->assertSessionHasErrors('on');
    }

    public function test_quick_add_reads_waiting_and_follow_up_words(): void
    {
        $lim = $this->user->people()->create(['name' => 'Dr. Lim Wei', 'nickname' => 'Dr. Lim']);

        $this->postJson(route('tasks.preview'), ['text' => 'Email Dr Lim about the form waiting Dr Lim follow up fri'])->assertOk()
            ->assertJsonPath('parsed.title', 'Email Dr Lim about the form')
            ->assertJsonPath('parsed.waiting', 'Dr. Lim Wei')->assertJsonPath('parsed.followUp', '2026-11-13')
            ->assertJsonPath('preview', null);
        $this->post(route('tasks.store'), ['text' => 'Email Dr Lim about the form waiting Dr Lim follow up fri'])->assertSessionHasNoErrors();
        $t = Task::latest('id')->first();
        $this->assertSame(['Email Dr Lim about the form', Task::WAITING, 'Dr. Lim Wei', '2026-11-13'], [$t->title, $t->status, $t->waiting_for, $t->follow_up_on->toDateString()]);
        $this->assertNotNull($t->waiting_since);
        $this->assertTrue($lim->tasks()->whereKey($t->id)->exists());

        // Someone not in People: the next word and the capitalised ones after it; the day by itself (3 days on).
        $this->post(route('tasks.store'), ['text' => 'Ask about the offer waiting for HR']);
        $t = Task::latest('id')->first();
        $this->assertSame(['Ask about the offer', 'HR', '2026-11-14'], [$t->title, $t->waiting_for, $t->follow_up_on->toDateString()]);
        $this->post(route('tasks.store'), ['text' => 'Loan papers wait on the bank f/u tomorrow']);
        $t = Task::latest('id')->first();
        $this->assertSame(['Loan papers', 'the bank', '2026-11-12'], [$t->title, $t->waiting_for, $t->follow_up_on->toDateString()]);

        // Only a follow-up day: still a ranked To do; "follow up" with no day stays in the title.
        $this->post(route('tasks.store'), ['text' => 'Plan the trip follow up tomorrow']);
        $t = Task::latest('id')->first();
        $this->assertSame(['Plan the trip', Task::TODO, '2026-11-12'], [$t->title, $t->status, $t->follow_up_on->toDateString()]);
        $this->post(route('tasks.store'), ['text' => 'Follow up with the club']);
        $this->assertSame(['Follow up with the club', null], [Task::latest('id')->first()->title, Task::latest('id')->first()->follow_up_on]);
    }

    public function test_a_reminder_comes_once_on_each_follow_up_day_at_the_time_picked(): void
    {
        $today = $this->task(['title' => 'Ethics form', 'status' => Task::WAITING, 'waiting_for' => 'Dr. Lim', 'follow_up_on' => '2026-11-11']);
        $late = $this->task(['title' => 'Survey', 'follow_up_on' => '2026-11-09']);
        $this->task(['title' => 'Later', 'follow_up_on' => '2026-11-12']);
        $due = fn () => $this->getJson(route('reminders.due'))->assertOk()->json('followUps');

        // 10:00, after 9:00: today's and the late one, once each.
        $this->assertSame([[$late->id, 2], [$today->id, 0]], array_map(fn ($f) => [$f['taskId'], $f['late']], $due()));
        $this->assertSame([], $due());
        // Moved to another day: it reminds again when that day comes.
        $today->update(['follow_up_on' => '2026-11-12']);
        config(['velocity.fixed_now' => '2026-11-12 08:00']);
        $this->assertSame([], $due());
        config(['velocity.fixed_now' => '2026-11-12 09:30']);
        $this->assertSame(['Ethics form', 'Later'], array_column($due(), 'title'));

        // Off: none.
        $this->patch(route('task-settings.update'), ['follow_up_remind_at' => null])->assertSessionHasNoErrors();
        $this->task(['title' => 'Quiet', 'follow_up_on' => '2026-11-12']);
        $this->assertSame([], $due());
        $this->patch(route('task-settings.update'), ['follow_up_remind_at' => '09:30'])->assertSessionHasErrors('follow_up_remind_at');
    }

    public function test_review_counts_the_follow_ups_and_the_replies(): void
    {
        // The events' own times are the clock's: the same Wednesday.
        $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00', 'Asia/Kuala_Lumpur'));
        $a = $this->task(['title' => 'Ethics form', 'status' => Task::WAITING, 'waiting_since' => '2026-11-07 02:00', 'follow_up_on' => '2026-11-10']);
        $b = $this->task(['title' => 'Grant letter', 'status' => Task::WAITING, 'waiting_for' => 'HR', 'waiting_since' => '2026-11-01 02:00', 'follow_up_on' => '2026-11-11']);
        $this->post(route('follow-ups.done', $b), ['again' => '2026-11-13']);
        $this->post(route('follow-ups.done', $a), ['again' => '2026-11-12']);
        $this->post(route('follow-ups.replied', $a));

        $report = null;
        $this->get(route('review.index'))->assertOk()->assertInertia(function ($page) use (&$report) {
            $report = $page->toArray()['props']['report'];
        });
        $f = $report['followUps'];
        $this->assertSame([2, 1, 1, 0], [$f['nudges'], $f['replies'], $f['waiting'], $f['late']]);
        $this->assertEquals(4.0, $f['avgWait']); // waiting since 7 Nov 10:00, the reply on 11 Nov 10:00
        $this->assertSame(['Grant letter', 'HR', 10], [$f['longest']['title'], $f['longest']['who'], $f['longest']['days']]);
    }
}
