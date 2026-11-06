<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Collection: checked every 15 minutes; each source runs only when its own frequency says it is due.
Schedule::command('collect:run')->everyFifteenMinutes()->withoutOverlapping();

// Retention: clear raw ad text older than RAW_RETENTION_DAYS (default 90).
Schedule::command('raw:purge')->dailyAt('03:00');

// A run that died halfway would stay "running" forever: mark it failed so the source can back off.
Schedule::command('runs:cleanup')->everyTenMinutes();
