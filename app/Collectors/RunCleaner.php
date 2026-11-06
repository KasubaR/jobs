<?php

namespace App\Collectors;

use App\Models\Source;
use App\Models\SourceRun;
use App\Models\SourceRunError;

/**
 * Closes runs that did not end properly, so the run list never lies and the source
 * can back off like after any other failure:
 *  - a run that died halfway (process killed, host restarted, timeout) would stay "running" forever
 *  - an attempt that could not even start (source misconfigured) needs a visible failed run
 */
class RunCleaner
{
    /** Runs still "running" after stuck_run_minutes. @return int how many were closed */
    public function failStuckRuns(): int
    {
        $limit = (int) config('job_intel.stuck_run_minutes');

        $stuck = SourceRun::with('source')
            ->where('status', 'running')
            ->where('started_at', '<', now()->subMinutes($limit))
            ->get();

        foreach ($stuck as $run) {
            $this->failRun($run, "The run did not finish within {$limit} minutes (the process may have stopped or timed out), so it was marked failed.");
        }

        return $stuck->count();
    }

    /** Close one run as failed, with the reason, and let its source back off. */
    public function failRun(SourceRun $run, string $message): void
    {
        $run->update(['status' => 'failed', 'finished_at' => now(), 'error_message' => mb_substr($message, 0, 1000)]);
        SourceRunError::create(['source_run_id' => $run->id, 'stage' => 'other', 'message' => mb_substr($message, 0, 2000)]);

        $source = $run->source ?? Source::find($run->source_id);
        if ($source) {
            app(SourceScheduler::class)->recordResult($source, $run);
        }
    }

    /** Close every run of a source that is still marked running (its queue job crashed or timed out). @return int */
    public function failRunningRunsOf(int $sourceId, string $message): int
    {
        $runs = SourceRun::where('source_id', $sourceId)->where('status', 'running')->get();

        foreach ($runs as $run) {
            $this->failRun($run, $message);
        }

        return $runs->count();
    }

    /** An attempt that failed before any run was created: record it as a failed run. */
    public function recordFailedAttempt(Source $source, string $trigger, string $message): SourceRun
    {
        $run = SourceRun::create([
            'source_id' => $source->id, 'started_at' => now(), 'status' => 'running', 'trigger' => $trigger,
            'items_found' => 0, 'items_new' => 0, 'items_updated' => 0, 'items_unchanged' => 0, 'items_failed' => 0, 'http_errors' => 0,
        ]);

        $this->failRun($run->setRelation('source', $source), $message);

        return $run->fresh();
    }
}
