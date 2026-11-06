<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Collection: once a day, only for approved + active sources.
Schedule::command('collect:run')->dailyAt('05:30')->withoutOverlapping();

// Retention: clear raw ad text older than RAW_RETENTION_DAYS (default 90).
Schedule::command('raw:purge')->dailyAt('03:00');
