<?php

namespace App\Console\Commands;

use App\Jobs\ProcessRawJobPost;
use App\Models\Job;
use App\Models\RawJobPost;
use App\Processing\ExtractionProcessor;
use Illuminate\Console\Command;

class DispatchProcessingCommand extends Command
{
    protected $signature = 'processing:dispatch
        {--limit=500 : Most ads to queue in one go}
        {--outdated : Also redo jobs made with older reading rules (only ads whose text is still stored)}
        {--failed : Also retry ads whose processing failed}';

    protected $description = 'Queue the processing job for ads still waiting (for example ads stored before processing was switched on), and optionally redo outdated or failed ones';

    public function handle(): int
    {
        if (! config('job_intel.processing_enabled')) {
            $this->warn('Processing is switched off (PROCESSING_ENABLED=false), so nothing was queued. Ads stay pending.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $redo = collect();

        if ($this->option('failed')) {
            $ids = RawJobPost::where('processing_status', 'failed')->orderBy('id')->limit($limit)->pluck('id');
            $redo = $redo->merge($ids);
            $this->line("Retrying {$ids->count()} failed ".($ids->count() === 1 ? 'ad' : 'ads').'.');
        }

        if ($this->option('outdated')) {
            $redo = $redo->merge($this->outdated($limit));
        }

        // A processed or failed ad goes back to "pending" so the job will take it; the raw ad itself is not changed.
        if ($redo->isNotEmpty()) {
            RawJobPost::whereIn('id', $redo->unique()->all())->update(['processing_status' => 'pending', 'processing_error' => null]);
        }

        $ids = RawJobPost::where('processing_status', 'pending')->orderBy('id')->limit($limit)->pluck('id');

        foreach ($ids as $id) {
            ProcessRawJobPost::dispatch($id);
        }

        $this->info("Queued {$ids->count()} pending ".($ids->count() === 1 ? 'ad' : 'ads').'.');

        return self::SUCCESS;
    }

    /**
     * Ads behind jobs that were made with older rules. Re-reading an ad whose text has been cleared would lose what was
     * read from that text, so those are left alone and counted; `jobs:standardize` can still redo their standard values.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function outdated(int $limit)
    {
        $jobs = Job::query()->where(fn ($q) => $q->whereNull('extraction_version')->orWhere('extraction_version', '<', ExtractionProcessor::VERSION))
            ->with('rawPost:id,raw_content')->orderBy('id')->limit($limit)->get();

        [$readable, $cleared] = $jobs->partition(fn ($job) => $job->rawPost?->raw_content !== null);

        $this->line("Redoing {$readable->count()} ".($readable->count() === 1 ? 'job' : 'jobs').' made with older rules.');

        if ($cleared->isNotEmpty()) {
            $this->warn("Left {$cleared->count()} ".($cleared->count() === 1 ? 'job' : 'jobs').' alone: the ad text was cleared, so re-reading would lose what was read from it.');
            $this->line('To redo their standard values instead, run:');
            $this->line('  php artisan jobs:standardize --all');
        }

        return $readable->pluck('raw_job_post_id');
    }
}
