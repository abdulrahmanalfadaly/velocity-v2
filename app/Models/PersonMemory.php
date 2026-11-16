<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Something to remember about a person, with the day it happened if it has one. */
class PersonMemory extends Model
{
    use SoftDeletes;

    protected $fillable = ['text', 'on'];

    protected function casts(): array
    {
        return ['on' => 'immutable_date'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class)->withTrashed();
    }

    public function present(): array
    {
        return ['id' => $this->id, 'text' => $this->text, 'on' => $this->on?->toDateString()];
    }
}
