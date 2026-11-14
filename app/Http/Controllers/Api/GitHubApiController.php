<?php

namespace App\Http\Controllers\Api;

use App\Actions\GitHubActions;
use App\Models\GithubLink;
use App\Models\Task;
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
