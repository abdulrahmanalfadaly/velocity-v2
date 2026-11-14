<?php

namespace App\Http\Controllers;

use App\Actions\GitHubActions;
use App\Actions\TaskActions;
use App\Models\GithubLink;
use App\Models\Project;
use App\Models\Task;
use App\Services\GitHub;
use App\Services\GitHubException;
use App\Services\RankingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * GitHub on the screens: a project's repository and its open issues ("Add as
 * task"), and a task's linked issues and pull requests (link, unlink, create an
 * issue, read again). Lists come as JSON, so a page never waits on GitHub.
 */
class GitHubController extends Controller
{
    public function __construct(private readonly GitHub $github, private readonly GitHubActions $actions) {}

    /** Repositories the token can see, for the project's picker. */
    public function repos(Request $request): JsonResponse
    {
        return response()->json(['repos' => $this->github->repos($request->user())]);
    }

    /** The project's repository, or none (null). */
    public function setRepo(Request $request, Project $project): RedirectResponse
    {
        $user = $request->user();
        $this->own($project, $user);
        $repo = $request->validate(['repo' => ['nullable', 'string', 'regex:'.GitHub::REPO]], ['repo.regex' => 'Write it as owner/name.'])['repo'] ?? null;
        $before = $project->github_repo;
        try {
            $project->update(['github_repo' => $repo ? $this->github->repo($user, $repo) : null]);
        } catch (GitHubException $e) {
            throw ValidationException::withMessages(['repo' => $e->getMessage()]);
        }

        return back()->with('toast', $project->github_repo ? "Linked {$project->github_repo}" : 'No repository')
            ->with('undo', $this->undo('put-project-github', $project->id, ['repo' => $before]));
    }

    /** The project's open issues, each with the task it already became (if any). */
    public function issues(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();
        $this->own($project, $user);
        abort_unless($project->github_repo, 404);
        $issues = $this->github->openIssues($user, $project->github_repo);
        $tasks = GithubLink::where('user_id', $user->id)->where('repo', $project->github_repo)->whereIn('number', array_column($issues, 'number'))
            ->whereHas('task')->pluck('task_id', 'number');

        return response()->json(['repo' => $project->github_repo, 'issues' => array_map(fn ($i) => $i + ['taskId' => $tasks[$i['number']] ?? null], $issues)]);
    }

    /** "Add as task": an issue becomes a task in this project, linked to it. */
    public function addTask(Request $request, Project $project, TaskActions $tasks, RankingService $ranking): RedirectResponse
    {
        $user = $request->user();
        $this->own($project, $user);
        abort_unless($project->github_repo, 404);
        $number = $request->validate(['number' => ['required', 'integer', 'min:1']])['number'];
        try {
            $issue = $this->github->item($user, $project->github_repo, (int) $number);
        } catch (GitHubException $e) {
            return back()->with('toast', $e->getMessage());
        }
        $task = $tasks->create($user, ['title' => Str::limit($issue['title'], 252), 'importance' => 3, 'urgency' => 3, 'est_hours' => 1, 'project_id' => $project->id]);
        $this->actions->save($task, $user, $issue);
        $rank = $ranking->rank($user)->rankOf($task->id);

        return back()->with('toast', "Added “{$task->title}”".($rank ? " · #{$rank}" : ''))->with('undo', $this->undo('delete-task', $task->id));
    }

    /** Issues and pull requests of the task's project's repository (or ?repo=) matching the words. */
    public function search(Request $request, Task $task): JsonResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        $d = $request->validate(['q' => ['required', 'string', 'max:100'], 'repo' => ['nullable', 'string', 'regex:'.GitHub::REPO]]);
        $repo = $d['repo'] ?? $task->project?->github_repo;
        abort_unless($repo, 422, 'Choose a repository for the project first.');

        return response()->json(['items' => $this->github->search($user, $repo, $d['q'])]);
    }

    public function link(Request $request, Task $task): RedirectResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        $url = $request->validate(['url' => ['required', 'string', 'max:300']])['url'];
        try {
            $link = $this->actions->link($task, $user, $url);
        } catch (GitHubException $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }

        return back()->with('toast', "Linked #{$link->number} {$link->title}")->with('undo', $this->undo('unlink-github', $link->id));
    }

    public function unlink(Request $request, GithubLink $link): RedirectResponse
    {
        $this->own($link, $request->user());
        $link->delete();

        return back()->with('toast', "Unlinked #{$link->number}")->with('undo', $this->undo('link-github', $link->task_id, ['url' => $link->url]));
    }

    /** Reads the task's links again if they are a few minutes old (JSON: whether anything changed). */
    public function refresh(Request $request, Task $task): JsonResponse
    {
        $user = $request->user();
        $this->own($task, $user);

        return response()->json($this->actions->refresh($task, $user, $request->boolean('force')));
    }

    /** A new issue on GitHub for this task, linked to it. The task's notes go along only when asked. */
    public function createIssue(Request $request, Task $task): RedirectResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        $d = $request->validate([
            'repo' => ['required', 'string', 'regex:'.GitHub::REPO],
            'title' => ['required', 'string', 'max:256'],
            'body' => ['nullable', 'string', 'max:20000'],
        ], ['repo.regex' => 'Write it as owner/name.']);
        try {
            $issue = $this->github->createIssue($user, $d['repo'], $d['title'], $d['body'] ?? null);
        } catch (GitHubException $e) {
            throw ValidationException::withMessages(['title' => $e->getMessage()]);
        }
        $this->actions->save($task, $user, $issue);

        return back()->with('toast', "Created #{$issue['number']} on GitHub");
    }
}
