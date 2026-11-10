<?php

namespace App\Jobs;

use App\Ai\AiFailure;
use App\Ai\Extraction\AiEligibility;
use App\Ai\Extraction\QualificationExtractor;
use App\Models\Job;
use App\Processing\AiRequirementApplier;
use App\Processing\ExtractionProcessor;
use App\Standardization\Text;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Asks the AI about one vacancy in the background, so a slow or failing AI service never touches the clean record.
 * Only for a vacancy AiEligibility allows. A passing failure (timeout, rate limit, server error) is retried later;
 * a refusal, a limit or a switched-off service is logged and left, and nothing about the job changes.
 */
class ExtractJobQualifications implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @return list<int> seconds to wait before each retry */
    public function backoff(): array
    {
        return [60, 300];
    }

    /** @param bool $force skip the "rules found nothing" tests (an explicit request for this vacancy) */
    public function __construct(public int $jobId, public bool $force = false) {}

    public function handle(AiEligibility $eligibility, QualificationExtractor $extractor, AiRequirementApplier $applier): void
    {
        $job = Job::with('rawPost.source')->find($this->jobId);
        if ($job === null) {
            return;
        }
        if ($reason = $eligibility->reason($job, $this->force)) {
            $this->log($job, 'warning', "AI skipped: {$reason}");

            return;
        }

        try {
            $result = $extractor->extract(Text::plain($job->rawPost->raw_content), $job->id);
        } catch (AiFailure $e) {
            $this->log($job, 'error', 'AI failed: '.$e->getMessage());
            if ($e->retryable && $this->attempts() < $this->tries) {
                throw $e;   // the queue tries again after the backoff
            }

            return;
        }

        if (! $result->ok) {
            $this->log($job, 'warning', "AI answer refused: {$result->error}");

            return;
        }

        $applier->apply($job, $result);
    }

    private function log(Job $job, string $status, string $message): void
    {
        DB::table('extraction_logs')->insert([
            'raw_job_post_id' => $job->raw_job_post_id, 'job_id' => $job->id, 'stage' => 'extract', 'extraction_version' => ExtractionProcessor::VERSION,
            'status' => $status, 'message' => mb_substr($message, 0, 500), 'created_at' => now(),
        ]);
    }
}
