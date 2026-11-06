<?php

namespace App\Collectors;

use App\Models\Source;
use App\Models\SourceRun;
use Illuminate\Support\Carbon;

/**
 * Decides when a source is next collected.
 *
 *  - success: wait one full frequency period (daily / weekly / monthly)
 *  - failure: back off (1h, 6h, 24h, ...) so a broken source never hammers a site
 *  - after max_consecutive_failures in a row the source is switched off and
 *    needs a person to turn it back on
 */
class SourceScheduler
{
    public function recordResult(Source $source, SourceRun $run): void
    {
        if ($run->status === 'failed') {
            $this->recordFailure($source, $run);

            return;
        }

        $source->forceFill([
            'consecutive_failures' => 0,
            'next_collection_at' => $this->nextAfterSuccess($source->collection_frequency),
        ])->save();
    }

    public function nextAfterSuccess(string $frequency, ?Carbon $from = null): Carbon
    {
        $from = ($from ?? now())->copy();

        return match ($frequency) {
            'every_6_hours' => $from->addHours(6),
            'every_12_hours' => $from->addHours(12),
            'every_2_days' => $from->addDays(2),
            'every_3_days' => $from->addDays(3),
            'weekly' => $from->addWeek(),
            'monthly' => $from->addMonth(),
            default => $from->addDay(), // daily
        };
    }

    private function recordFailure(Source $source, SourceRun $run): void
    {
        $failures = $source->consecutive_failures + 1;
        $limit = (int) config('job_intel.max_consecutive_failures');
        $steps = config('job_intel.retry_backoff_minutes');
        $minutes = $steps[min($failures, count($steps)) - 1];

        $source->consecutive_failures = $failures;
        $source->next_collection_at = now()->addMinutes($minutes);

        if ($failures >= $limit) {
            $source->is_active = false;
            $source->decisionNote = "Switched off automatically after {$failures} failed runs in a row. Last error: ".mb_substr((string) $run->error_message, 0, 300);
        }

        $source->save();
    }
}
