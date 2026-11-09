<?php

namespace Tests\Feature;

use App\Jobs\ProcessRawJobPost;
use App\Models\Job;
use App\Models\RawJobPost;
use App\Processing\ExtractionProcessor;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\MakesJobs;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/** Phase 6, step 7: running the chain day to day: backfill, retry, and what the dashboard says about it. */
class ProcessingOperationTest extends TestCase
{
    use MakesJobs, RefreshDatabase, SignsIn;

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-08 12:00:00');
        config(['job_intel.collector_contact' => 'owner@example.com', 'job_intel.processing_enabled' => true]);
        $this->seed(SourceSeeder::class);
        $this->s = $this->sourceSet();
    }

    private function makeOutdated(Job $job): Job
    {
        $job->update(['extraction_version' => 0]);

        return $job->fresh();
    }

    // ---- processing:dispatch --outdated ----

    public function test_outdated_jobs_are_redone_and_up_to_date_ones_are_left_alone(): void
    {
        $old = $this->makeOutdated($this->ad($this->s['company']));
        $current = $this->ad($this->s['boardA'], ['employer' => 'Beta Foods Ltd'], ['raw_title' => 'Cook', 'raw_content' => '<p>'.self::cleanerBody().'</p>']);
        Queue::fake();

        $this->artisan('processing:dispatch', ['--outdated' => true])
            ->expectsOutputToContain('Redoing 1 job made with older rules')->expectsOutputToContain('Queued 1 pending ad')->assertSuccessful();

        Queue::assertPushed(ProcessRawJobPost::class, 1);
        Queue::assertPushed(ProcessRawJobPost::class, fn ($j) => $j->rawJobPostId === $old->raw_job_post_id);
        $this->assertSame('pending', RawJobPost::find($old->raw_job_post_id)->processing_status);
        $this->assertSame('processed', RawJobPost::find($current->raw_job_post_id)->processing_status);
    }

    public function test_redoing_a_job_updates_it_in_place_and_keeps_a_persons_decisions(): void
    {
        $job = $this->ad($this->s['company'], ['education' => "Bachelor's in Accounting"]);
        $job->update(['review_status' => 'reviewed', 'qualification_label' => null, 'qualification_level' => null, 'extraction_version' => 0]);
        $postId = $job->raw_job_post_id;
        $history = DB::table('job_status_changes')->where('job_id', $job->id)->count();

        $this->artisan('processing:dispatch', ['--outdated' => true])->assertSuccessful(); // the queue is "sync" in tests, so it runs now

        $this->assertSame(1, Job::count());
        $fresh = $job->fresh();
        $this->assertSame($postId, $fresh->raw_job_post_id);
        $this->assertSame(ExtractionProcessor::VERSION, (int) $fresh->extraction_version);
        $this->assertSame("Bachelor's Degree in Accounting", $fresh->qualification_label);
        $this->assertSame('reviewed', $fresh->review_status);
        $this->assertSame('published', $fresh->status);
        $this->assertSame($history, DB::table('job_status_changes')->where('job_id', $job->id)->count()); // no churn when nothing changed
        $this->assertSame('processed', RawJobPost::find($postId)->processing_status);

        $this->artisan('processing:dispatch', ['--outdated' => true])->expectsOutputToContain('Redoing 0 jobs')->assertSuccessful(); // nothing older is left
    }

    public function test_a_job_whose_ad_text_was_cleared_is_not_re_read(): void
    {
        $job = $this->makeOutdated($this->ad($this->s['company'], [], ['raw_content' => '<p>'.self::mineBody().'</p>']));
        $job->update(['location_text' => 'Solwezi (read from the text)']);
        $job->rawPost->update(['raw_content' => null, 'raw_purged_at' => now()]);
        Queue::fake();

        $this->artisan('processing:dispatch', ['--outdated' => true])
            ->expectsOutputToContain('Redoing 0 jobs')
            ->expectsOutputToContain('Left 1 job alone')->expectsOutputToContain('jobs:standardize --all')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame('processed', $job->rawPost->fresh()->processing_status);
        $this->assertSame('Solwezi (read from the text)', $job->fresh()->location_text); // what was read from the text is not wiped
    }

    public function test_processed_ads_are_not_touched_without_the_flag(): void
    {
        $this->makeOutdated($this->ad($this->s['company']));
        Queue::fake();

        $this->artisan('processing:dispatch')->expectsOutputToContain('Queued 0 pending ads')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    // ---- processing:dispatch --failed ----

    public function test_failed_ads_are_retried_and_their_error_is_cleared(): void
    {
        $post = RawJobPost::create([
            'source_id' => $this->s['company']->id, 'source_url' => 'https://x.test/failed', 'raw_title' => 'Mining Engineer', 'raw_content' => '<p>'.self::mineBody().'</p>',
            'content_hash' => hash('sha256', 'failed'), 'fetched_at' => now(), 'first_seen_at' => now(), 'last_seen_at' => now(),
            'processing_status' => 'failed', 'processing_error' => 'something went wrong',
        ]);
        $waiting = RawJobPost::create([
            'source_id' => $this->s['company']->id, 'source_url' => 'https://x.test/waiting', 'raw_title' => 'Cook', 'raw_content' => '<p>'.self::cleanerBody().'</p>',
            'content_hash' => hash('sha256', 'waiting'), 'fetched_at' => now(), 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        Queue::fake();

        $this->artisan('processing:dispatch', ['--failed' => true])->expectsOutputToContain('Retrying 1 failed ad')->expectsOutputToContain('Queued 2 pending ads')->assertSuccessful();

        Queue::assertPushed(ProcessRawJobPost::class, 2);
        $this->assertSame('pending', $post->fresh()->processing_status);
        $this->assertNull($post->fresh()->processing_error);
    }

    public function test_a_retried_ad_that_now_works_becomes_a_job(): void
    {
        $post = RawJobPost::create([
            'source_id' => $this->s['company']->id, 'source_url' => 'https://x.test/was-broken', 'raw_title' => 'Mining Engineer', 'raw_content' => '<p>'.self::mineBody().'</p>',
            'content_hash' => hash('sha256', 'wb'), 'fetched_at' => now(), 'first_seen_at' => now(), 'last_seen_at' => now(),
            'processing_status' => 'failed', 'processing_error' => 'temporary problem',
        ]);

        $this->artisan('processing:dispatch', ['--failed' => true])->assertSuccessful();

        $this->assertSame('processed', $post->fresh()->processing_status);
        $this->assertSame(1, Job::where('raw_job_post_id', $post->id)->count());
    }

    public function test_nothing_is_redone_while_processing_is_off(): void
    {
        config(['job_intel.processing_enabled' => false]);
        $job = $this->makeOutdated($this->ad($this->s['company']));
        $job->rawPost->update(['processing_status' => 'failed']);
        Queue::fake();

        $this->artisan('processing:dispatch', ['--outdated' => true, '--failed' => true])->expectsOutputToContain('Processing is switched off')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame('failed', $job->rawPost->fresh()->processing_status);
    }

    // ---- the dashboard ----

    public function test_the_dashboard_shows_jobs_by_status_each_linking_to_the_list(): void
    {
        $this->signIn();
        $this->ad($this->s['company']);
        $expired = $this->ad($this->s['boardA'], ['valid_through' => '2026-10-01', 'employer' => 'Beta Foods Ltd'], ['raw_title' => 'Cook', 'raw_content' => '<p>'.self::cleanerBody().'</p>']);
        $this->ad($this->s['boardB']); // a copy of the first

        $this->get('/')->assertOk()->assertSee('Jobs by status')->assertSee('3 jobs in the clean dataset')
            ->assertSee(route('jobs.index', ['status' => 'published']), false)->assertSee(route('jobs.index', ['status' => 'expired']), false)
            ->assertSee('Duplicate');
        $this->assertSame('expired', $expired->fresh()->status);
    }

    public function test_the_dashboard_says_so_when_there_are_no_jobs(): void
    {
        $this->signIn();

        $this->get('/')->assertOk()->assertSee('No jobs yet')->assertSee('0</b> possible duplicates', false);
    }

    public function test_the_dashboard_counts_what_waits_for_a_person(): void
    {
        $this->signIn();
        $this->ad($this->s['company'], ['employer' => null, 'locations' => null, 'valid_through' => null, 'date_posted' => null], ['raw_content' => '<p>Short.</p>']); // thin: to review
        $a = $this->ad($this->s['boardA'], ['education' => "Valid driver's licence", 'employer' => 'Foods One Ltd'], ['raw_title' => 'Cook', 'raw_content' => '<p>'.self::cleanerBody().'</p>']);
        $b = $this->ad($this->s['boardB'], ['education' => "VALID DRIVER'S LICENCE", 'employer' => 'Foods Two Ltd'], ['raw_title' => 'Driver', 'raw_content' => '<p>Drive the van. '.str_repeat('Keep the logbook up to date and report faults. ', 5).'</p>']);
        DB::table('job_duplicate_candidates')->insert(['job_a_id' => $a->id, 'job_b_id' => $b->id, 'score' => 75, 'reason' => 'score', 'outcome' => 'possible', 'created_at' => now(), 'updated_at' => now()]);

        $this->get('/')->assertOk()
            ->assertSee('<b>1</b> job to review', false)
            ->assertSee('<b>1</b> possible duplicate', false)
            ->assertSee('<b>1</b> value to map', false); // two jobs, the same text: one decision
    }

    public function test_the_dashboard_warns_when_ads_wait_and_processing_is_off(): void
    {
        $this->signIn();
        RawJobPost::create([
            'source_id' => $this->s['company']->id, 'source_url' => 'https://x.test/waiting', 'raw_title' => 'Cook', 'raw_content' => 'x', 'content_hash' => hash('sha256', 'w'),
            'fetched_at' => now(), 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        config(['job_intel.processing_enabled' => false]);
        $this->get('/')->assertSee('Processing is switched off')->assertSee('PROCESSING_ENABLED=true', false)->assertSee('processing:dispatch');

        config(['job_intel.processing_enabled' => true]);
        $this->get('/')->assertDontSee('Processing is switched off');
    }

    public function test_no_warning_when_processing_is_off_but_nothing_waits(): void
    {
        $this->signIn();
        config(['job_intel.processing_enabled' => false]);

        $this->get('/')->assertOk()->assertDontSee('Processing is switched off');
    }

    public function test_the_dashboard_tells_how_to_retry_failed_ads(): void
    {
        $this->signIn();
        RawJobPost::create([
            'source_id' => $this->s['company']->id, 'source_url' => 'https://x.test/f', 'raw_title' => 'Cook', 'raw_content' => 'x', 'content_hash' => hash('sha256', 'f'),
            'fetched_at' => now(), 'first_seen_at' => now(), 'last_seen_at' => now(), 'processing_status' => 'failed',
        ]);

        $this->get('/')->assertSee('failed during processing')->assertSee('processing:dispatch --failed');
    }
}
