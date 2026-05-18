<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('licenses:expire-check')
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/license-cron.log'));

Schedule::command('licenses:heartbeat-alerts')
    ->everyFourHours()
    ->withoutOverlapping();
