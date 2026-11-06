<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Collection: every 15 minutes, queue one background job for each source whose own frequency says it is due.
Schedule::command('collect:dispatch')->everyFifteenMinutes()->withoutOverlapping();

// The host only offers a scheduler, so the scheduler also starts the queue worker each minute.
// It works through what is waiting and stops after about 50 seconds; the next minute starts the next one.
// withoutOverlapping(20): never two workers at once, and a worker lost mid-job cannot block the next ones for long.
Schedule::command('queue:work', ['--stop-when-empty', '--max-time=50', '--tries=1'])
    ->everyMinute()
    ->withoutOverlapping(20)
    ->name('queue-worker');

// Retention: clear raw ad text older than RAW_RETENTION_DAYS (default 90).
Schedule::command('raw:purge')->dailyAt('03:00');

// A run that died halfway would stay "running" forever: mark it failed so the source can back off.
Schedule::command('runs:cleanup')->everyTenMinutes();
