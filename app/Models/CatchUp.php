<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A time the user was in touch with someone: a message, a call, meeting up. */
class CatchUp extends Model
{
    use SoftDeletes;

    protected $fillable = ['on', 'kind', 'note'];

    protected function casts(): array
    {
        return ['on' => 'immutable_date'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class)->withTrashed();
    }
}
