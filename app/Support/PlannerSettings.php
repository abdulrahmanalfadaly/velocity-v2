<?php

namespace App\Support;

use App\Models\User;

/**
 * Settings → Planner: how much task work "Plan" books a day (fill the working hours, or at most "Hours a day"
 * from Settings → Tasks), the gap kept around blocks and events, and how short or long a booked block may be.
 */
class PlannerSettings
{
    public const DEFAULTS = [
        'gap' => 10,       // minutes kept free around events and habit times, and after each block
        'shortest' => 30,  // a booked block is at least this long (unless less is left to do)
        'longest' => 120,  // … and at most this long, so a break comes in between
        'cap' => 0,        // 0 = fill the working hours; 1 = at most "Hours a day" (the formula's H, Settings → Tasks)
    ];

    public static function of(User $user): array
    {
        return array_merge(self::DEFAULTS, array_intersect_key($user->planner_settings ?? [], self::DEFAULTS));
    }

    public static function rules(): array
    {
        return [
            'gap' => ['required', 'integer', 'between:0,60'],
            'shortest' => ['required', 'integer', 'between:15,120'],
            'longest' => ['required', 'integer', 'between:30,240', 'gte:shortest'],
            'cap' => ['sometimes', 'integer', 'in:0,1'],
        ];
    }
}
