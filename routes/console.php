<?php

use App\Console\Commands\MonitorFreezeParent;
use App\Console\Commands\SaveIDsParent;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(MonitorFreezeParent::class)->everyFifteenMinutes();
Schedule::command(SaveIDsParent::class)->everyThirtyMinutes();
