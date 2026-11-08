<?php

namespace App\Processing;

use App\Models\Job;
use App\Models\RawJobPost;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 6, step 1: turns a stored ad into one `jobs` record and writes an extraction log row.
 *
 * - One job per ad address of a source: when an ad changes (a new stored version) its job is updated and
 *   pointed at the newest version, never duplicated.
 * - Safe to run twice: the same ad gives the same job.
 * - A job a person already reviewed keeps its "reviewed" state.
 * - The raw ad is left exactly as it was.
 */
class ExtractionProcessor implements RawPostProcessor
{
    /** Raised when the reading rules improve, so older jobs can be found and reprocessed. */
    public const VERSION = 1;

    public function __construct(private JobExtractor $extractor) {}

    public function process(RawJobPost $post): bool
    {
        try {
            $result = $this->extractor->extract($post);
        } catch (Throwable $e) {
            $this->log($post, null, 'error', $e->getMessage());

            throw $e;
        }

        $job = DB::transaction(function () use ($post, $result) {
            $job = Job::whereIn('raw_job_post_id', RawJobPost::where('source_id', $post->source_id)
                ->where('source_url', $post->source_url)->select('id'))->first();

            $data = $result['fields'] + [
                'raw_job_post_id' => $post->id,
                'source_url' => $post->source_url,
                'confidence' => $result['confidence'],
                'field_sources' => $result['sources'],
                'extraction_version' => self::VERSION,
                'last_seen_at' => $post->last_seen_at ?? now(),
                'review_status' => $result['needs_review'] ? 'needs_review' : 'auto',
            ];

            if ($job === null) {
                return Job::create($data + ['first_seen_at' => $post->first_seen_at ?? now()]);
            }

            if ($job->review_status === 'reviewed') {
                $data['review_status'] = 'reviewed'; // a person's decision stays
            }

            $job->update($data);

            return $job;
        });

        $this->log($post, $job, $result['warnings'] ? 'warning' : 'ok', $result['warnings'] ? implode(' ', $result['warnings']) : null);

        return true;
    }

    private function log(RawJobPost $post, ?Job $job, string $status, ?string $message): void
    {
        DB::table('extraction_logs')->insert([
            'raw_job_post_id' => $post->id,
            'job_id' => $job?->id,
            'stage' => 'extract',
            'extraction_version' => self::VERSION,
            'status' => $status,
            'message' => $message === null ? null : mb_substr($message, 0, 2000),
            'created_at' => now(),
        ]);
    }
}
