<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** The API for everything added in Phase 9: cancel, archive, reminders, bulk, next actions, focus, projects, areas, planner blocks, habits, people, note links, review. */
class ApiMoreTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Asia/Kuala_Lumpur';

    private User $user;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        // Wednesday 11 Nov 2026, 10:00 in Kuala Lumpur.
        config(['velocity.fixed_now' => '2026-11-11 10:00', 'velocity.study_log' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00', self::TZ));
        $this->user = User::factory()->create(['timezone' => self::TZ]);
        $this->key = $this->user->createToken('Mark', ['*'])->plainTextToken;
    }

    private function api(string $method, string $uri, array $data = [], ?string $key = null): TestResponse
    {
        $this->app['auth']->forgetGuards(); // each request reads its own key

        return $this->json($method, "/api/v1/{$uri}", $data, ['Authorization' => 'Bearer '.($key ?? $this->key)]);
    }

    private function task(string $title, array $attrs = []): Task
    {
        return $this->user->tasks()->create($attrs + ['title' => $title, 'importance' => 3, 'urgency' => 3, 'est_hours' => 1]);
    }

    public function test_tasks_cancel_archive_bulk_next_and_templates(): void
    {
        $gym = $this->task('Gym', ['due_at' => CarbonImmutable::parse('2026-11-16 18:00', self::TZ)->utc(), 'repeat_unit' => 'week']);
        $this->api('POST', "tasks/{$gym->id}/cancel", ['skip' => true])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancelReason', 'Skipped')->assertJsonPath('data.next.title', 'Gym');
        $this->api('POST', "tasks/{$gym->id}/cancel")->assertStatus(422)->assertJsonPath('error.code', 'INVALID');
        $this->api('POST', "tasks/{$gym->id}/archive")->assertOk()->assertJsonPath('data.archivedAt', fn ($v) => $v !== null);
        $this->api('POST', "tasks/{$gym->id}/unarchive")->assertOk()->assertJsonPath('data.archivedAt', null);

        [$a, $b] = [$this->task('A'), $this->task('B')];
        $this->api('POST', 'tasks/bulk', ['ids' => [$a->id, $b->id], 'action' => 'due', 'due_at' => '2026-11-20'])->assertOk()->assertJsonPath('data.ids', [$a->id, $b->id]);
        $this->assertSame('2026-11-20 23:59', $a->fresh()->due_at->timezone(self::TZ)->format('Y-m-d H:i'));
        $this->assertSame(1, $a->events()->where('type', 'updated')->count()); // the history has it

        $fyp = $this->user->projects()->create(['name' => 'FYP']);
        $this->task('Chapter 4', ['project_id' => $fyp->id, 'importance' => 5]);
        $this->api('GET', 'tasks/next')->assertOk()->assertJsonPath('data.rows.0.task.title', 'Chapter 4')->assertJsonPath('data.rows.0.task.rank', 1);

        $template = $this->user->templates()->create(['name' => 'Lab report', 'title' => 'Lab report', 'importance' => 3, 'urgency' => 3, 'est_hours' => 2, 'steps' => ['Data', 'Write-up']]);
        $this->api('GET', 'templates')->assertOk()->assertJsonPath('data.0.steps', ['Data', 'Write-up']);
        $this->api('POST', "templates/{$template->id}/use", ['due_at' => '2026-11-19'])->assertCreated()
            ->assertJsonPath('data.title', 'Lab report')->assertJsonPath('data.steps.1.title', 'Write-up');
    }

    public function test_reminders_through_the_api(): void
    {
        $task = $this->task('Essay', ['due_at' => CarbonImmutable::parse('2026-11-13 17:00', self::TZ)->utc()]);
        $this->api('POST', "tasks/{$task->id}/reminders", ['kind' => 'before', 'before' => '1d'])->assertCreated()->assertJsonPath('data.daysBefore', 1);
        $at = $this->api('POST', "tasks/{$task->id}/reminders", ['kind' => 'at', 'at' => '2026-11-11 15:00'])->assertCreated()->json('data');
        $this->assertSame('2026-11-11T07:00:00Z', $at['at']);
        $this->api('GET', "tasks/{$task->id}/reminders")->assertOk()->assertJsonCount(2, 'data');
        $this->api('POST', "reminders/{$at['id']}/snooze", ['until' => '2026-11-12 09:00'])->assertOk()->assertJsonPath('data.at', '2026-11-12T01:00:00Z');
        $this->api('DELETE', "reminders/{$at['id']}")->assertOk();
        $this->assertSame(1, $task->reminders()->count());
    }

    public function test_focus_pause_pomodoro_history_and_note(): void
    {
        $task = $this->task('Chapter 4');
        $this->api('POST', 'focus', ['task_id' => $task->id, 'mode' => 'pomodoro', 'intention' => 'Draft 4.2'])->assertCreated();
        $this->api('POST', 'focus/pause')->assertOk()->assertJsonPath('data.pausedAt', fn ($v) => $v !== null);
        $this->api('POST', 'focus/resume')->assertOk()->assertJsonPath('data.phase', 'focus');

        config(['velocity.fixed_now' => '2026-11-11 10:27']);
        $this->api('GET', 'focus')->assertOk()->assertJsonPath('data.phase', 'break');
        $this->api('POST', 'focus/next')->assertCreated()->assertJsonPath('data.round', 2);
        $this->api('POST', 'focus/end')->assertOk();

        $history = $this->api('GET', 'focus/history')->assertOk()->json('data');
        $this->assertSame([1, 'Draft 4.2'], [$history['count'], $history['sessions'][0]['intention']]);
        $this->api('PATCH', "focus/{$history['sessions'][0]['id']}", ['note' => 'Wrote the intro'])->assertOk()->assertJsonPath('data.note', 'Wrote the intro');
    }

    public function test_projects_areas_and_goals(): void
    {
        $this->api('POST', 'areas', ['name' => 'Uni', 'icon' => 'cap'])->assertCreated();
        $area = $this->api('GET', 'areas')->assertOk()->json('data.0');
        $this->api('PATCH', "areas/{$area['id']}", ['name' => 'University'])->assertOk()->assertJsonPath('data.name', 'University');

        $fyp = $this->user->projects()->create(['name' => 'FYP', 'area_id' => $area['id']]);
        $open = $this->task('Chapter 5', ['project_id' => $fyp->id]);
        $this->api('POST', "projects/{$fyp->id}/status", ['status' => 'on_hold'])->assertOk()->assertJsonPath('data.status', 'on_hold');
        $this->api('GET', "projects/{$fyp->id}/tasks")->assertOk()->assertJsonCount(0, 'data.ranked')->assertJsonPath('data.notReady.0.kind', 'held');
        $this->api('POST', "projects/{$fyp->id}/complete", ['open' => 'cancel'])->assertOk()->assertJsonPath('data.cancelledTasks', [$open->id]);
        $this->api('POST', "projects/{$fyp->id}/reopen")->assertOk()->assertJsonPath('data.status', 'active');

        $goal = $this->api('POST', 'goals', ['title' => 'Graduate', 'color' => 2])->assertCreated()->json('data');
        $this->api('PATCH', "goals/{$goal['id']}", ['progress_mode' => 'manual', 'progress' => 40, 'target_date' => '2027-06-30'])->assertOk()
            ->assertJsonPath('data.progress', 40)->assertJsonPath('data.targetDate', '2027-06-30');
    }

    public function test_planner_blocks_and_free_time(): void
    {
        $task = $this->task('Essay');
        $block = $this->api('POST', 'planner/blocks', ['task_id' => $task->id, 'date' => '2026-11-12', 'start' => '09:00', 'minutes' => 60])->assertCreated()->json('data');
        $this->assertSame([], $block['overlaps']);
        $this->api('PATCH', "planner/blocks/{$block['id']}", ['minutes' => 90])->assertOk();
        $this->assertSame(90, $this->user->plannerBlocks()->sole()->minutes());
        $free = $this->api('GET', 'planner/free', ['date' => '2026-11-12'])->assertOk()->json('data.0');
        $this->assertSame(['2026-11-12', '2026-11-12T00:00:00Z'], [$free['date'], $free['slots'][0]['start']]);
        $this->api('DELETE', "planner/blocks/{$block['id']}")->assertOk();
    }

    public function test_habits_people_notes_and_review(): void
    {
        $habit = $this->user->habits()->create(['name' => 'Run', 'target' => 1]);
        $this->api('PATCH', "habits/{$habit->id}", ['kind' => 'minutes', 'target' => 20])->assertOk()->assertJsonPath('data.kind', 'minutes')->assertJsonPath('data.unit', 'min');
        $this->api('PUT', "habits/{$habit->id}/day", ['date' => '2026-11-10', 'count' => 25, 'note' => 'Easy pace'])->assertOk();
        $this->api('GET', "habits/{$habit->id}")->assertOk()->assertJsonPath('data.notes.0.note', 'Easy pace');
        $this->api('POST', "habits/{$habit->id}/pause")->assertOk()->assertJsonPath('data.group', 'paused');
        $this->api('POST', "habits/{$habit->id}/resume")->assertOk()->assertJsonPath('data.pausedSince', null);

        $person = $this->user->people()->create(['name' => 'Sara']);
        $this->api('PATCH', "people/{$person->id}", ['relationship' => 'Classmate'])->assertOk();
        $this->assertSame('Classmate', $person->fresh()->relationship);
        $this->api('POST', "people/{$person->id}/archive")->assertOk()->assertJsonPath('data.archived', true);

        $note = $this->user->notes()->create(['title' => 'Plan', 'body' => '', 'body_text' => '']);
        $link = $this->api('POST', "notes/{$note->id}/links", ['type' => 'habit', 'id' => $habit->id])->assertCreated()->json('data');
        $this->api('GET', "notes/{$note->id}")->assertOk()->assertJsonPath('data.links.0.type', 'habit');
        $this->api('DELETE', "notes/{$note->id}/links/{$link['id']}")->assertOk();

        $this->api('GET', 'review', ['period' => 'day'])->assertOk()->assertJsonPath('data.kind', 'day')->assertJsonStructure(['data' => ['activity' => ['created', 'postponed'], 'events']]);
    }

    public function test_every_answer_has_a_request_id_and_scopes_still_hold(): void
    {
        $this->api('GET', 'me')->assertHeader('X-Request-Id');
        $this->app['auth']->forgetGuards();
        $this->json('GET', '/api/v1/me', [], ['Authorization' => "Bearer {$this->key}", 'X-Request-Id' => 'mark-run-0042'])->assertHeader('X-Request-Id', 'mark-run-0042');

        $readOnly = $this->user->createToken('Reader', ['tasks:read'])->plainTextToken;
        $task = $this->task('Essay');
        $this->api('POST', "tasks/{$task->id}/cancel", [], $readOnly)->assertForbidden()->assertHeader('X-Request-Id');
        $this->api('GET', 'tasks/next', [], $readOnly)->assertOk();
        $this->api('GET', 'areas', [], $readOnly)->assertForbidden();
    }

    public function test_phase_10_subtasks_blocks_finished_views_bulk_fields_and_deleting_a_project(): void
    {
        $report = $this->task('Report');
        $this->api('POST', "tasks/{$report->id}/subtasks", ['title' => 'Intro'])->assertCreated()->assertJsonPath('data.parent.id', $report->id);
        $slides = $this->task('Slides');
        $this->api('POST', "tasks/{$report->id}/blocks", ['add' => $slides->id])->assertOk()->assertJsonPath('data.0.title', 'Slides');
        $this->api('GET', "tasks/{$report->id}")->assertOk()
            ->assertJsonPath('data.subtasks.0.title', 'Intro')->assertJsonPath('data.blocks.0.title', 'Slides');
        $this->api('GET', "tasks/{$slides->id}")->assertOk()->assertJsonPath('data.activity.0.text', 'Starts after changed');
        // Waiting on "Report": 409 unless "anyway".
        $this->api('POST', "tasks/{$slides->id}/complete")->assertStatus(409);
        $this->api('POST', "tasks/{$report->id}/complete", ['children' => 'complete'])->assertOk();
        $this->assertSame(Task::DONE, Task::where('title', 'Intro')->sole()->status);

        // Finished views, and the selection bar's new actions.
        $this->api('POST', 'tasks/bulk', ['ids' => [$report->id], 'action' => 'archive'])->assertOk()->assertJsonPath('data.ids', [$report->id]);
        $this->api('GET', 'tasks?view=archived')->assertOk()->assertJsonPath('data.0.title', 'Report');
        $this->api('POST', 'tasks/bulk', ['ids' => [$slides->id], 'action' => 'importance', 'importance' => 5])->assertOk();
        $this->assertSame(5, $slides->fresh()->importance);

        // Deleting a project: its tasks to the Inbox; meta.undo brings it back.
        $fyp = $this->user->projects()->create(['name' => 'FYP']);
        $slides->update(['project_id' => $fyp->id]);
        $undo = $this->api('DELETE', "projects/{$fyp->id}")->assertOk()->json('meta.undo');
        $this->assertNull($slides->fresh()->project_id);
        $this->api('POST', "projects/{$fyp->id}/restore", $undo)->assertOk()->assertJsonPath('data.name', 'FYP');
        $this->assertSame($fyp->id, $slides->fresh()->project_id);
    }
}
