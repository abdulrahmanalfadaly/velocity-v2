<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Someone in the user's life: how to reach them, their dates, keeping in touch, and what they are linked to. */
class Person extends Model
{
    use SoftDeletes;

    public const CONTACT_KINDS = ['email', 'phone', 'link', 'other'];

    public const CATCH_UP_KINDS = ['message', 'call', 'met', 'other'];

    protected $fillable = ['name', 'relationship', 'organisation', 'about', 'catch_up_days', 'archived_at'];

    protected function casts(): array
    {
        return ['catch_up_days' => 'integer', 'archived_at' => 'datetime'];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(PersonContact::class)->orderBy('position');
    }

    public function dates(): HasMany
    {
        return $this->hasMany(PersonDate::class);
    }

    public function catchUps(): HasMany
    {
        return $this->hasMany(CatchUp::class)->orderByDesc('on')->orderByDesc('id');
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(PersonGroup::class, 'person_group_members')->orderBy('name');
    }

    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'person_task');
    }

    public function notes(): BelongsToMany
    {
        return $this->belongsToMany(Note::class, 'person_note');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'person_project')->orderBy('name');
    }

    /** "Aina Rahman" → "AR" */
    public function initials(): string
    {
        $words = preg_split('/\s+/u', trim($this->name)) ?: [];
        $first = mb_substr($words[0] ?? '?', 0, 1);
        $last = count($words) > 1 ? mb_substr(end($words), 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    /**
     * Keeping in touch: when the next catch-up is due (the last one, or the day the
     * person was added, plus the interval). Null when no interval is set.
     */
    public function nextCatchUp(CarbonImmutable $today): ?CarbonImmutable
    {
        if (! $this->catch_up_days) {
            return null;
        }
        $last = $this->relationLoaded('catchUps') ? $this->catchUps->first()?->on : $this->catchUps()->value('on');
        $base = $last
            ? CarbonImmutable::parse($last instanceof \DateTimeInterface ? $last->format('Y-m-d') : $last, $today->timezone)
            : CarbonImmutable::instance($this->created_at ?? $today)->timezone($today->timezone)->startOfDay()->min($today);

        return $base->addDays($this->catch_up_days);
    }
}
