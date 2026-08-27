<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * The review window sweep.
 *
 * Hourly rather than daily, because the window closes at whatever time of day it
 * opened, and a daily run would leave a seller blocked for up to a further day after
 * their proposal was already decidable.
 *
 * `withoutOverlapping` because a slow run must not have a second one start beside it:
 * two sweeps resolving the same proposal is exactly the race the row lock in the
 * resolution service defends against, and not starting the race is cheaper than
 * winning it.
 *
 * This needs monitoring rather than best effort execution. A missed run leaves a
 * proposing seller unable to trade, and nothing else in the platform will notice.
 */
Schedule::command('proposals:sweep')->hourly()->withoutOverlapping();
