<?php

namespace Tests\Feature;

use App\Models\GithubLink;
use App\Models\Task;
use App\Models\User;
use App\Services\GitHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GitHub, with GitHub faked (no real calls): the token is checked and kept
 * encrypted, links come from an address and keep their state, old links are read
 * again (and GitHub being down changes nothing), a project's issues become tasks,
 * a new issue carries only what was chosen, and the API needs the GitHub area.
 */
class GitHubTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['velocity.fixed_now' => '2026-11-12 01:00', 'velocity.study_log' => false]);
        $this->user = User::factory()->create(['timezone' => 'Asia/Kuala_Lumpur', 'fyp_mode' => false]);
        $this->task = $this->user->tasks()->create(['title' => 'Fix the login page', 'importance' => 4, 'urgency' => 3, 'est_hours' => 2]);
    }

    private function connect(): void
    {
        $this->user->forceFill(['github_token' => 'github_pat_test', 'github_login' => 'abdul'])->save();
    }

    private static function issue(int $number, string $title, string $state = 'open', array $extra = []): array
    {
        return ['number' => $number, 'title' => $title, 'state' => $state, 'repository_url' => 'https://api.github.com/repos/velo/app', 'html_url' => 'https://evil.example/x'] + $extra;
    }

    public function test_the_token_is_checked_kept_encrypted_and_never_sent_to_the_browser(): void
    {
        Http::fake(['api.github.com/user' => Http::sequence()->push(['message' => 'Bad credentials'], 401)->push(['login' => 'abdul'])]);
        $this->actingAs($this->user);

        $this->post(route('github.connect'), ['token' => 'github_pat_wrong'])->assertSessionHasErrors(['token' => 'GitHub didn’t accept the token. Connect again in Settings → Integrations.']);
        $this->assertNull($this->user->fresh()->github_login);

        $this->post(route('github.connect'), ['token' => 'github_pat_right'])->assertRedirect(route('integrations.edit'))->assertSessionHas('toast', 'Connected to GitHub as @abdul');
        $this->assertSame('github_pat_right', $this->user->fresh()->github_token);
        $this->assertNotSame('github_pat_right', DB::table('users')->where('id', $this->user->id)->value('github_token'));
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer github_pat_right'));

        $page = $this->get(route('integrations.edit'))->assertInertia(fn ($p) => $p->where('github.login', 'abdul'));
        $this->assertStringNotContainsString('github_pat_right', $page->getContent());
        $this->get(route('tasks.index', ['task' => $this->task->id]))->assertInertia(fn ($p) => $p->where('selected.github.connected', true));
        $this->assertStringNotContainsString('github_pat_right', $this->get(route('tasks.index'))->getContent());

        $this->delete(route('github.disconnect'))->assertRedirect();
        $this->assertNull($this->user->fresh()->github_token);
    }

    public function test_links_come_from_an_address_and_keep_their_state(): void
    {
        $this->connect();
        Http::fake([
            'api.github.com/repos/velo/app/issues/42' => Http::response(self::issue(42, 'Login redirect loops')),
            'api.github.com/repos/velo/app/issues/7' => Http::response(self::issue(7, 'Add the API', 'closed', ['pull_request' => ['merged_at' => '2026-11-10T09:00:00Z']])),
        ]);
        $this->actingAs($this->user);

        $this->assertSame(['repo' => 'velo/app', 'number' => 42], GitHub::parse('https://github.com/velo/app/issues/42#issuecomment-1'));
        $this->assertNull(GitHub::parse('https://github.com.evil.example/velo/app/issues/42'));
        $this->assertNull(GitHub::parse('http://github.com/velo/app/issues/42'));
        $this->assertNull(GitHub::parse('https://github.com/velo/app/blob/main/README.md'));

        $this->post(route('tasks.github.link', $this->task), ['url' => 'https://github.com/velo/app/issues/42'])->assertSessionHas('toast', 'Linked #42 Login redirect loops');
        $this->post(route('tasks.github.link', $this->task), ['url' => 'https://github.com/velo/app/issues/42']); // again: no second link
        $this->post(route('tasks.github.link', $this->task), ['url' => 'https://github.com/velo/app/pull/7']);
        $this->post(route('tasks.github.link', $this->task), ['url' => 'https://gitlab.com/velo/app/issues/1'])->assertSessionHasErrors('url');

        $links = $this->task->githubLinks()->get();
        $this->assertSame([[42, 'issue', 'open'], [7, 'pull', 'merged']], $links->map(fn ($l) => [$l->number, $l->kind, $l->state])->all());
        // The address is built by Velocity, never taken from GitHub's answer.
        $this->assertSame('https://github.com/velo/app/issues/42', $links[0]->url);

        $this->delete(route('github-links.destroy', $links[0]))->assertSessionHas('undo', ['action' => 'link-github', 'id' => $this->task->id, 'data' => ['url' => 'https://github.com/velo/app/issues/42']]);
        $this->assertSame(1, $this->task->githubLinks()->count());

        $other = User::factory()->create(['fyp_mode' => false]);
        $this->actingAs($other)->post(route('tasks.github.link', $this->task), ['url' => 'https://github.com/velo/app/issues/42'])->assertNotFound();
        $this->delete(route('github-links.destroy', $links[1]))->assertNotFound();
    }

    public function test_old_links_are_read_again_and_github_being_down_changes_nothing(): void
    {
        $this->connect();
        $make = fn (int $n, $at) => $this->task->githubLinks()->create(['user_id' => $this->user->id, 'repo' => 'velo/app', 'number' => $n, 'kind' => 'issue', 'title' => "Issue {$n}", 'state' => 'open', 'url' => "https://github.com/velo/app/issues/{$n}", 'fetched_at' => $at]);
        $make(42, now()->subHour());
        $make(43, now()->subHour());
        $make(44, now()); // just read: left alone
        Http::fake([
            'api.github.com/repos/velo/app/issues/42' => Http::response(self::issue(42, 'Issue 42', 'closed')),
            'api.github.com/repos/velo/app/issues/43' => Http::response(['message' => 'Server Error'], 502),
        ]);
        $this->actingAs($this->user);

        $this->get(route('tasks.index', ['task' => $this->task->id]))->assertInertia(fn ($p) => $p->where('selected.github.stale', true));
        $this->postJson(route('tasks.github.refresh', $this->task))->assertOk()
            ->assertJson(['changed' => true, 'error' => 'GitHub isn’t answering properly right now.']);
        $this->assertSame(['closed', 'open', 'open'], $this->task->githubLinks()->pluck('state')->all());
        Http::assertSentCount(2);
    }

    public function test_a_projects_open_issues_become_linked_tasks_in_the_project(): void
    {
        $this->connect();
        $project = $this->user->projects()->create(['name' => 'Velocity']);
        Http::fake([
            'api.github.com/repos/Velo/App' => Http::response(['full_name' => 'velo/app']),
            'api.github.com/repos/velo/app/issues?*' => Http::response([self::issue(42, 'Dark mode toggle'), self::issue(7, 'A pull request', 'open', ['pull_request' => ['merged_at' => null]])]),
            'api.github.com/repos/velo/app/issues/42' => Http::response(self::issue(42, 'Dark mode toggle')),
        ]);
        $this->actingAs($this->user);

        $this->put(route('projects.github', $project), ['repo' => 'Velo/App'])->assertSessionHas('toast', 'Linked velo/app');
        $this->assertSame('velo/app', $project->fresh()->github_repo);
        $this->put(route('projects.github', $project), ['repo' => 'https://github.com/velo/app'])->assertSessionHasErrors('repo');

        $issues = $this->getJson(route('projects.github.issues', $project))->assertOk()->json('issues');
        $this->assertSame([[42, null]], array_map(fn ($i) => [$i['number'], $i['taskId']], $issues));

        $this->post(route('projects.github.task', $project), ['number' => 42])->assertSessionHas('undo.action', 'delete-task');
        $task = Task::where('title', 'Dark mode toggle')->firstOrFail();
        $this->assertSame($project->id, $task->project_id);
        $this->assertSame(42, $task->githubLinks()->first()->number);
        $this->assertSame($task->id, $this->getJson(route('projects.github.issues', $project))->json('issues.0.taskId'));
        $this->get(route('projects.show', $project))->assertInertia(fn ($p) => $p->where('github.repo', 'velo/app'));
    }

    public function test_a_new_issue_carries_only_what_was_chosen_and_is_linked(): void
    {
        $this->connect();
        Http::fake(['api.github.com/repos/velo/app/issues' => Http::response(self::issue(43, 'Fix the login page'), 201)]);
        $this->actingAs($this->user);

        $this->post(route('tasks.github.issue', $this->task), ['repo' => 'velo/app', 'title' => 'Fix the login page', 'body' => null])
            ->assertSessionHas('toast', 'Created #43 on GitHub');
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r['title'] === 'Fix the login page' && ! isset($r['body']));
        $this->assertSame(43, $this->task->githubLinks()->first()->number);

        $this->post(route('tasks.github.issue', $this->task), ['repo' => 'velo', 'title' => 'x'])->assertSessionHasErrors('repo');
        Http::assertSentCount(1);
    }

    public function test_the_api_needs_the_github_area(): void
    {
        $this->connect();
        Http::fake(['api.github.com/repos/velo/app/issues/42' => Http::response(self::issue(42, 'Login redirect loops'))]);
        $read = $this->user->createToken('Mark', ['tasks:read'])->plainTextToken;
        $this->getJson("/api/v1/tasks/{$this->task->id}/github", ['Authorization' => "Bearer {$read}"])->assertStatus(403);

        $this->app['auth']->forgetGuards();
        $key = $this->user->createToken('Mark', ['github:read', 'github:write'])->plainTextToken;
        $this->postJson("/api/v1/tasks/{$this->task->id}/github", ['url' => 'https://github.com/velo/app/issues/42'], ['Authorization' => "Bearer {$key}"])
            ->assertCreated()->assertJsonPath('data.state', 'open');
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/tasks/{$this->task->id}/github", ['Authorization' => "Bearer {$key}"])->assertOk()->assertJsonPath('data.0.number', 42);
        $this->assertSame(1, GithubLink::count());
    }
}
