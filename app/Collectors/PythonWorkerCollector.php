<?php

namespace App\Collectors;

use App\Models\Source;
use App\Models\SourceRun;
use RuntimeException;

/**
 * Sources collected by the Python worker (collectors_py/) that runs on your own computer:
 * pages that need real parsing (BeautifulSoup) or a real browser (Playwright).
 *
 * The app never fetches these itself. The worker finds out which are due (python:due), collects,
 * and hands the ads over with collect:import, which comes here: same storage rules as every
 * other collector. Unlike manual sources these ARE scheduled, so after each run the source is
 * rescheduled (or backs off after a failure, and is switched off after too many).
 */
class PythonWorkerCollector extends ManualSubmissionCollector
{
    public function method(): string
    {
        return 'python';
    }

    protected function allowedMethods(): array
    {
        return ['python'];
    }

    protected function fetch(Source $source): array
    {
        throw new RuntimeException("'{$source->name}' is collected by the Python worker on your computer (collectors_py/run_due.py), not by the app.");
    }

    protected function afterRun(Source $source, SourceRun $run): void
    {
        app(SourceScheduler::class)->recordResult($source, $run);
    }
}
