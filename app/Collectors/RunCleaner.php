<?php

namespace App\Collectors;

use App\Models\SourceRun;
use App\Models\SourceRunError;

/**
 * A run that died halfway (the process was killed, the host restarted, a timeout) would
 * stay "running" forever. This marks such runs failed, with a reason, and lets the
 * source back off like any other failure.
 */
class RunCleaner
{
    public function failStuckRuns(): int
    {
        $limit = (int) config('job_intel.stuck_run_minutes');

        $stuck = SourceRun::with('source')
            ->where('status', 'running')
            ->where('started_at', '<', now()->subMinutes($limit))
            ->get();

        foreach ($stuck as $run) {
            $message = "The run did not finish within {$limit} minutes (the process may have stopped or timed out), so it was marked failed.";

            $run->update(['status' => 'failed', 'finished_at' => now(), 'error_message' => $message]);
            SourceRunError::create(['source_run_id' => $run->id, 'stage' => 'other', 'message' => $message]);

            if ($run->source) {
                app(SourceScheduler::class)->recordResult($run->source, $run);
            }
        }

        return $stuck->count();
    }
}
