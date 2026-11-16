<?php

namespace App\Http\Controllers\Api;

use App\Actions\GitHubActions;
use App\Models\GithubLink;
use App\Models\Project;
use App\Models\Task;
use App\Services\GitHub;
use App\Services\RankingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A task's linked GitHub issues and pull requests. */
class GitHubApiController extends ApiController
{
    public function __construct(RankingService $ranking, private readonly GitHubActions $actions)
    {
        parent::__construct($ranking);
    }

    /** The links, read again from GitHub when a few minutes old (meta.error if GitHub couldn't be reached). */
    public function index(Request $request, Task $task): JsonResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        $refresh = $user->github_login ? $this->actions->refresh($task, $user) : ['error' => null];

        return $this->ok($task->githubLinks()->get()->map(fn (GithubLink $l) => $l->present())->values(), meta: ['error' => $refresh['error']]);
    }

    /** Issues and pull requests in the task's project repository matching ?q=…. */
    public function search(Request $request, Task $task, GitHub $github): JsonResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        $d = $request->validate(['q' => ['required', 'string', 'max:100'], 'repo' => ['nullable', 'string', 'regex:'.GitHub::REPO]]);
        $repo = $d['repo'] ?? $task->project?->github_repo;
        abort_unless($repo, 422, 'Choose a repository for the project first.');

        return $this->ok($github->search($user, $repo, $d['q']));
    }

    /** A new issue for the task, linked to it: {"repo": "owner/name", "title": "…", "body"?: "…"}. */
    public function createIssue(Request $request, Task $task, GitHub $github): JsonResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        $d = $request->validate([
            'repo' => ['nullable', 'string', 'regex:'.GitHub::REPO],
            'title' => ['required', 'string', 'max:256'],
            'body' => ['nullable', 'string', 'max:20000'],
        ]);
        $repo = $d['repo'] ?? $task->project?->github_repo;
        abort_unless($repo, 422, 'Give a repository (owner/name), or set one for the project.');
        // The same title in the same repository again: the issue already made comes back (200), nothing is posted.
        $made = $this->actions->createIssue($task, $user, $repo, $d['title'], $d['body'] ?? null);

        return $this->ok($made['link']->present(), $made['created'] ? 201 : 200);
    }

    /** Read the task's links again from GitHub now. */
    public function refresh(Request $request, Task $task): JsonResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        $result = $this->actions->refresh($task, $user, true);

        return $this->ok($task->githubLinks()->get()->map(fn (GithubLink $l) => $l->present())->values(), meta: ['error' => $result['error'] ?? null]);
    }

    /** The project's repository ({"repo": null} when none). */
    public function projectRepo(Request $request, Project $project): JsonResponse
    {
        $this->own($project, $request->user());

        return $this->ok(['repo' => $project->github_repo]);
    }

    /** {"repo": "owner/name"} (checked with GitHub), or null to clear it. */
    public function setProjectRepo(Request $request, Project $project, GitHub $github): JsonResponse
    {
        $user = $request->user();
        $this->own($project, $user);
        $repo = $request->validate(['repo' => ['present', 'nullable', 'string', 'regex:'.GitHub::REPO]])['repo'];
        $project->update(['github_repo' => $repo ? $github->repo($user, $repo) : null]);

        return $this->ok(['repo' => $project->github_repo]);
    }

    /** {"url": "https://github.com/owner/name/issues/42"} */
    public function store(Request $request, Task $task): JsonResponse
    {
        $user = $request->user();
        $this->own($task, $user);
        $url = $request->validate(['url' => ['required', 'string', 'max:300']])['url'];

        return $this->ok($this->actions->link($task, $user, $url)->present(), 201);
    }

    public function destroy(Request $request, Task $task, GithubLink $link): JsonResponse
    {
        $this->own($task, $request->user());
        abort_unless((int) $link->task_id === (int) $task->id, 404);
        $link->delete();

        return $this->ok(['id' => $link->id, 'unlinked' => true]);
    }
}
