<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Talks to GitHub's API with the user's own fine-grained token. Only fixed
 * api.github.com paths built from a checked "owner/name" and a number are
 * called: never an address someone typed.
 */
class GitHub
{
    public const REPO = '/^[A-Za-z0-9_.-]{1,100}\/[A-Za-z0-9_.-]{1,100}$/';

    /** Links older than this are read again when a task is opened. */
    public const STALE_MINUTES = 10;

    /** "https://github.com/owner/name/issues/42" (or /pull/42) → ['repo' => 'owner/name', 'number' => 42]. */
    public static function parse(string $url): ?array
    {
        if (! preg_match('~^https://(?:www\.)?github\.com/([A-Za-z0-9_.-]{1,100})/([A-Za-z0-9_.-]{1,100})/(?:issues|pull)/(\d{1,9})(?:[/?#].*)?$~', trim($url), $m)) {
            return null;
        }

        return ['repo' => "{$m[1]}/{$m[2]}", 'number' => (int) $m[3]];
    }

    /** Who the token belongs to (checks it works). */
    public function login(string $token): string
    {
        return (string) $this->send($token, fn (PendingRequest $c) => $c->get('/user'))['login'];
    }

    /** Repositories the token can see, the most recently worked on first. */
    public function repos(User $user): array
    {
        $rows = $this->send($user, fn (PendingRequest $c) => $c->get('/user/repos', ['per_page' => 100, 'sort' => 'pushed']));

        return array_map(fn ($r) => ['name' => (string) $r['full_name'], 'private' => (bool) ($r['private'] ?? false)], $rows);
    }

    /** A repository's name as GitHub writes it (and that the token can see it). */
    public function repo(User $user, string $repo): string
    {
        return (string) $this->send($user, fn (PendingRequest $c) => $c->get("/repos/{$repo}"))['full_name'];
    }

    /** Open issues (not pull requests), the most recently updated first. */
    public function openIssues(User $user, string $repo, int $limit = 10): array
    {
        $rows = $this->send($user, fn (PendingRequest $c) => $c->get("/repos/{$repo}/issues", ['state' => 'open', 'sort' => 'updated', 'per_page' => 30]));
        $issues = array_filter($rows, fn ($i) => ! isset($i['pull_request']));

        return array_slice(array_values(array_map(fn ($i) => $this->present($i, $repo), $issues)), 0, $limit);
    }

    /** One issue or pull request as it is now. */
    public function item(User $user, string $repo, int $number): array
    {
        return $this->present($this->send($user, fn (PendingRequest $c) => $c->get("/repos/{$repo}/issues/{$number}")), $repo);
    }

    /** Issues and pull requests of a repository whose words match. */
    public function search(User $user, string $repo, string $q): array
    {
        $words = trim(preg_replace('/\b(?:repo|org|user):\S*/i', '', $q)); // only this repository
        $rows = $this->send($user, fn (PendingRequest $c) => $c->get('/search/issues', ['q' => "repo:{$repo} {$words}", 'sort' => 'updated', 'per_page' => 10]));

        return array_map(fn ($i) => $this->present($i, $repo), $rows['items'] ?? []);
    }

    public function createIssue(User $user, string $repo, string $title, ?string $body): array
    {
        $issue = $this->send($user, fn (PendingRequest $c) => $c->post("/repos/{$repo}/issues", array_filter(['title' => $title, 'body' => $body])));

        return $this->present($issue, $repo);
    }

    /** What Velocity keeps of an issue or pull request; the link to it is built here, not taken from GitHub's answer. */
    private function present(array $i, string $repo): array
    {
        if (preg_match('~/repos/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)$~', (string) ($i['repository_url'] ?? ''), $m)) {
            $repo = $m[1];
        }
        $pull = isset($i['pull_request']);
        $state = match (true) {
            $pull && ! empty($i['pull_request']['merged_at']) => 'merged',
            ($i['state'] ?? 'open') === 'closed' => 'closed',
            $pull && ! empty($i['draft']) => 'draft',
            default => 'open',
        };
        $number = (int) $i['number'];

        return [
            'repo' => $repo,
            'number' => $number,
            'kind' => $pull ? 'pull' : 'issue',
            'title' => Str::limit((string) ($i['title'] ?? ''), 297),
            'state' => $state,
            'url' => "https://github.com/{$repo}/".($pull ? 'pull' : 'issues')."/{$number}",
        ];
    }

    private function client(string $token): PendingRequest
    {
        return Http::baseUrl('https://api.github.com')
            ->withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'Velocity'])
            ->connectTimeout(5)->timeout(12)->withoutRedirecting();
    }

    /** @param  callable(PendingRequest): Response  $call */
    private function send(User|string $who, callable $call): array
    {
        $token = is_string($who) ? $who : $who->github_token;
        if (! $token) {
            throw new GitHubException('Connect GitHub in Settings → Integrations first.', 409);
        }
        try {
            $r = $call($this->client($token));
        } catch (ConnectionException) {
            throw new GitHubException('GitHub can’t be reached right now.', 503);
        }
        if ($r->successful()) {
            return (array) $r->json();
        }
        $limit = $r->header('X-RateLimit-Remaining') === '0';
        throw match (true) {
            $r->status() === 401 => new GitHubException('GitHub didn’t accept the token. Connect again in Settings → Integrations.', 409),
            $limit => new GitHubException('GitHub’s limit for this hour is used up. Try again later.', 503),
            in_array($r->status(), [403, 404], true) => new GitHubException('GitHub can’t find that, or the token can’t see it.', 422),
            $r->status() === 410 => new GitHubException('That has been deleted on GitHub.', 422),
            $r->status() === 422 => new GitHubException('GitHub didn’t accept that.', 422),
            default => new GitHubException('GitHub isn’t answering properly right now.', 503),
        };
    }
}
