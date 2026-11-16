<?php

namespace Tests\Feature;

use App\Models\Note;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\AccountTransfer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Phase 13: everything out as one .zip, and back into a new, empty account with the same data. */
class AccountTransferTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = [
        'areas', 'goals', 'projects', 'milestones', 'goal_project', 'tasks', 'task_steps', 'task_dependencies', 'task_files', 'events',
        'notes', 'note_files', 'note_task', 'habits', 'habit_entries', 'goal_habit', 'note_links', 'people', 'person_contacts', 'person_memories',
        'person_task', 'planner_blocks', 'focus_sessions',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['velocity.fixed_now' => '2026-11-11 10:00', 'velocity.study_log' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00', 'Asia/Kuala_Lumpur'));
        Storage::fake('local');
    }

    /** A full account: goals, a project in two goals, tasks with a parent, steps, a dependency and a file, a note with a picture and links, a habit, a person, the planner and focus. */
    private function account(): User
    {
        $u = User::factory()->create(['timezone' => 'Asia/Kuala_Lumpur', 'fyp_mode' => false]);
        $u->forceFill(['auto_archive_days' => 14, 'focus_settings' => ['minutes' => 40], 'wallpaper' => 'own', 'wallpaper_path' => "wallpapers/{$u->id}/w.png"])->save();
        Storage::disk('local')->put("wallpapers/{$u->id}/w.png", 'WALLPAPER');
        $area = $u->areas()->create(['name' => 'University', 'position' => 1]);
        $first = $u->goals()->create(['title' => 'Graduate with a First', 'color' => 1, 'position' => 1]);
        $job = $u->goals()->create(['title' => 'Land an internship', 'color' => 2, 'position' => 2]);
        $fyp = $u->projects()->create(['name' => 'FYP', 'area_id' => $area->id, 'goal_id' => $first->id]);
        $fyp->extraGoals()->attach($job->id);
        $fyp->milestones()->create(['title' => 'Demo', 'position' => 1]);
        $task = fn (string $title, array $more = []) => $u->tasks()->create($more + ['title' => $title, 'importance' => 3, 'urgency' => 3, 'est_hours' => 1, 'project_id' => $fyp->id]);
        $ch = $task('Chapter 4');
        $sec = $task('Section 4.1', ['parent_id' => $ch->id]);
        $survey = $task('Run the survey');
        $sec->dependencies()->attach($survey->id);
        $ch->steps()->create(['title' => 'Outline', 'position' => 1]);
        Storage::disk('local')->put("task-files/{$u->id}/a.pdf", 'PDF');
        $ch->files()->create(['user_id' => $u->id, 'name' => 'draft.pdf', 'path' => "task-files/{$u->id}/a.pdf", 'mime' => 'application/pdf', 'size' => 3]);
        $note = $u->notes()->create(['title' => 'Survey method', 'body' => '<p>x</p>', 'project_id' => $fyp->id]);
        Storage::disk('local')->put("note-files/{$u->id}/p.png", 'PNG');
        $pic = $note->files()->create(['user_id' => $u->id, 'name' => 'chart.png', 'path' => "note-files/{$u->id}/p.png", 'mime' => 'image/png', 'size' => 3, 'inline' => true]);
        $note->update(['body' => "<p>See</p><img src=\"/notes/files/{$pic->id}\"><p><a href=\"/tasks?task={$ch->id}\">Chapter</a></p>"]);
        $note->tasks()->attach($ch->id);
        $habit = $u->habits()->create(['name' => 'Write 30 min', 'kind' => 'do', 'target' => 1, 'project_id' => $fyp->id]);
        $habit->entries()->create(['day' => '2026-11-10', 'count' => 1]);
        $habit->goals()->attach($first->id);
        $note->links()->create(['linkable_type' => 'habit', 'linkable_id' => $habit->id]);
        $lim = $u->people()->create(['name' => 'Dr. Lim Wei']);
        $lim->contacts()->create(['kind' => 'email', 'value' => 'lim@example.edu', 'position' => 1]);
        $lim->memories()->create(['text' => 'Likes short drafts', 'on' => '2026-11-01']);
        $lim->tasks()->attach($ch->id);
        $u->events()->create(['calendar' => 'class', 'title' => 'Data Mining', 'starts_at' => '2026-11-12 01:00:00', 'ends_at' => '2026-11-12 03:00:00']);
        $u->plannerBlocks()->create(['task_id' => $ch->id, 'kind' => 'task', 'starts_at' => '2026-11-12 04:00:00', 'ends_at' => '2026-11-12 05:00:00']);
        DB::table('focus_sessions')->insert(['user_id' => $u->id, 'task_id' => $ch->id, 'mode' => 'timer', 'started_at' => '2026-11-10 02:00:00', 'ended_at' => '2026-11-10 02:50:00', 'seconds' => 3000, 'created_at' => now(), 'updated_at' => now()]);

        return $u;
    }

    private function rows(User $u, string $table): int
    {
        $owned = fn (string $t) => DB::table($t)->where('user_id', $u->id)->pluck('id');

        return match ($table) {
            'milestones' => DB::table($table)->whereIn('project_id', $owned('projects'))->count(),
            'goal_project' => DB::table($table)->whereIn('project_id', $owned('projects'))->count(),
            'task_steps', 'task_dependencies' => DB::table($table)->whereIn('task_id', $owned('tasks'))->count(),
            'note_task', 'note_links' => DB::table($table)->whereIn('note_id', $owned('notes'))->count(),
            'habit_entries', 'goal_habit' => DB::table($table)->whereIn('habit_id', $owned('habits'))->count(),
            'person_contacts', 'person_memories', 'person_task' => DB::table($table)->whereIn('person_id', $owned('people'))->count(),
            default => DB::table($table)->where('user_id', $u->id)->count(),
        };
    }

    public function test_an_export_comes_back_the_same_in_a_new_account(): void
    {
        $a = $this->account();
        $before = collect(self::TABLES)->mapWithKeys(fn ($t) => [$t => $this->rows($a, $t)])->all();
        $a->createToken('Mark', ['*']);
        $a->forceFill(['github_token' => 'secret-token', 'github_login' => 'aina'])->save();

        $res = $this->actingAs($a)->get(route('data.export'))->assertDownload('velocity-2026-11-11.zip');
        $zip = $res->baseResponse->getFile()->getPathname();
        $json = (new \ZipArchive)->open($zip) === true ? null : null;
        $archive = new \ZipArchive;
        $archive->open($zip);
        $data = $archive->getFromName('data.json');
        $archive->close();
        // Secrets stay behind.
        $this->assertStringNotContainsString('secret-token', $data);
        $this->assertStringNotContainsString('personal_access_tokens', $data);

        // Into a new account (registered on the new server, say).
        $b = User::factory()->create(['timezone' => 'UTC', 'fyp_mode' => false]);
        $this->actingAs($b)->get(route('data.edit'))->assertInertia(fn ($p) => $p->where('empty', true));
        $this->post(route('data.import'), ['file' => new UploadedFile($zip, 'velocity.zip', 'application/zip', null, true)])
            ->assertSessionHasNoErrors()->assertRedirect(route('home'))->assertSessionHas('toast', 'Brought in 3 tasks, 1 project, 1 note, 1 habit, 1 person');

        foreach (self::TABLES as $t) {
            $this->assertSame($before[$t], $this->rows($b, $t), "{$t} came back with a different count");
        }
        // The links between things are the same, with new ids.
        $fyp = Project::where('user_id', $b->id)->sole();
        $this->assertSame(['Graduate with a First', 'Land an internship'], collect($fyp->goalIds())->map(fn ($id) => DB::table('goals')->find($id)->title)->all());
        $this->assertSame('University', $fyp->area->name);
        $ch = Task::where('user_id', $b->id)->where('title', 'Chapter 4')->sole();
        $sec = Task::where('user_id', $b->id)->where('title', 'Section 4.1')->sole();
        $this->assertSame($ch->id, $sec->parent_id);
        $this->assertSame(['Run the survey'], $sec->dependencies->pluck('title')->all());
        $this->assertSame(['Dr. Lim Wei'], $ch->people->pluck('name')->all());
        $this->assertSame('PDF', Storage::disk('local')->get($ch->files()->sole()->path));
        $this->assertStringStartsWith("task-files/{$b->id}/", $ch->files()->sole()->path);
        // The picture and the link inside the note point at the new ones.
        $note = Note::where('user_id', $b->id)->sole();
        $pic = $note->files()->sole();
        $this->assertSame("<p>See</p><img src=\"/notes/files/{$pic->id}\"><p><a href=\"/tasks?task={$ch->id}\">Chapter</a></p>", $note->body);
        $this->assertSame('PNG', Storage::disk('local')->get($pic->path));
        $this->assertSame([$ch->id], $note->tasks->pluck('id')->all());
        $this->assertSame(DB::table('habits')->where('user_id', $b->id)->value('id'), $note->links()->sole()->linkable_id);
        // Settings came along; the account's own name, email and keys didn't change.
        $b->refresh();
        $this->assertSame(['Asia/Kuala_Lumpur', 14, ['minutes' => 40], 'own'], [$b->timezone, $b->auto_archive_days, $b->focus_settings, $b->wallpaper]);
        $this->assertSame('WALLPAPER', Storage::disk('local')->get($b->wallpaper_path));
        $this->assertNull($b->github_login);
        $this->assertSame(0, $b->tokens()->count());
        // The first account is as it was.
        foreach (self::TABLES as $t) {
            $this->assertSame($before[$t], $this->rows($a, $t));
        }

        // Only into an empty account: nothing doubles.
        $this->post(route('data.import'), ['file' => new UploadedFile($zip, 'velocity.zip', 'application/zip', null, true)])->assertSessionHasErrors('file');
        $this->assertSame(3, Task::where('user_id', $b->id)->count());
    }

    public function test_a_file_that_is_not_an_export_is_turned_away(): void
    {
        $u = User::factory()->create(['fyp_mode' => false]);
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('data.json', json_encode(['format' => 'something-else', 'tables' => []]));
        $zip->close();
        $this->actingAs($u)->post(route('data.import'), ['file' => new UploadedFile($path, 'other.zip', 'application/zip', null, true)])
            ->assertSessionHasErrors(['file' => 'That isn’t a Velocity export (.zip).']);

        // A link inside a note to something that didn't come along is left as it was.
        $this->assertSame('<a href="/tasks?task=99">x</a>', AccountTransfer::relink('<a href="/tasks?task=99">x</a>', ['tasks' => [1 => 5]]));
        $this->assertSame('<img src="/notes/files/7"><a href="/notes?in=p1&amp;note=5">n</a>', AccountTransfer::relink('<img src="/notes/files/3"><a href="/notes?in=p1&amp;note=2">n</a>', ['note_files' => [3 => 7], 'notes' => [2 => 5]]));
    }
}
