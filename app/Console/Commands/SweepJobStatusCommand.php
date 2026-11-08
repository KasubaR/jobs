<?php

namespace App\Console\Commands;

use App\Models\Job;
use App\Processing\JobStatusService;
use Illuminate\Console\Command;

class SweepJobStatusCommand extends Command
{
    protected $signature = 'jobs:sweep-status';

    protected $description = 'Re-check every job\'s status: expire jobs whose end date has passed, close jobs a full-list source stopped listing, and reopen ones that came back';

    public function handle(JobStatusService $status): int
    {
        $changes = $status->sweep();

        if (! $changes) {
            $this->info('No status changed.');
        }

        foreach ($changes as $change => $count) {
            $this->line(sprintf('%5d  %s', $count, $change));
        }

        $this->newLine();
        foreach (Job::query()->selectRaw('status, count(*) as c')->groupBy('status')->orderBy('status')->pluck('c', 'status') as $name => $count) {
            $this->line(sprintf('%-10s %d', $name, $count));
        }

        return self::SUCCESS;
    }
}
