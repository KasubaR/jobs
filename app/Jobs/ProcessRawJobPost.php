<?php

namespace App\Jobs;

use App\Models\RawJobPost;
use App\Processing\RawPostProcessor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Hands one stored ad to the processing stage, in the background. */
class ProcessRawJobPost implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $rawJobPostId) {}

    public function uniqueId(): string
    {
        return "process-raw-post-{$this->rawJobPostId}";
    }

    public function handle(RawPostProcessor $processor): void
    {
        $post = RawJobPost::find($this->rawJobPostId);

        // Gone, or already dealt with (for example processed by an earlier queued job).
        if (! $post || $post->processing_status !== 'pending') {
            return;
        }

        try {
            $finished = $processor->process($post);
        } catch (Throwable $e) {
            $this->markFailed($post, $e->getMessage());

            return;
        }

        if ($finished) {
            $post->update(['processing_status' => 'processed', 'processed_at' => now(), 'processing_error' => null]);
        }
    }

    public function failed(Throwable $e): void
    {
        if ($post = RawJobPost::find($this->rawJobPostId)) {
            $this->markFailed($post, $e->getMessage());
        }
    }

    private function markFailed(RawJobPost $post, string $message): void
    {
        $post->update(['processing_status' => 'failed', 'processing_error' => mb_substr($message, 0, 2000)]);
    }
}
