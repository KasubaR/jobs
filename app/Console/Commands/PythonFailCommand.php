<?php

namespace App\Console\Commands;

use App\Collectors\RunCleaner;
use App\Import\AdImporter;
use Illuminate\Console\Command;

/**
 * The worker could not collect (a script crashed, the site blocked it, a CAPTCHA appeared).
 * This records a failed run with the reason, so the source backs off like any failing source and
 * is switched off after too many failures in a row. It never retries on its own.
 */
class PythonFailCommand extends Command
{
    protected $signature = 'python:fail {source : Source id or exact name} {message : What went wrong} {--blocked : The site blocked or challenged the worker (CAPTCHA, login, rate limit, bot detection)}';

    protected $description = 'Record a failed Python-worker run for a source, so it backs off';

    public function handle(AdImporter $importer, RunCleaner $cleaner): int
    {
        $source = $importer->find((string) $this->argument('source'));

        if (! $source || $source->collection_method !== 'python') {
            $this->error('No Python-worker source matches that id or name.');

            return self::FAILURE;
        }

        if (! $source->is_active || $source->access_status !== 'approved') {
            $this->error("'{$source->name}' is not approved and active, so no run is recorded.");

            return self::FAILURE;
        }

        $message = trim((string) $this->argument('message'));
        $message = ($this->option('blocked') ? 'Blocked, so the worker stopped and did not try to get round it: ' : 'The Python worker failed: ').mb_substr($message, 0, 800);

        $run = $cleaner->recordFailedAttempt($source, 'python', $message);
        $source = $source->fresh();

        $this->line("{$source->name}: failed run recorded (#{$run->id}). Failures in a row: {$source->consecutive_failures}; next try after {$source->next_collection_at}".($source->is_active ? '.' : '. The source was switched off.'));

        return self::SUCCESS;
    }
}
