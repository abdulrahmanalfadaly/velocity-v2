<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Files on tasks: attach, open, remove and undo, and nobody else can reach them. */
class TaskFilesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['velocity.fixed_now' => '2026-11-11 10:00', 'velocity.study_log' => false]);
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->task = $this->user->tasks()->create(['title' => 'Essay', 'importance' => 3, 'urgency' => 3, 'est_hours' => 1]);
    }

    /** A real 1×1 PNG, so no image library (GD) is needed to make one. */
    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
    }

    public function test_files_are_attached_stored_privately_and_shown_in_the_panel(): void
    {
        $this->post(route('files.store', $this->task), [
            'files' => [$this->png('scan.png'), UploadedFile::fake()->create('notes.docx', 120, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')],
        ])->assertSessionHas('toast', 'Attached 2 files');

        $files = $this->task->files()->get();
        $this->assertCount(2, $files);
        foreach ($files as $f) {
            Storage::disk('local')->assertExists($f->path);
            $this->assertStringStartsWith("task-files/{$this->user->id}/", $f->path);
        }
        $this->get(route('tasks.index', ['task' => $this->task->id]))->assertInertia(fn ($page) => $page
            ->where('selected.files.0.name', 'notes.docx')
            ->where('selected.files.1.name', 'scan.png')
            ->where('selected.files.1.image', true));
    }

    public function test_pictures_open_in_the_browser_and_anything_else_downloads(): void
    {
        $this->post(route('files.store', $this->task), ['files' => [$this->png('scan.png'), UploadedFile::fake()->createWithContent('page.html', '<script>alert(1)</script>')]]);
        $png = TaskFile::firstWhere('name', 'scan.png');
        $html = TaskFile::firstWhere('name', 'page.html');

        $r = $this->get(route('files.show', $png));
        $r->assertOk();
        $this->assertStringStartsWith('inline', $r->headers->get('Content-Disposition'));
        $this->assertSame('image/png', $r->headers->get('Content-Type'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));

        // A web page is never shown as a page: it downloads.
        $r = $this->get(route('files.show', $html));
        $this->assertStringStartsWith('attachment', $r->headers->get('Content-Disposition'));
        $this->assertSame('application/octet-stream', $r->headers->get('Content-Type'));

        $this->assertStringStartsWith('attachment', $this->get(route('files.show', $png).'?download=1')->headers->get('Content-Disposition'));
    }

    public function test_too_big_or_missing_files_are_refused(): void
    {
        $this->post(route('files.store', $this->task), ['files' => [UploadedFile::fake()->create('huge.zip', 21000)]])->assertSessionHasErrors('files.0');
        $this->post(route('files.store', $this->task), ['files' => []])->assertSessionHasErrors('files');
        $this->assertSame(0, TaskFile::count());
    }

    public function test_removing_a_file_and_attaching_can_both_be_undone(): void
    {
        $this->post(route('files.store', $this->task), ['files' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]]);
        $undo = session('undo');
        $file = TaskFile::firstOrFail();

        $this->delete(route('files.destroy', $file))->assertSessionHas('undo', fn ($u) => $u['action'] === 'restore-file');
        $this->assertSoftDeleted($file);
        $this->get(route('files.show', $file))->assertNotFound();
        $this->post(route('files.restore', $file->id));
        $this->assertNull($file->fresh()->deleted_at);

        // Undo of the attach removes the file for good, from the disk too.
        $this->post(route('files.remove-many', $this->task), $undo['data']);
        $this->assertNull(TaskFile::withTrashed()->find($file->id));
        Storage::disk('local')->assertMissing($file->path);
    }

    public function test_nobody_else_can_open_attach_or_remove(): void
    {
        $this->post(route('files.store', $this->task), ['files' => [$this->png('mine.png')]]);
        $file = TaskFile::firstOrFail();
        $other = User::factory()->create();
        $theirTask = $other->tasks()->create(['title' => 'Theirs', 'importance' => 3, 'urgency' => 3, 'est_hours' => 1]);

        $this->actingAs($other);
        $this->get(route('files.show', $file))->assertNotFound();
        $this->delete(route('files.destroy', $file))->assertNotFound();
        $this->post(route('files.store', $this->task), ['files' => [$this->png('x.png')]])->assertNotFound();
        $this->post(route('files.remove-many', $theirTask), ['ids' => [$file->id]]);
        $this->assertNotNull(TaskFile::find($file->id));

        $this->post('/logout');
        $this->get(route('files.show', $file))->assertRedirect('/login');
    }

    public function test_deleting_the_account_removes_its_files_from_the_disk(): void
    {
        $this->post(route('files.store', $this->task), ['files' => [$this->png('mine.png')]]);
        $path = TaskFile::firstOrFail()->path;
        $this->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect('/');
        Storage::disk('local')->assertMissing($path);
        $this->assertSame(0, TaskFile::count());
    }
}
