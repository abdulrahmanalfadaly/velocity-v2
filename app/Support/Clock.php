<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * "Now" for everything time-based. Set VELOCITY_NOW (read in the default
 * Velocity time zone) to freeze it for demos and black-box tests of FR6.
 */
class Clock
{
    public function now(): CarbonImmutable
    {
        $fixed = config('velocity.fixed_now');

        return $fixed
            ? CarbonImmutable::parse($fixed, config('velocity.timezone'))->utc()
            : CarbonImmutable::now('UTC');
    }
}
