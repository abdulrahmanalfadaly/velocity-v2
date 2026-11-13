<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A class or event on the Planner. Weekly ones repeat until repeat_until, minus skipped weeks. */
class Event extends Model
{
    use SoftDeletes;

    public const CALENDARS = ['class', 'work', 'personal'];

    protected $fillable = ['calendar', 'title', 'location', 'notes', 'all_day', 'starts_at', 'ends_at', 'repeat_weekly', 'repeat_until', 'skipped'];

    protected function casts(): array
    {
        return [
            'all_day' => 'boolean',
            'repeat_weekly' => 'boolean',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'repeat_until' => 'immutable_date',
            'skipped' => 'array',
        ];
    }

    /** The event as the dialog shows it: dates and times in the user's own time zone. */
    public function formValues(string $tz): array
    {
        $s = $this->starts_at->timezone($tz);
        $e = $this->ends_at->timezone($tz);

        return [
            'title' => $this->title,
            'calendar' => $this->calendar,
            'location' => $this->location,
            'notes' => $this->notes,
            'all_day' => $this->all_day,
            'date' => $s->toDateString(),
            'end_date' => $this->all_day ? $e->subDay()->toDateString() : null,
            'start_time' => $this->all_day ? null : $s->format('H:i'),
            'end_time' => $this->all_day ? null : $e->format('H:i'),
            'repeat_weekly' => $this->repeat_weekly,
            'repeat_until' => $this->repeat_until?->toDateString(),
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Every time this event happens between two moments (UTC), worked out in the
     * user's time zone so a 9:00 class stays at 9:00 all semester.
     *
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, date: string}>
     */
    public function occurrences(CarbonImmutable $from, CarbonImmutable $to, string $tz): array
    {
        $start = $this->starts_at->timezone($tz);
        $end = $this->ends_at->timezone($tz);
        if (! $this->repeat_weekly) {
            return $this->starts_at < $to && $this->ends_at > $from ? [['start' => $start->utc(), 'end' => $end->utc(), 'date' => $start->toDateString()]] : [];
        }
        $skipped = array_flip($this->skipped ?? []);
        $until = $this->repeat_until?->toDateString();
        // Jump straight to the week before the range instead of walking from the first class.
        $k = max(0, intdiv(max(0, $from->getTimestamp() - $this->ends_at->getTimestamp()), 7 * 86400) - 1);
        $out = [];
        for ($guard = 0; $guard < 600; $guard++, $k++) {
            $s = $start->addWeeks($k);
            if ($s->utc() >= $to || ($until !== null && $s->toDateString() > $until)) {
                break;
            }
            $e = $end->addWeeks($k);
            if ($e->utc() > $from && ! isset($skipped[$s->toDateString()])) {
                $out[] = ['start' => $s->utc(), 'end' => $e->utc(), 'date' => $s->toDateString()];
            }
        }

        return $out;
    }
}
