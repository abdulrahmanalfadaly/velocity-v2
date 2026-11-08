<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The evaluation log the report requires (Section 3.6.3). */
class StudyLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'study_log';

    protected $fillable = ['user_id', 'kind', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'datetime'];
    }
}
