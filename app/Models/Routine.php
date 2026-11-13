<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A routine: habits done one after another, in the order given ("Morning: water, stretch, flashcards"). */
class Routine extends Model
{
    use SoftDeletes;

    protected $fillable = ['name'];

    public function habits(): BelongsToMany
    {
        return $this->belongsToMany(Habit::class)->withPivot('position')->orderByPivot('position');
    }
}
