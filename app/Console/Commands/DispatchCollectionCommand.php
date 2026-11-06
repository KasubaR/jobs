<?php

namespace App\Console\Commands;

use App\Jobs\CollectSource;
use App\Models\Source;
use Illuminate\Console\Command;

class DispatchCollectionCommand extends Command
{
    protected $signature = 'collect:dispatch';

    protected $description = 'Queue one background collection job for every approved, active source that is due';

    public function handle(): int
    {
        $due = Source::collectable()->due()->get(['id', 'name']);

        if ($due->isEmpty()) {
            $this->info('No approved, active sources are due.');

            return self::SUCCESS;
        }

        foreach ($due as $source) {
            // Unique per source: if one is already waiting or running, this adds nothing.
            CollectSource::dispatch($source->id, 'scheduled');
            $this->line("Queued {$source->name}");
        }

        $this->info("{$due->count()} due ".($due->count() === 1 ? 'source' : 'sources').' sent to the queue (one already waiting or running is not queued twice).');

        return self::SUCCESS;
    }
}
