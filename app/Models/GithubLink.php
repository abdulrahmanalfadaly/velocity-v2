<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A GitHub issue or pull request linked to a task, as GitHub last showed it. */
class GithubLink extends Model
{
    protected $fillable = ['user_id', 'task_id', 'repo', 'number', 'kind', 'title', 'state', 'url', 'fetched_at'];

    protected function casts(): array
    {
        return ['number' => 'integer', 'fetched_at' => 'datetime'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** Closed issues and merged (or closed) pull requests: the work on GitHub is over. */
    public function finished(): bool
    {
        return in_array($this->state, ['closed', 'merged'], true);
    }

    public function present(): array
    {
        return [
            'id' => $this->id,
            'repo' => $this->repo,
            'number' => $this->number,
            'kind' => $this->kind,
            'title' => $this->title,
            'state' => $this->state,
            'url' => $this->url,
            'fetchedAt' => $this->fetched_at?->toIso8601ZuluString(),
        ];
    }
}
