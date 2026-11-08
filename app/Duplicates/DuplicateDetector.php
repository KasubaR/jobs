<?php

namespace App\Duplicates;

use App\Models\Job;
use App\Processing\JobStatusService;
use Illuminate\Support\Facades\DB;

/**
 * Finds jobs that are the same vacancy and links them to one main job.
 *
 * 1. Candidates: only jobs that share the employer, the title, the application link or the exact text, and were
 *    posted within the window. Nothing is compared with everything.
 * 2. Each candidate is scored (DuplicateScorer). Certain matches and scores at or above the automatic line are linked.
 *    Scores between the two lines are stored as "possible" for a person. Below that they are different vacancies.
 * 3. A pair a person rejected is never proposed or linked again; a pair a person confirmed is linked again whenever it comes up.
 *
 * A link made by the system stays only while the pair would still qualify for it: when an ad is edited and no longer
 * matches, the copy is released and stands alone. The copy keeps its own source, link and dates.
 */
class DuplicateDetector
{
    /** Who a reader would rather see as "the" ad: the employer's own site first, boards last. */
    private const SOURCE_RANK = ['company' => 1, 'government' => 2, 'ngo' => 3, 'education' => 4, 'job_board' => 5, 'aggregator' => 6, 'media' => 7];

    public function __construct(private DuplicateScorer $scorer, private JobStatusService $status) {}

    /** @return array{linked:int, possible:int, released:int} */
    public function detect(Job $job): array
    {
        $cfg = config('job_intel.duplicates');
        $out = ['linked' => 0, 'possible' => 0, 'released' => 0];

        if ($job->status === 'rejected') {
            return $out; // a job turned down is not matched with anything
        }

        $job->loadMissing('rawPost');
        $out['released'] += $this->verify($job) ? 1 : 0;
        $this->refreshPairs($job);

        foreach ($this->candidates($job) as $other) {
            if ($this->root($other)->id === $this->root($job)->id) {
                continue; // already in the same group
            }

            [$a, $b] = $job->id < $other->id ? [$job, $other] : [$other, $job];
            $pair = $this->pair($a, $b);

            if ($pair?->outcome === 'rejected') {
                continue;
            }

            $result = $this->scorer->score($a, $b);

            if ($pair?->outcome === 'confirmed') {
                $this->link($a, $b, 'a person confirmed it');
                $out['linked']++;

                continue;
            }

            if ($this->qualifies($result)) {
                $this->record($a, $b, $result, 'auto');
                $this->link($a, $b, $this->why($result));
                $out['linked']++;
            } elseif ($result['score'] >= $cfg['possible']) {
                $this->record($a, $b, $result, 'possible');
                $out['possible']++;
            } elseif ($pair && in_array($pair->outcome, ['auto', 'possible'], true)) {
                DB::table('job_duplicate_candidates')->where('id', $pair->id)->delete(); // no longer looks like one
            }
        }

        return $out;
    }

    /** A person says these two are the same vacancy. $mainId picks the main one; otherwise the usual preference decides. */
    public function confirm(object $pair, ?int $mainId = null): void
    {
        DB::table('job_duplicate_candidates')->where('id', $pair->id)->update(['outcome' => 'confirmed', 'decided_by' => 'person', 'decided_at' => now(), 'updated_at' => now()]);
        $this->link(Job::findOrFail($pair->job_a_id), Job::findOrFail($pair->job_b_id), 'a person confirmed it', $mainId, 'person');
    }

    /** A person says these two are different vacancies. They are not proposed again; a link between them is undone. */
    public function reject(object $pair): void
    {
        DB::table('job_duplicate_candidates')->where('id', $pair->id)->update(['outcome' => 'rejected', 'decided_by' => 'person', 'decided_at' => now(), 'updated_at' => now()]);

        foreach ([[$pair->job_a_id, $pair->job_b_id], [$pair->job_b_id, $pair->job_a_id]] as [$child, $main]) {
            $job = Job::find($child);
            if ($job && (int) $job->duplicate_of_job_id === (int) $main) {
                $this->status->release($job, 'A person said this is a different vacancy.', 'person');
            }
        }
    }

