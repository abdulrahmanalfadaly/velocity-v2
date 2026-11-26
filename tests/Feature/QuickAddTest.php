<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Quick add: the words it reads for tasks and events, and adding an event or a person from it. */
class QuickAddTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Wednesday 11 Nov 2026, 10:00 in Kuala Lumpur (UTC+8).
        config(['velocity.fixed_now' => '2026-11-11 10:00', 'velocity.study_log' => false]);
        $this->user = User::factory()->create(['timezone' => 'Asia/Kuala_Lumpur']);
        $this->actingAs($this->user);
    }

    private function task(string $text): array
    {
        return $this->postJson(route('tasks.preview'), ['text' => $text])->assertOk()->json('parsed');
    }

    private function event(string $text): array
    {
        return $this->postJson(route('events.preview'), ['text' => $text])->assertOk()->json();
    }

    public function test_tasks_read_spaced_times_noon_and_hours_with_minutes(): void
    {
        $this->assertSame(['Meet Sara', '2026-11-11T07:00:00Z'], array_values(array_intersect_key($this->task('Meet Sara 3 pm'), ['title' => 1, 'due' => 1])));
        $this->assertSame('2026-11-11T04:00:00Z', $this->task('Lunch noon')['due']);
        $this->assertSame(1.5, $this->task('Write 1h 30m')['hours']);
        $this->assertSame(1.5, $this->task('Write 1h30m')['hours']);
        $this->assertSame('Write', $this->task('Write 1h 30m')['title']);

        // Not times: a chapter range, "at 3" with no am / pm.
        $this->assertSame(['Read chapters 2-3', null], [$this->task('Read chapters 2-3')['title'], $this->task('Read chapters 2-3')['due']]);
        $this->assertSame(['Meet at 3', null], [$this->task('Meet at 3')['title'], $this->task('Meet at 3')['due']]);
    }

    public function test_a_start_date_a_reminder_and_importance_come_from_the_words_and_a_chip_can_be_taken_off(): void
    {
        // Wed 11 Nov, 10:00. "from mon" = Mon 16 Nov; "remind 9am" = 9:00 on the due day (Fri 13 Nov).
        $p = $this->task('Book flights fri from mon remind 9am !!');
        $this->assertSame(['Book flights', '2026-11-15T16:00:00Z', ['kind' => 'at', 'at' => '2026-11-13T01:00:00Z'], 5],
            [$p['title'], $p['start'], $p['remind'], $p['importance']]);
        $this->assertSame(['kind' => 'before', 'before' => '1h'], $this->task('Call Dr Lim tomorrow 3pm remind me 1h before')['remind']);
        // No due date and 9:00 has passed: tomorrow at nine. "Remind Ali" isn't a reminder.
        $this->assertSame('2026-11-12T01:00:00Z', $this->task('Pay rent remind 9am')['remind']['at']);
        $this->assertSame(['Remind Ali about the form', null], [$this->task('Remind Ali about the form')['title'], $this->task('Remind Ali about the form')['remind']]);

        // Taking the "fri" chip off keeps the word in the title.
        $kept = $this->postJson(route('tasks.preview'), ['text' => 'Plan fri party fri', 'plain' => ['fri']])->json('parsed');
        $this->assertSame(['Plan fri party fri', null], [$kept['title'], $kept['due']]);

        // Adding: importance from "!" unless the dots were used; it starts later, with its reminder.
        $this->post(route('tasks.store'), ['text' => 'Book flights from mon remind fri 8am !'])->assertSessionHas('toast', 'Added “Book flights” · starts Mon 16 Nov');
        $task = $this->user->tasks()->where('title', 'Book flights')->sole();
        $this->assertSame([4, '2026-11-15 16:00:00', '2026-11-13 00:00:00'],
            [$task->importance, $task->available_from->format('Y-m-d H:i:s'), $task->reminders()->sole()->at->format('Y-m-d H:i:s')]);
        $this->post(route('tasks.store'), ['text' => 'Essay !!', 'importance' => 2]);
        $this->assertSame(2, $this->user->tasks()->where('title', 'Essay')->sole()->importance);
    }

    public function test_quick_add_can_save_a_note_with_its_text_and_stay(): void
    {
        $this->from('/tasks')->post(route('notes.store'), ['title' => 'Lab ideas', 'text' => 'Try the new sensor
Ask about the budget', 'stay' => true])
            ->assertRedirect('/tasks')->assertSessionHas('toast', 'Added note “Lab ideas”');
        $note = $this->user->notes()->sole();
        $this->assertSame('Try the new sensor
Ask about the budget', $note->body_text);
        $this->post(route('notes.store'), ['stay' => true])->assertSessionHasErrors('title');
    }

    public function test_an_event_line_gives_its_day_times_and_area(): void
    {
        $uni = $this->user->areas()->create(['name' => 'University', 'position' => 0]);
        $work = $this->user->areas()->create(['name' => 'Part-time Work', 'position' => 1]);
        $personal = $this->user->areas()->create(['name' => 'Personal', 'position' => 2]);
        $this->assertSame(
            ['title' => 'Dentist', 'date' => '2026-11-12', 'start' => '15:00', 'end' => '16:00', 'allDay' => false, 'areaId' => $personal->id, 'weekly' => false, 'until' => null],
            array_diff_key($this->event('Dentist tomorrow 3-4pm #personal'), ['picked' => 1]),
        );
        // "#uni": the start of an area's name; "#pw": its capitals; an unknown one stays in the title.
        $quiz = $this->event('Quiz fri 9-10am #uni');
        $this->assertSame(['Quiz', '2026-11-13', '09:00', '10:00', $uni->id], [$quiz['title'], $quiz['date'], $quiz['start'], $quiz['end'], $quiz['areaId']]);
        $this->assertSame($work->id, $this->event('Shift sat 9am-1pm #pw')['areaId']);
        $this->assertSame(['Party #club', null], array_values(array_intersect_key($this->event('Party sat 8pm #club'), ['title' => 1, 'areaId' => 1])));

        // A side without am / pm takes the other's; "11-1pm" is 11 am to 1 pm, later today.
        $lecture = $this->event('Lecture 11-1pm');
        $this->assertSame(['2026-11-11', '11:00', '13:00'], [$lecture['date'], $lecture['start'], $lecture['end']]);
        $this->assertSame(['22:00', '02:00', $work->id], array_values(array_intersect_key($this->event('Night shift 10pm-2am #part'), ['start' => 1, 'end' => 1, 'areaId' => 1])));
        $this->assertSame(['Meeting', '15:00', '16:30'], array_values(array_intersect_key($this->event('Meeting 3pm to 4:30pm'), ['title' => 1, 'start' => 1, 'end' => 1])));
        $this->assertSame(['Talk', '14:30', '16:00'], array_values(array_intersect_key($this->event('Talk from 2:30 - 4pm'), ['title' => 1, 'start' => 1, 'end' => 1])));

        // A start alone lasts the hours given, or an hour.
        $this->assertSame(['15:00', '15:30'], array_values(array_intersect_key($this->event('Call 3pm 30m'), ['start' => 1, 'end' => 1])));
        $gym = $this->event('Gym every mon 6pm');
        $this->assertSame(['Gym', '2026-11-16', '18:00', '19:00', true], [$gym['title'], $gym['date'], $gym['start'], $gym['end'], $gym['weekly']]);

        // No time: all day. A time that has passed today: tomorrow.
        $holiday = $this->event('Holiday 20 dec');
        $this->assertSame([true, '2026-12-20', null], [$holiday['allDay'], $holiday['date'], $holiday['start']]);
        $this->assertSame('2026-11-12', $this->event('Standup 9am')['date']);

        // Events only repeat weekly; other repeats stay in the title.
        $this->assertSame(['Report every 2 weeks', false], array_values(array_intersect_key($this->event('Report every 2 weeks'), ['title' => 1, 'weekly' => 1])));
    }

    public function test_quick_add_adds_an_event_and_a_person_where_you_are(): void
    {
        $this->from('/tasks')->post(route('events.store'), [
            'title' => 'Dentist', 'calendar' => 'personal', 'date' => '2026-11-12', 'all_day' => false,
            'start_time' => '15:00', 'end_time' => '16:00', 'repeat_weekly' => false, 'repeat_until' => null, 'location' => null, 'notes' => null,
        ])->assertRedirect('/tasks')->assertSessionHas('toast', 'Added “Dentist”');
        $this->assertSame('2026-11-12 07:00:00', $this->user->events()->sole()->starts_at->utc()->format('Y-m-d H:i:s'));

        $this->from('/tasks')->post(route('people.store'), ['name' => 'Sara Ahmad', 'relationship' => 'Classmate', 'contacts' => [], 'group_ids' => [], 'stay' => true])
            ->assertRedirect('/tasks')->assertSessionHas('toast', 'Added “Sara Ahmad”');
        $this->assertSame('Classmate', $this->user->people()->sole()->relationship);

        // Without "stay" (the People page's own dialog) it still opens the person.
        $this->post(route('people.store'), ['name' => 'Adam', 'contacts' => [], 'group_ids' => []])->assertRedirect();
        $this->assertStringContainsString('person=', $this->post(route('people.store'), ['name' => 'Ali', 'contacts' => [], 'group_ids' => []])->headers->get('Location'));
    }

    public function test_hours_and_minutes_written_as_two_words(): void
    {
        $this->assertSame(['Read chapter 4', 2], array_values(array_intersect_key($this->task('Read chapter 4 2 hours'), ['title' => 1, 'hours' => 1])));
        $this->assertSame(0.5, $this->task('Call the bank 30 min')['hours']);
        $this->assertSame(1.5, $this->task('Write 1 hour 30 min')['hours']);
        $this->assertSame(1.5, $this->task('Write 1.5 hrs')['hours']);
        $this->assertSame(1.75, $this->task('Write 1h 45 minutes')['hours']);
        // Kept as words when the chip was taken off; a number alone stays a word.
        $this->assertSame('Read 2 hours', $this->postJson(route('tasks.preview'), ['text' => 'Read 2 hours', 'plain' => ['2', 'hours']])->json('parsed.title'));
        $this->assertSame('Chapter 2 notes', $this->task('Chapter 2 notes')['title']);
    }
}
