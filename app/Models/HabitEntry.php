<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One day of a habit: how many times (or how much) it was done, or that the day was skipped. */
class HabitEntry extends Model
{
    protected $fillable = ['day', 'count', 'skipped'];

    protected function casts(): array
    {
        return ['day' => 'date', 'count' => 'integer', 'skipped' => 'boolean'];
    }

    public function habit(): BelongsTo
    {
        return $this->belongsTo(Habit::class);
    }
}
