<?php

namespace App\Listeners;

use App\Events\RawJobPostStored;
use App\Jobs\ProcessRawJobPost;

/**
 * Queues the processing job for a newly stored or changed ad, once processing is switched on.
 * While it is off (before the processing stage exists) ads simply wait as "pending" and
 * `processing:dispatch` queues them later.
 */
class QueueRawPostForProcessing
{
    public function handle(RawJobPostStored $event): void
    {
        if (config('job_intel.processing_enabled')) {
            ProcessRawJobPost::dispatch($event->rawJobPostId);
        }
    }
}
