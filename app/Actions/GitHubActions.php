<?php

namespace App\Actions;

use App\Models\GithubLink;
use App\Models\Task;
use App\Models\User;
use App\Services\GitHub;
use App\Services\GitHubException;
use Illuminate\Validation\ValidationException;

/** Linking GitHub issues and pull requests to tasks, shared by the screens and the API. */
class GitHubActions
{
    /** A task can hold this many links. */
    public const MAX_LINKS = 20;

    public function __construct(private readonly GitHub $github) {}

    /** Links an issue or pull request by its address. Linking it again just reads it again. */
    public function link(Task $task, User $user, string $url): GithubLink
    {
        $ref = GitHub::parse($url);
        if (! $ref) {
            throw ValidationException::withMessages(['url' => 'Paste the address of a GitHub issue or pull request.']);
        }
        $item = $this->github->item($user, $ref['repo'], $ref['number']);

        return $this->save($task, $user, $item);
    }

    /** Keeps what GitHub showed, linked to the task. */
    public function save(Task $task, User $user, array $item): GithubLink
    {
        $existing = $task->githubLinks()->where('repo', $item['repo'])->where('number', $item['number'])->first();
        if (! $existing && $task->githubLinks()->count() >= self::MAX_LINKS) {
            throw ValidationException::withMessages(['url' => 'A task can have '.self::MAX_LINKS.' GitHub links at most.']);
        }
        $values = ['user_id' => $user->id, 'kind' => $item['kind'], 'title' => $item['title'], 'state' => $item['state'], 'url' => $item['url'], 'fetched_at' => now()];
        if ($existing) {
            $existing->update($values);

            return $existing;
        }

        return $task->githubLinks()->create($values + ['repo' => $item['repo'], 'number' => $item['number']]);
    }

    /**
     * Reads a task's links again when they are older than a few minutes (all of
     * them with $force). If GitHub can't be reached, what is known stays.
     *
     * @return array{changed: bool, error: ?string}
     */
    public function refresh(Task $task, User $user, bool $force = false): array
    {
        $changed = false;
        $stale = $task->githubLinks()->get()
            ->filter(fn (GithubLink $l) => $force || ! $l->fetched_at || $l->fetched_at->lt(now()->subMinutes(GitHub::STALE_MINUTES)))
            ->take(self::MAX_LINKS);
        foreach ($stale as $link) {
            try {
                $item = $this->github->item($user, $link->repo, $link->number);
            } catch (GitHubException $e) {
                return ['changed' => $changed, 'error' => $e->getMessage()];
            }
            $link->fill(['title' => $item['title'], 'state' => $item['state'], 'kind' => $item['kind'], 'url' => $item['url']]);
            $changed = $changed || $link->isDirty();
            $link->fetched_at = now();
            $link->save();
        }

        return ['changed' => $changed, 'error' => null];
    }
}