    /** From a job's own page: "this is not a copy". */
    public function separate(Job $job): void
    {
        $main = $job->duplicate_of_job_id;
        abort_if($main === null, 422, 'This job is not marked as a copy.');

        [$a, $b] = $job->id < $main ? [$job->id, $main] : [$main, $job->id];
        $now = now();
        DB::table('job_duplicate_candidates')->updateOrInsert(['job_a_id' => $a, 'job_b_id' => $b], [
            'score' => DB::table('job_duplicate_candidates')->where(['job_a_id' => $a, 'job_b_id' => $b])->value('score') ?? 0,
            'reason' => 'score', 'outcome' => 'rejected', 'decided_by' => 'person', 'decided_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->status->release($job, 'A person said this is a different vacancy.', 'person');
    }

    /** The job at the top of a copy's chain. */
    public function root(Job $job): Job
    {
        for ($hops = 0; $hops < 10 && $job->duplicate_of_job_id !== null; $hops++) {
            $parent = Job::find($job->duplicate_of_job_id);
            if ($parent === null) {
                break;
            }
            $job = $parent;
        }

        return $job;
    }

    /** A copy that no longer qualifies (its ad changed) is released. Returns whether it was. */
    private function verify(Job $job): bool
    {
        if ($job->duplicate_of_job_id === null) {
            return false;
        }

        $main = $this->root($job);
        [$a, $b] = $job->id < $main->id ? [$job, $main] : [$main, $job];

        if ($this->pair($a, $b)?->outcome === 'confirmed') {
            return false; // a person's decision stands
        }

        if ($this->qualifies($this->scorer->score($a, $b))) {
            return false;
        }

        $this->status->release($job, 'The ad changed and no longer matches the main ad.');

        return true;
    }

    /**
     * Pairs stored earlier for this job are scored again, because the ad may have changed: one that no longer looks
     * like a match is dropped, even when the new ad shares no key with the old partner and so is not a candidate now.
     * A pair a person decided is never touched.
     */
    private function refreshPairs(Job $job): void
    {
        $rows = DB::table('job_duplicate_candidates')->whereIn('outcome', ['auto', 'possible'])
            ->where(fn ($q) => $q->where('job_a_id', $job->id)->orWhere('job_b_id', $job->id))->get();

        foreach ($rows as $row) {
            $a = $row->job_a_id === $job->id ? $job : Job::with('rawPost')->find($row->job_a_id);
            $b = $row->job_b_id === $job->id ? $job : Job::with('rawPost')->find($row->job_b_id);

            if (! $a || ! $b || $a->status === 'rejected' || $b->status === 'rejected') {
                DB::table('job_duplicate_candidates')->where('id', $row->id)->delete();

                continue;
            }

            $result = $this->scorer->score($a, $b);

            if ($result['score'] < config('job_intel.duplicates.possible') && ! $result['exact']) {
                DB::table('job_duplicate_candidates')->where('id', $row->id)->delete();

                continue;
            }

            DB::table('job_duplicate_candidates')->where('id', $row->id)->update([
                'score' => min(100, $result['score']), 'reason' => $result['reason'], 'breakdown' => json_encode($result['breakdown']),
                'outcome' => $this->qualifies($result) ? 'auto' : 'possible', 'updated_at' => now(),
            ]);
        }
    }

    private function qualifies(array $result): bool
    {
        return $result['exact'] || ($result['score'] >= config('job_intel.duplicates.auto') && ! $result['no_auto']);
    }

    private function why(array $result): string
    {
        return match ($result['reason']) {
            'exact_url' => 'the same application link',
            'exact_text' => 'the same ad text from the same employer',
            default => "a similarity score of {$result['score']}",
        };
    }

    /** @return \Illuminate\Support\Collection<int, Job> */
    private function candidates(Job $job)
    {
        $days = (int) config('job_intel.duplicates.window_days');

        $query = Job::query()->with('rawPost')->where('id', '!=', $job->id)->where('status', '!=', 'rejected')
            ->when($job->match_date, fn ($q) => $q->whereBetween('match_date', [$job->match_date->copy()->subDays($days), $job->match_date->copy()->addDays($days)]))
            ->where(function ($w) use ($job) {
                $any = false;
                foreach (['match_employer', 'match_title', 'match_url', 'content_hash_norm'] as $key) {
                    if ($job->{$key} !== null) {
                        $w->orWhere($key, $job->{$key});
                        $any = true;
                    }
                }
                if (! $any) {
                    $w->whereRaw('1 = 0'); // nothing to match on
                }
            });

        return $query->limit(200)->get();
    }

    private function pair(Job $a, Job $b): ?object
    {
        return DB::table('job_duplicate_candidates')->where('job_a_id', $a->id)->where('job_b_id', $b->id)->first();
    }

    private function record(Job $a, Job $b, array $result, string $outcome): void
    {
        $now = now();
        DB::table('job_duplicate_candidates')->updateOrInsert(['job_a_id' => $a->id, 'job_b_id' => $b->id], [
            'score' => min(100, $result['score']), 'reason' => $result['reason'], 'breakdown' => json_encode($result['breakdown']),
            'outcome' => $outcome, 'decided_by' => 'system', 'decided_at' => $outcome === 'auto' ? $now : null, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** Joins the groups of two jobs under one main job. */
    private function link(Job $a, Job $b, string $why, ?int $mainId = null, string $by = 'system'): void
    {
        $ra = $this->root($a);
        $rb = $this->root($b);

        if ($ra->id === $rb->id) {
            return;
        }

        $main = match (true) {
            $mainId === $ra->id => $ra,
            $mainId === $rb->id => $rb,
            default => $this->preferred($ra, $rb),
        };
        $child = $main->id === $ra->id ? $rb : $ra;

        DB::transaction(function () use ($main, $child, $why, $by) {
            Job::where('duplicate_of_job_id', $child->id)->update(['duplicate_of_job_id' => $main->id]); // its copies follow it
            $this->status->markDuplicate($child, $main, "Same vacancy as job #{$main->id}: {$why}.", $by);
        });
    }

    /** The job a reader would rather see as the main one (the suggestion shown when a person decides). */
    public function preferred(Job $x, Job $y): Job
    {
        $key = function (Job $j) {
            $j->loadMissing('rawPost.source');

            return [self::SOURCE_RANK[$j->rawPost?->source?->source_type] ?? 9, $j->first_seen_at?->getTimestamp() ?? PHP_INT_MAX, $j->id];
        };

        return $key($x) <= $key($y) ? $x : $y;
    }
}
