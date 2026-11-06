<?php

namespace App\Console\Commands;

use App\Collectors\JobWebFeedCollector;
use App\Models\Source;
use Illuminate\Console\Command;
use Throwable;

class CollectCommand extends Command
{
    protected $signature = 'collect:run {source? : Source id or name (default: every approved, active source)}';

    protected $description = 'Collect new job ads from approved, active sources';

    public function handle(JobWebFeedCollector $rss): int
    {
        $query = Source::query()->where('is_active', true)->where('access_status', 'approved');

        if ($arg = $this->argument('source')) {
            $query->where(fn ($q) => $q->where('id', $arg)->orWhere('name', $arg));
        }

        $sources = $query->get();

        if ($sources->isEmpty()) {
            $this->warn('No approved, active sources to collect from.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($sources as $source) {
            // One source failing must never stop the others.
            try {
                $run = match ($source->collection_method) {
                    'rss' => $rss->collect($source),
                    default => throw new \RuntimeException("No collector for method '{$source->collection_method}'."),
                };
                $this->line("{$source->name}: {$run->status} (found {$run->items_found}, new {$run->items_new}, updated {$run->items_updated})");
                $failed = $failed || $run->status === 'failed';
            } catch (Throwable $e) {
                $this->error("{$source->name}: {$e->getMessage()}");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
