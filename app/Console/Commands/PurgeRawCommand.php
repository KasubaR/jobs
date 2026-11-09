<?php

namespace App\Console\Commands;

use App\Models\RawJobPost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeRawCommand extends Command
{
    protected $signature = 'raw:purge';

    protected $description = 'Delete raw ad text older than the retention period (structured data is kept)';

    public function handle(): int
    {
        $cutoff = now()->subDays(config('job_intel.raw_retention_days'));

        $old = RawJobPost::whereNotNull('raw_content')->where('first_seen_at', '<', $cutoff);

        // The words quoted as evidence are the ad's text, so they go with it. What was learned from them stays.
        $ids = (clone $old)->pluck('id');
        foreach ($ids->chunk(500) as $chunk) {
            $jobs = DB::table('jobs')->whereIn('raw_job_post_id', $chunk)->pluck('id');
            foreach (['job_skills', 'job_qualifications', 'job_experiences'] as $table) {
                DB::table($table)->whereIn('job_id', $jobs)->update(['evidence' => null]);
            }
        }

        $count = $old->update(['raw_content' => null, 'raw_purged_at' => now()]);

        $this->info("Cleared raw content from {$count} ads older than {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
