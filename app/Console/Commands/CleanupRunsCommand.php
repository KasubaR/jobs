<?php

namespace App\Console\Commands;

use App\Collectors\RunCleaner;
use Illuminate\Console\Command;

class CleanupRunsCommand extends Command
{
    protected $signature = 'runs:cleanup';

    protected $description = 'Mark collection runs that never finished as failed';

    public function handle(RunCleaner $cleaner): int
    {
        $count = $cleaner->failStuckRuns();

        $this->info($count === 0 ? 'No stuck runs.' : "Marked {$count} stuck ".($count === 1 ? 'run' : 'runs').' as failed.');

        return self::SUCCESS;
    }
}
