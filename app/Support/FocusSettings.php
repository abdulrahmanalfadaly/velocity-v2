<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Validation\Rule;

/** Settings → Focus: how "Start focus" starts, the Pomodoro lengths, and what happens on its own. */
class FocusSettings
{
    public const DEFAULTS = [
        'mode' => 'timer',    // what "Start focus" starts: a timer or a Pomodoro
        'length' => 50,       // the timer's minutes
        'focus' => 25,        // Pomodoro: a round
        'short' => 5,         // … a short break
        'long' => 15,         // … a long break
        'every' => 4,         // … after this many rounds
        'autoBreak' => true,  // a break starts by itself when a round ends
        'autoNext' => false,  // the next round starts by itself when a break ends (while Velocity is open)
        'sound' => true,      // a soft sound when time is up
    ];

    public static function of(User $user): array
    {
        return array_merge(self::DEFAULTS, array_intersect_key($user->focus_settings ?? [], self::DEFAULTS));
    }

    public static function rules(): array
    {
        return [
            'mode' => ['required', Rule::in(['timer', 'pomodoro'])],
            'length' => ['required', 'integer', 'between:1,240'],
            'focus' => ['required', 'integer', 'between:1,240'],
            'short' => ['required', 'integer', 'between:1,60'],
            'long' => ['required', 'integer', 'between:1,120'],
            'every' => ['required', 'integer', 'between:1,12'],
            'autoBreak' => ['required', 'boolean'],
            'autoNext' => ['required', 'boolean'],
            'sound' => ['required', 'boolean'],
        ];
    }
}
