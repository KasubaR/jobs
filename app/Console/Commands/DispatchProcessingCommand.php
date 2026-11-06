<?php

namespace App\Console\Commands;

use App\Jobs\ProcessRawJobPost;
use App\Models\RawJobPost;
use Illuminate\Console\Command;

class DispatchProcessingCommand extends Command
{
    protected $signature = 'processing:dispatch {--limit=500 : Most ads to queue in one go}';

    protected $description = 'Queue the processing job for ads still waiting (for example ads stored before processing was switched on)';

    public function handle(): int
    {
        if (! config('job_intel.processing_enabled')) {
            $this->warn('Processing is switched off (PROCESSING_ENABLED=false), so nothing was queued. Ads stay pending.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $ids = RawJobPost::where('processing_status', 'pending')->orderBy('id')->limit($limit)->pluck('id');

        foreach ($ids as $id) {
            ProcessRawJobPost::dispatch($id);
        }

        $this->info("Queued {$ids->count()} pending ".($ids->count() === 1 ? 'ad' : 'ads').'.');

        return self::SUCCESS;
    }
}
