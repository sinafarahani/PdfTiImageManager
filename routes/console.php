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
 * Takes the contents of the profiles named in CONVERTER_SKIP_PROFILES out of the queue, so that
 * naming a profile in .env is all that is needed: whatever was already queued is retired here rather
 * than one FTP connection at a time. A no-op when no profile is named.
 */
Schedule::command('converters:skip')->everyFifteenMinutes()->withoutOverlapping();

/*
 * Puts conversions back whose worker died mid-way, after removing exactly the page rows and images
 * that attempt had created. This is what used to be done by hand with rebuild scripts.
 */
Schedule::command('converters:reconcile')->everyMinute()->withoutOverlapping();

/*
 * Removes the workspace folder a conversion that was killed mid-way left on the staging drive. The
 * pipeline deletes its own folders; this is for the ones whose worker never got that far, and the
 * drive filling up is what cost the old pipeline 206 contents.
 */
Schedule::command('converters:sweep')->hourly()->withoutOverlapping();

// Failed queue jobs are only a transport record; the conversions table is the real history.
Schedule::command('queue:prune-failed --hours=168')->daily();
