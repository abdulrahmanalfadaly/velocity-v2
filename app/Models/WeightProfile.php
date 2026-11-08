<?php

namespace App\Models;

use App\Domain\Prioritization\RankingParameters;
use App\Domain\Prioritization\Weights;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeightProfile extends Model
{
    protected $fillable = ['importance', 'urgency', 'deadline', 'pressure', 'horizon_days', 'hours_per_day'];

    protected function casts(): array
    {
        return [
            'importance' => 'float', 'urgency' => 'float', 'deadline' => 'float', 'pressure' => 'float',
            'horizon_days' => 'float', 'hours_per_day' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function weights(): Weights
    {
        return Weights::fromArray(['I' => $this->importance, 'U' => $this->urgency, 'D' => $this->deadline, 'P' => $this->pressure]);
    }

    public function parameters(): RankingParameters
    {
        return new RankingParameters($this->weights(), $this->horizon_days, $this->hours_per_day);
    }
}
