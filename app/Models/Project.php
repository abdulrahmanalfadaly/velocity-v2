<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $fillable = ['area_id', 'goal_id', 'name', 'description', 'due_date', 'position', 'archived_at', 'github_repo'];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'archived_at' => 'datetime'];
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
