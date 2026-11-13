<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A habit. Its schedule is one of: every day (days and per_week empty), chosen
 * weekdays (days, ISO 1 = Monday), or a number of times a week on any days (per_week).
 * target > 1 makes it counted ("8 glasses"); at_time + minutes put it on the Planner.
 */
class Habit extends Model
{
    use SoftDeletes;

    protected $fillable = ['project_id', 'name', 'detail', 'days', 'per_week', 'target', 'unit', 'at_time', 'minutes', 'archived_at'];

    protected function casts(): array
    {
        return ['days' => 'array', 'per_week' => 'integer', 'target' => 'integer', 'minutes' => 'integer', 'archived_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(HabitEntry::class);
    }

    public function routines(): BelongsToMany
    {
        return $this->belongsToMany(Routine::class)->withPivot('position');
    }

    public function weekly(): bool
    {
        return $this->per_week !== null;
    }

    /** Whether the schedule asks for it on this day. A weekly habit can be done on any day. */
    public function isDue(CarbonImmutable $day): bool
    {
        return $this->weekly() || empty($this->days) || in_array($day->dayOfWeekIso, $this->days, true);
    }

    /** "Every day", "Mon, Wed, Fri", "3 times a week". */
    public function scheduleLabel(): string
    {
        if ($this->weekly()) {
            return $this->per_week === 1 ? 'Once a week' : "{$this->per_week} times a week";
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
