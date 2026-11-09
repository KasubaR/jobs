<?php

namespace Tests\Concerns;

use App\Models\Job;
use Illuminate\Support\Facades\DB;

/**
 * The rules the finished dataset must obey, whatever went into it. Run after any scenario: if one fails, the
 * dataset is not clean, however each step looked on its own.
 */
trait ChecksDataset
{
    protected function assertDatasetIsSound(): void
    {
        $jobs = Job::with('rawPost')->get()->keyBy('id');

        foreach ($jobs as $job) {
            $at = "job #{$job->id} ({$job->title})";

            // one of the seven statuses, with a reason
            $this->assertContains($job->status, Job::STATUSES, "{$at} has an unknown status");
            $this->assertNotNull($job->status_reason, "{$at} has a status but no reason");

            // its ad exists and belongs to it
            $this->assertNotNull($job->rawPost, "{$at} has no ad behind it");
            $this->assertSame($job->rawPost->source_url, $job->source_url, "{$at} points at a different address than its ad");

            // the history tells the story: it begins at "new" and ends at the status the job has now
            $history = DB::table('job_status_changes')->where('job_id', $job->id)->orderBy('id')->get();
            $this->assertNotEmpty($history, "{$at} has no status history");
            $this->assertNull($history->first()->from_status, "{$at}: history does not begin at the start");
            $this->assertSame('new', $history->first()->to_status, "{$at}: history does not begin with new");
            $this->assertSame($job->status, $history->last()->to_status, "{$at}: history does not end at the current status");
            foreach ($history->slice(1) as $i => $row) {
                $this->assertSame($history[$i - 1]->to_status, $row->from_status, "{$at}: history has a gap");
            }

            // a copy points at one main job, directly; the main is not itself a copy and not rejected
            if ($job->duplicate_of_job_id !== null) {
                $main = $jobs[$job->duplicate_of_job_id] ?? null;
                $this->assertNotNull($main, "{$at} is a copy of a job that does not exist");
                $this->assertNotSame($job->id, $main->id, "{$at} is a copy of itself");
                $this->assertNull($main->duplicate_of_job_id, "{$at} points at a job that is itself a copy (a chain)");
                $this->assertNotSame('duplicate', $main->status, "{$at}: its main is marked duplicate");
                $this->assertNotSame('rejected', $main->status, "{$at}: its main is rejected");
            }
            $this->assertSame($job->duplicate_of_job_id !== null, $job->status === 'duplicate', "{$at}: 'duplicate' and a main job must go together");

            // standard values agree with each other and refer to things that exist
            if ($job->qualification_label !== null) {
                $this->assertNotNull($job->qualification_level, "{$at} has a qualification label but no level");
            }
            if ($job->experience_min_months !== null && $job->experience_max_months !== null) {
                $this->assertLessThanOrEqual($job->experience_max_months, $job->experience_min_months, "{$at}: experience minimum is above its maximum");
            }
            if ($job->location_id !== null) {
                $this->assertTrue(DB::table('locations')->where('id', $job->location_id)->exists(), "{$at}: unknown place");
            }
            if ($job->employer_id !== null) {
                $this->assertTrue(DB::table('employers')->where('id', $job->employer_id)->exists(), "{$at}: unknown employer");
                $this->assertNotNull($job->employer_text, "{$at} has an employer record but no employer text");
            }
            $this->assertNotNull($job->standardization_version, "{$at} was never standardized");
            $this->assertNotNull($job->match_title, "{$at} has no title key");
            if ($job->ends_on !== null && $job->closing_date !== null) {
                $this->assertTrue($job->ends_on->equalTo($job->closing_date), "{$at}: its end date is not its closing date");
            }
            $this->assertSame($job->closing_date === null, (bool) $job->end_assumed, "{$at}: assumed end and closing date disagree");
        }

        // every ad that was processed has exactly one job (one per address of a source); failed ones have none
        $this->assertSame(0, DB::table('raw_job_posts')->where('processing_status', 'failed')->count(), 'an ad failed processing');
        $perAddress = DB::table('jobs')->join('raw_job_posts as r', 'r.id', '=', 'jobs.raw_job_post_id')
            ->selectRaw('r.source_id, r.source_url, count(*) as n')->groupBy('r.source_id', 'r.source_url')->get();
        $this->assertTrue($perAddress->every(fn ($r) => (int) $r->n === 1), 'an ad address has more than one job');

        // pairs: ordered, with a decided outcome the data agrees with
        foreach (DB::table('job_duplicate_candidates')->get() as $pair) {
            $this->assertLessThan($pair->job_b_id, $pair->job_a_id, 'a pair is not stored with the lower id first');
            $this->assertContains($pair->outcome, ['auto', 'possible', 'confirmed', 'rejected']);
            $this->assertGreaterThanOrEqual(0, $pair->score);
            $this->assertLessThanOrEqual(100, $pair->score);
            if ($pair->outcome === 'possible') {
                $a = $jobs[$pair->job_a_id];
                $b = $jobs[$pair->job_b_id];
                $this->assertFalse($a->duplicate_of_job_id === $b->id || $b->duplicate_of_job_id === $a->id, 'a pair still waiting for a decision is already linked');
            }
        }
    }

    /** A copy of what an ad stored, to prove later that nothing changed it. */
    protected function snapshotRawPosts(): array
    {
        return DB::table('raw_job_posts')->orderBy('id')->get(['id', 'source_id', 'source_url', 'raw_title', 'raw_content', 'structured_data', 'content_hash'])
            ->map(fn ($r) => (array) $r)->all();
    }
}
