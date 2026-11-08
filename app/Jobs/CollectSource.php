<?php

namespace App\Jobs;

use App\Collectors\CollectorRegistry;
use App\Collectors\RunCleaner;
use App\Models\Source;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * One collection of one source, in the background.
 *
 *  - unique per source: while one is waiting or running, another is not queued
 *  - checks again, when it actually starts, that the source is still approved, active and
 *    (for scheduled runs) still due: permission may have changed while it waited
 *  - runs once (tries = 1): retries are the scheduler's back-off, not the queue's
 *  - if it crashes or times out, the run is closed as failed so it never stays "running"
 */
class CollectSource implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    /** Seconds the "already queued" lock is held at most, if the job vanishes without finishing. */
    public int $uniqueFor;

    /** @param 'scheduled'|'manual' $trigger */
    public function __construct(public int $sourceId, public string $trigger = 'scheduled')
    {
        $this->timeout = (int) config('job_intel.collect_job_timeout');
        $this->uniqueFor = $this->timeout + 300;
    }

    public function uniqueId(): string
    {
        return "collect-source-{$this->sourceId}";
    }

    public function handle(CollectorRegistry $registry, RunCleaner $cleaner): void
    {
        $source = Source::find($this->sourceId);

        // Gone, switched off, no longer approved, or not collected by the app (manual, Python worker): nothing to do.
        if (! $source || ! $source->is_active || $source->access_status !== 'approved' || in_array($source->collection_method, Source::EXTERNAL_METHODS, true)) {
            return;
        }

        // Another run finished while this one waited in the queue.
        if ($this->trigger === 'scheduled' && $source->next_collection_at?->isFuture()) {
            return;
        }

        try {
            $registry->for($source)->collect($source, $this->trigger);
        } catch (RuntimeException $e) {
            // Could not even start (no collector, bad settings): show it as a failed run and let the source back off.
            $cleaner->recordFailedAttempt($source, $this->trigger, $e->getMessage());
        }
    }

    /** The job crashed or timed out while a run was open. */
    public function failed(Throwable $e): void
    {
        app(RunCleaner::class)->failRunningRunsOf(
            $this->sourceId,
            'The background job stopped before the run finished: '.mb_substr($e->getMessage(), 0, 300),
        );
    }
}
