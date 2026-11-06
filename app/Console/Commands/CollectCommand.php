<?php

namespace App\Console\Commands;

use App\Collectors\CollectorRegistry;
use App\Models\Source;
use Illuminate\Console\Command;
use Throwable;

class CollectCommand extends Command
{
    protected $signature = 'collect:run {source? : Source id or name. Runs it now, even if not due.}';

    protected $description = 'Collect new job ads from approved, active sources that are due';

    public function handle(CollectorRegistry $registry): int
    {
        $query = Source::query()->where('is_active', true)->where('access_status', 'approved');
        $named = $this->argument('source');

        if ($named) {
            $query->where(fn ($q) => $q->where('id', $named)->orWhere('name', $named));
        } else {
            // Manual sources are never collected on a schedule; ads are pasted in by hand.
            $query->where('collection_method', '!=', 'manual');
            $query->where(fn ($q) => $q->whereNull('next_collection_at')->orWhere('next_collection_at', '<=', now()));
        }

        $sources = $query->get();

        if ($sources->isEmpty()) {
            $this->warn($named ? 'No approved, active source matches.' : 'No approved, active sources are due.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($sources as $source) {
            // One source failing must never stop the others.
            try {
                $run = $registry->for($source)->collect($source, $named ? 'manual' : 'scheduled');
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
