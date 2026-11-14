<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A habit paused from a day, until a day or until resumed: those days are neither due nor missed. */
class HabitPause extends Model
{
    protected $fillable = ['from', 'until'];

    protected function casts(): array
    {
        return ['from' => 'date', 'until' => 'date'];
    }

    public function habit(): BelongsTo
    {
        return $this->belongsTo(Habit::class);
    }

    public function covers(string $day): bool
    {
        return $this->from->toDateString() <= $day && ($this->until === null || $this->until->toDateString() >= $day);
    }
}
