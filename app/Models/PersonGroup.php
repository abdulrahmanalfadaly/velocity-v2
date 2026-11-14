<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A group of people: Family, Friends, FYP team. Someone can be in several. */
class PersonGroup extends Model
{
    use SoftDeletes;

    protected $fillable = ['name'];

    public function people(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'person_group_members');
    }
}
