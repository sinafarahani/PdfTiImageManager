<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Keeps the work queue current. The previous pipeline read a snapshot table that was built once and
 * never refreshed, so contents added afterwards were never converted until somebody dropped the
 * table by hand; this pass picks them up on its own.
 */
Schedule::command('converters:discover')
    ->cron('*/'.max(1, min(59, (int) config('converter.discovery.interval_minutes'))).' * * * *')
    ->withoutOverlapping();

/*
 * Puts conversions back whose worker died mid-way, after removing exactly the page rows and images
 * that attempt had created. This is what used to be done by hand with rebuild scripts.
 */
Schedule::command('converters:reconcile')->everyMinute()->withoutOverlapping();

// Failed queue jobs are only a transport record; the conversions table is the real history.
Schedule::command('queue:prune-failed --hours=168')->daily();
