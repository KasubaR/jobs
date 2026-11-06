<?php

namespace App\Console\Commands;

use App\Models\RawJobPost;
use Illuminate\Console\Command;

class PurgeRawCommand extends Command
{
    protected $signature = 'raw:purge';

    protected $description = 'Delete raw ad text older than the retention period (structured data is kept)';

    public function handle(): int
    {
        $cutoff = now()->subDays(config('job_intel.raw_retention_days'));

        $count = RawJobPost::whereNotNull('raw_content')
            ->where('first_seen_at', '<', $cutoff)
            ->update(['raw_content' => null, 'raw_purged_at' => now()]);

        $this->info("Cleared raw content from {$count} ads older than {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
