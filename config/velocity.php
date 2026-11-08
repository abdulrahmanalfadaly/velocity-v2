<?php

/*
 * Velocity's own settings. The ranking follows the Project I report:
 * Simple Additive Weighting over four criteria with fixed bounds and
 * AHP-derived default weights (Algorithms 3.1–3.3).
 */
return [
    // Freeze "now" (e.g. "2026-11-09 09:00") so deadline effects can be tested.
    'fixed_now' => env('VELOCITY_NOW') ?: null,

    // Default time zone for new accounts; every user can change their own.
    'timezone' => env('VELOCITY_TIMEZONE', 'Asia/Kuala_Lumpur'),

    // Record every ranking shown and every weight change for the evaluation study.
    'study_log' => (bool) env('VELOCITY_STUDY_LOG', true),
];
