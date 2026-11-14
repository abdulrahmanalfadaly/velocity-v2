<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A way to reach someone: an email address, a phone number, a link, or anything else. */
class PersonContact extends Model
{
    public $timestamps = false;

    protected $fillable = ['kind', 'label', 'value', 'position'];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
