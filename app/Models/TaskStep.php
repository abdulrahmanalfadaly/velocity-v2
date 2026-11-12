<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaskStep extends Model
{
    use SoftDeletes;

    protected $fillable = ['title', 'done_at', 'position'];

    protected function casts(): array
    {
        return ['done_at' => 'datetime'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
