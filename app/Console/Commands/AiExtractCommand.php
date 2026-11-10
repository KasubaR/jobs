<?php

namespace App\Console\Commands;

use App\Ai\AiClient;
use App\Ai\Extraction\AiEligibility;
use App\Jobs\ExtractJobQualifications;
use App\Models\Job;
use Illuminate\Console\Command;

class AiExtractCommand extends Command
{
    protected $signature = 'ai:extract {--limit=20 : most vacancies to send} {--job= : one vacancy, even if the rules found something} {--dry-run : list what would be sent, send nothing}';

    protected $description = 'Ask the AI about vacancies the rules could not read (only sources that allow it)';

    public function handle(AiClient $ai, AiEligibility $eligibility): int
    {
        if (($why = $ai->unavailable()) && ! $this->option('dry-run')) {
            $this->error($why);

            return self::FAILURE;
        }

        if ($id = $this->option('job')) {
            $job = Job::with('rawPost.source')->find((int) $id);
            if ($job === null) {
                $this->error("No vacancy {$id}.");

                return self::FAILURE;
            }
            if ($reason = $eligibility->reason($job, true)) {
                $this->error("Vacancy {$job->id} cannot go to the AI: {$reason}");

                return self::FAILURE;
            }
            $jobs = collect([$job]);
        } else {
            $jobs = $eligibility->candidates(max(1, (int) $this->option('limit')));
        }

        foreach ($jobs as $job) {
            $this->line(sprintf('  #%d %s', $job->id, mb_strimwidth((string) $job->title, 0, 70, '…')));
            $this->option('dry-run') || ExtractJobQualifications::dispatch($job->id, (bool) $this->option('job'));
        }

        $this->info(($this->option('dry-run') ? 'Would send ' : 'Sent ').$jobs->count().' vacancies.'.($jobs->isEmpty() ? ' None are eligible: the source must allow AI, keep its text, and the rules must have found no qualification.' : ''));

        return self::SUCCESS;
    }
}
