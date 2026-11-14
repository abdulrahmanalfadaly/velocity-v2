<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A birthday, an anniversary or another date. Yearly ones come round every year;
 * 29 February falls on 28 February in other years. A one-off date needs its year.
 */
class PersonDate extends Model
{
    use SoftDeletes;

    public const KINDS = ['birthday', 'anniversary', 'other'];

    protected $fillable = ['kind', 'label', 'month', 'day', 'year', 'yearly', 'remind_days', 'reminded_for'];

    protected function casts(): array
    {
        return ['month' => 'integer', 'day' => 'integer', 'year' => 'integer', 'yearly' => 'boolean', 'remind_days' => 'integer', 'reminded_for' => 'immutable_date'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class)->withTrashed();
    }

    /** The date in a given year, as it is kept that year. */
    public function in(int $year, string $tz): CarbonImmutable
    {
        $day = $this->month === 2 && $this->day === 29 && ! CarbonImmutable::create($year, 1, 1)->isLeapYear() ? 28 : $this->day;

        return CarbonImmutable::create($year, $this->month, $day, 0, 0, 0, $tz);
    }

    /** The next time it comes round, today included. Null for a one-off date that has passed. */
    public function next(CarbonImmutable $today): ?CarbonImmutable
    {
        if (! $this->yearly) {
            $once = $this->in((int) $this->year, $today->timezone->getName());

            return $once >= $today ? $once : null;
        }
        $thisYear = $this->in($today->year, $today->timezone->getName());

        return $thisYear >= $today ? $thisYear : $this->in($today->year + 1, $today->timezone->getName());
    }

    /** Age on a birthday, years on an anniversary: from the year, when it is known. */
    public function turns(?CarbonImmutable $on): ?int
    {
        return $on && $this->year && $this->yearly && $this->kind !== 'other' ? $on->year - $this->year : null;
    }

    /** "Sara's birthday", "Adam's anniversary", "Dr Lee: viva". */
    public function title(string $name): string
    {
        return match ($this->kind) {
            'birthday' => "{$name}’s birthday",
            'anniversary' => $this->label ? "{$name}: {$this->label}" : "{$name}’s anniversary",
            default => "{$name}: ".($this->label ?: 'date'),
        };
    }
}
