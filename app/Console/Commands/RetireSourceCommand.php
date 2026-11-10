<?php

namespace App\Console\Commands;

use App\Duplicates\DuplicateDetector;
use App\Models\Job;
use App\Models\Source;
use App\Standardization\Text;
use Illuminate\Console\Command;

/**
 * For a source that is no longer used: marks its ads as copies of the same vacancy on another source, so the vacancy
 * is counted once. Nothing is deleted; "This is not a copy" on a job's page undoes any of it.
 *
 * Built for a source whose titles carry the employer ("Training Officer at BURN") and whose ads have no employer of
 * their own, which is why the scorer could not pair them. Only an exact match counts: the job's title must be the other
 * ad's title plus " at " plus that ad's employer. An ad with no such single twin is left as it is and listed.
 */
class RetireSourceCommand extends Command
{
    protected $signature = 'jobs:retire-source {source : the source id or name} {--dry-run : list what would change, change nothing}';

    protected $description = 'Mark a retired source\'s ads as copies of the same vacancy on another source';

    public function handle(DuplicateDetector $detector): int
    {
        $arg = (string) $this->argument('source');
        $matches = ctype_digit($arg) ? Source::whereKey((int) $arg)->get() : Source::where('name', 'like', "%{$arg}%")->get();

        if ($matches->count() !== 1) {
            $this->error($matches->isEmpty() ? "No source matches \"{$arg}\"." : 'More than one source matches: '.$matches->pluck('name')->implode(', ').'. Use the id.');

            return self::FAILURE;
        }
        $source = $matches->first();
        $source->is_active && $this->warn("{$source->name} is still switched on and will keep collecting. Deactivate it on the Sources page.");

        $retiring = Job::whereHas('rawPost', fn ($q) => $q->where('source_id', $source->id))
            ->whereNotIn('status', ['duplicate', 'rejected'])->orderBy('id')->get();

        $linked = [];
        $left = [];

        foreach ($retiring as $job) {
            $twins = $this->twins($job, $source);
            if ($twins->count() === 1) {
                $linked[] = [$job, $twins->first()];
            } else {
                $left[] = [$job, $twins->count() > 1 ? 'more than one possible twin' : 'no twin on another source'];
            }
        }

        foreach ($linked as [$job, $twin]) {
            $this->line(sprintf('  #%d  %s  =>  copy of #%d', $job->id, mb_strimwidth((string) $job->title, 0, 60, '…'), $twin->id));
            $this->option('dry-run') || $detector->markCopyOf($job->fresh(), $twin->fresh(), "{$source->name} was retired and this vacancy is on another source");
        }
        foreach ($left as [$job, $why]) {
            $this->line(sprintf('  #%d  %s  left as it is (%s)', $job->id, mb_strimwidth((string) $job->title, 0, 60, '…'), $why));
        }

        $this->info(($this->option('dry-run') ? 'Would mark ' : 'Marked ').count($linked).' as copies; '.count($left).' left as they are.');

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int, Job> other sources' counted ads whose title plus " at " plus employer is this job's title */
    private function twins(Job $job, Source $retired)
    {
        $title = (string) $job->title;
        $found = collect();
        $offset = 0;

        while (($pos = mb_stripos($title, ' at ', $offset)) !== false) {
            $left = trim(mb_substr($title, 0, $pos));
            $right = Text::employerKey(trim(mb_substr($title, $pos + 4)));
            $offset = $pos + 1;

            if ($left === '' || $right === '') {
                continue;
            }

            Job::where('title', $left)->whereNotIn('status', ['duplicate', 'rejected'])
                ->whereHas('rawPost', fn ($q) => $q->where('source_id', '!=', $retired->id))
                ->get()
                ->each(function (Job $candidate) use ($right, $found) {
                    Text::employerKey($candidate->employer_text) === $right && $found->put($candidate->id, $candidate);
                });
        }

        return $found->values();
    }
}
