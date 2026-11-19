<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskFile;
use App\Models\TaskLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** A task's log: entries the user writes, with files on them; edit, delete with Undo; nobody else can reach them. */
class TaskLogTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['velocity.fixed_now' => '2026-11-20 10:00', 'velocity.study_log' => false]);
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->task = $this->user->tasks()->create(['title' => 'Chapter 2', 'importance' => 3, 'urgency' => 3, 'est_hours' => 2]);
    }

    public function test_entries_are_written_with_or_without_files_and_shown_newest_first(): void
    {
        $this->post(route('logs.store', $this->task), ['body' => '  Emailed Dr. Lim the draft  '])->assertSessionHasNoErrors();
        $this->travel(5)->minutes();
        $this->post(route('logs.store', $this->task), [
            'body' => 'Feedback came back',
            'files' => [UploadedFile::fake()->create('comments.pdf', 40, 'application/pdf')],
        ])->assertSessionHasNoErrors();
        $this->travel(5)->minutes();
        // Only a file is fine; nothing at all is not.
        $this->post(route('logs.store', $this->task), ['files' => [UploadedFile::fake()->create('scan.pdf', 10, 'application/pdf')]])->assertSessionHasNoErrors();
        $this->post(route('logs.store', $this->task), ['body' => '   '])->assertSessionHasErrors('body');

        // A file of the task's own stays apart from the log's.
        $this->post(route('files.store', $this->task), ['files' => [UploadedFile::fake()->create('brief.docx', 10)]]);

        $this->get(route('tasks.index', ['task' => $this->task->id]))->assertInertia(fn ($page) => $page
            ->has('selected.logs', 3)
            ->where('selected.logs.0.body', null)
            ->where('selected.logs.0.files.0.name', 'scan.pdf')
            ->where('selected.logs.1.body', 'Feedback came back')
            ->where('selected.logs.1.files.0.name', 'comments.pdf')
            ->where('selected.logs.2.body', 'Emailed Dr. Lim the draft')
            ->has('selected.logs.2.files', 0)
            ->where('selected.logs.2.edited', false)
            ->has('selected.files', 1)
            ->where('selected.files.0.name', 'brief.docx'));
    }

    public function test_an_entry_is_edited_and_gets_more_files(): void
    {
        $this->post(route('logs.store', $this->task), ['body' => 'Ran the survey']);
        $log = TaskLog::first();

        $this->travel(10)->minutes();
        $this->patch(route('logs.update', $log), ['body' => 'Ran the survey (32 answers)'])->assertSessionHasNoErrors();
        $this->post(route('files.store', $this->task), ['files' => [UploadedFile::fake()->create('results.csv', 5)], 'log_id' => $log->id])
            ->assertSessionHas('toast', 'Attached “results.csv”');
        // Emptied with no files: it would say nothing, so it isn't saved.
        $other = $this->task->logs()->create(['user_id' => $this->user->id, 'body' => 'Note to self']);
        $this->patch(route('logs.update', $other), ['body' => ''])->assertSessionHasErrors('body');
        // With a file it can be left with no words.
        $this->patch(route('logs.update', $log), ['body' => ''])->assertSessionHasNoErrors();
        $this->patch(route('logs.update', $log), ['body' => 'Ran the survey (32 answers)']);

        $this->get(route('tasks.index', ['task' => $this->task->id]))->assertInertia(fn ($page) => $page
            ->where('selected.logs.1.body', 'Ran the survey (32 answers)')
            ->where('selected.logs.1.edited', true)
            ->where('selected.logs.1.files.0.name', 'results.csv')
            ->has('selected.files', 0));
    }

    public function test_deleting_an_entry_takes_its_files_and_undo_brings_both_back(): void
    {
        $this->post(route('logs.store', $this->task), ['body' => 'Printed the poster', 'files' => [UploadedFile::fake()->create('poster.pdf', 10)]]);
        $log = TaskLog::first();
        $file = TaskFile::first();

        $this->delete(route('logs.destroy', $log))->assertSessionHas('toast', 'Removed the log entry')
            ->assertSessionHas('undo', ['action' => 'restore-log', 'id' => $log->id, 'data' => ['files' => [$file->id]]]);
        $this->assertSoftDeleted($log);
        $this->assertSoftDeleted($file);
        $this->get(route('tasks.index', ['task' => $this->task->id]))->assertInertia(fn ($page) => $page->has('selected.logs', 0));

        $this->post(route('logs.restore', $log->id), ['files' => [$file->id]])->assertSessionHas('toast', 'The log entry is back');
        $this->assertNotSoftDeleted($log);
        $this->assertNotSoftDeleted($file);
    }

    public function test_nobody_else_can_reach_an_entry(): void
    {
        $this->post(route('logs.store', $this->task), ['body' => 'Mine']);
        $log = TaskLog::first();
        $theirTask = User::factory()->create()->tasks()->create(['title' => 'Theirs', 'importance' => 3, 'urgency' => 3, 'est_hours' => 1]);
        $theirLog = $theirTask->logs()->create(['user_id' => $theirTask->user_id, 'body' => 'Theirs']);

        // Files can't be put on another task's entry, even your own.
        $this->post(route('files.store', $this->task), ['files' => [UploadedFile::fake()->create('a.txt', 1)], 'log_id' => $theirLog->id])->assertNotFound();
        $mine = $this->user->tasks()->create(['title' => 'Other', 'importance' => 3, 'urgency' => 3, 'est_hours' => 1]);
        $this->post(route('files.store', $mine), ['files' => [UploadedFile::fake()->create('a.txt', 1)], 'log_id' => $log->id])->assertNotFound();

        $this->actingAs(User::factory()->create());
        $this->post(route('logs.store', $this->task), ['body' => 'Hi'])->assertNotFound();
        $this->patch(route('logs.update', $log), ['body' => 'Changed'])->assertNotFound();
        $this->delete(route('logs.destroy', $log))->assertNotFound();
        $this->post(route('logs.restore', $log->id))->assertNotFound();
        $this->assertSame('Mine', $log->fresh()->body);
    }
}
