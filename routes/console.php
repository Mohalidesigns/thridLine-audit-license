<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('licenses:expire-check')
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/license-cron.log'));

// Apply scheduled (grace-delayed) revokes once their effective time passes.
// Enforcement is already lazy via the client validate/heartbeat checks; this
// keeps the license status + activation rows consistent for the admin portal.
Schedule::command('licenses:apply-revocations')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('licenses:heartbeat-alerts')
    ->everyFourHours()
    ->withoutOverlapping();
