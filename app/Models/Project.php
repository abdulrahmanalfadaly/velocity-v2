<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    public const STATUSES = ['planned', 'active', 'on_hold', 'completed'];

    /** Not being worked on now: its tasks leave Up next (shown under Not ready yet). */
    public const HELD = ['planned', 'on_hold'];

    protected $fillable = ['area_id', 'goal_id', 'name', 'description', 'status', 'completed_at', 'due_date', 'position', 'archived_at', 'github_repo'];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'archived_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** Files on the project itself (its tasks have their own). */
    public function files(): HasMany
    {
        return $this->hasMany(ProjectFile::class)->latest('id');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class)->orderBy('position');
    }

    /** People who are part of this project: a supervisor, teammates. */
    public function people(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'person_project')->orderBy('name');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class)->latest('updated_at');
    }

    public function habits(): HasMany
    {
        return $this->hasMany(Habit::class)->whereNull('archived_at');
    }
}
