<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A habit. Its schedule is one of: every day (days, per_week, per_month and
 * every_days empty), chosen weekdays (days, ISO 1 = Monday), a number of times
 * a week or a month on any days (per_week / per_month), or every N days from
 * starts_on (every_days).
 *
 * Its kind says what a day asks for: "do" (target > 1 makes it counted, "8
 * glasses"), "minutes" (target = minutes), "limit" (at most target, "2
 * coffees") or "avoid" (none at all). A limit or avoid day with nothing logged
 * is a day kept. at_time + minutes put it on the Planner.
 */
class Habit extends Model
{
    use SoftDeletes;

    public const KINDS = ['do', 'minutes', 'limit', 'avoid'];

    protected $fillable = ['project_id', 'name', 'kind', 'detail', 'days', 'per_week', 'per_month', 'every_days', 'starts_on', 'target', 'unit', 'at_time', 'minutes', 'archived_at'];

    protected function casts(): array
    {
        return [
            'days' => 'array', 'per_week' => 'integer', 'per_month' => 'integer', 'every_days' => 'integer', 'starts_on' => 'date',
            'target' => 'integer', 'minutes' => 'integer', 'archived_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(HabitEntry::class);
    }

    public function pauses(): HasMany
    {
        return $this->hasMany(HabitPause::class)->orderBy('from');
    }

    public function routines(): BelongsToMany
    {
        return $this->belongsToMany(Routine::class)->withPivot('position');
    }

    public function weekly(): bool
    {
        return $this->per_week !== null;
    }

    public function monthly(): bool
    {
        return $this->per_month !== null;
    }

    /** Done on any days of the week or month: a day without it isn't a miss. */
    public function flexible(): bool
    {
        return $this->weekly() || $this->monthly();
    }

    /** Fewer is better: a limit or something to avoid. */
    public function less(): bool
    {
        return in_array($this->kind, ['limit', 'avoid'], true);
    }

    /** Whether a day's count meets the habit: enough (do, minutes), no more than the limit, or none (avoid). */
    public function achieved(int $count): bool
    {
        return match ($this->kind) {
            'limit' => $count <= $this->target,
            'avoid' => $count === 0,
            default => $count >= $this->target,
        };
    }

    /** Whether the schedule asks for it on this day. A weekly or monthly habit can be done on any day. */
    public function isDue(CarbonImmutable $day): bool
    {
        if ($this->every_days) {
            $anchor = CarbonImmutable::parse(($this->starts_on ?? $this->created_at)->toDateString(), $day->timezone);
            $diff = (int) round($anchor->diffInDays($day->startOfDay()));

            return $diff >= 0 && $diff % $this->every_days === 0;
        }

        return $this->flexible() || empty($this->days) || in_array($day->dayOfWeekIso, $this->days, true);
    }

    /** Paused on this day ("YYYY-MM-DD")? Uses the loaded pauses. */
    public function pausedOn(string $day): bool
    {
        return $this->pauses->contains(fn (HabitPause $p) => $p->covers($day));
    }

    /** The pause that covers today, if any. */
    public function pauseOn(string $day): ?HabitPause
    {
        return $this->pauses->first(fn (HabitPause $p) => $p->covers($day));
    }

    /** "Every day", "Mon, Wed, Fri", "3 times a week", "5 times a month", "Every 3 days". */
    public function scheduleLabel(): string
    {
        if ($this->weekly()) {
            return $this->per_week === 1 ? 'Once a week' : "{$this->per_week} times a week";
        }
        if ($this->monthly()) {
            return $this->per_month === 1 ? 'Once a month' : "{$this->per_month} times a month";
        }
        if ($this->every_days) {
            return "Every {$this->every_days} days";
        }
        $days = $this->days ?? [];
        sort($days);
        if ($days === [] || $days === [1, 2, 3, 4, 5, 6, 7]) {
            return 'Every day';
        }
        if ($days === [1, 2, 3, 4, 5]) {
            return 'Weekdays';
        }
        if ($days === [6, 7]) {
            return 'Weekends';
        }
        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

        return implode(', ', array_map(fn ($d) => $names[$d], $days));
    }
}
