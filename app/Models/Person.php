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

    /** "username": a handle on some service ("@sara", label: Instagram). */
    public const CONTACT_KINDS = ['email', 'phone', 'link', 'username', 'other'];

    public const CATCH_UP_KINDS = ['message', 'call', 'met', 'other'];

    /** Titles that go with the name after them ("Dr. Lim", "Puan Aminah"), never alone; the same list as firstName() in lib/velocity.ts. */
    private const TITLES = ['dr', 'prof', 'professor', 'mr', 'mrs', 'ms', 'miss', 'mx', 'sir', 'madam', 'puan', 'encik', 'cik', 'tuan', 'en', 'pn',
        'dato', "dato'", 'datuk', 'datin', 'haji', 'hajah', 'ustaz', 'ustazah'];

    protected $fillable = ['name', 'nickname', 'relationship', 'organisation', 'location', 'about', 'catch_up_days', 'archived_at'];

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

    /** Things to remember about them, the latest first (undated ones last). */
    public function memories(): HasMany
    {
        return $this->hasMany(PersonMemory::class)->orderByDesc('on')->orderByDesc('id');
    }

    public function notes(): BelongsToMany
    {
        return $this->belongsToMany(Note::class, 'person_note');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'person_project')->orderBy('name');
    }

    /** How to call them in a sentence: "Daniel" for Daniel Tan, "Dr. Lim" for Dr. Lim Wei. */
    public function callName(): string
    {
        $words = preg_split('/\s+/u', trim($this->name)) ?: [''];
        $title = count($words) > 1 && in_array(rtrim(mb_strtolower($words[0]), '.'), self::TITLES, true);

        return $title ? "{$words[0]} {$words[1]}" : $words[0];
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
        // The last one that counts ("Doesn't count": a quick like or a wave keeps the clock running).
        $last = $this->relationLoaded('catchUps') ? $this->catchUps->firstWhere('counts', true)?->on : $this->catchUps()->where('counts', true)->value('on');
        $base = $last
            ? CarbonImmutable::parse($last instanceof \DateTimeInterface ? $last->format('Y-m-d') : $last, $today->timezone)
            : CarbonImmutable::instance($this->created_at ?? $today)->timezone($today->timezone)->startOfDay()->min($today);

        return $base->addDays($this->catch_up_days);
    }
}
