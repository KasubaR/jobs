<?php

namespace App\Console\Commands;

use App\Models\Job;
use App\Standardization\JobStandardizer;
use Illuminate\Console\Command;

class StandardizeJobsCommand extends Command
{
    protected $signature = 'jobs:standardize {--all : Redo every job, not only those made with older rules}';

    protected $description = 'Standardize jobs and rebuild their matching keys (jobs made before this step, or with older rules)';

    public function handle(JobStandardizer $standardizer): int
    {
        $done = 0;

        Job::query()
            ->when(! $this->option('all'), fn ($q) => $q->where(fn ($w) => $w->whereNull('standardization_version')->orWhere('standardization_version', '<', JobStandardizer::VERSION)))
            ->with('rawPost')
            ->chunkById(200, function ($jobs) use ($standardizer, &$done) {
                foreach ($jobs as $job) {
                    $standardizer->standardize($job);
                    $done++;
                }
            });

        $this->info("Standardized {$done} ".($done === 1 ? 'job' : 'jobs').'.');

        return self::SUCCESS;
    }
}
