<?php

namespace Tests\Feature;

use App\Jobs\ProcessRawJobPost;
use App\Models\Job;
use App\Models\RawJobPost;
use App\Models\Source;
use App\Models\SourceRun;
use App\Processing\JobStatusService;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/** Phase 6, step 2: a job's status comes from dates and the source, never from "still on the website". */
class JobStatusTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-08 12:00:00');
        config(['job_intel.collector_contact' => 'owner@example.com']);
        $this->seed(SourceSeeder::class);
    }

    private function source(bool $listsAll = false): Source
    {
        $source = Source::where('name', 'JobWeb Zambia')->first();
        $source->update(['lists_all_open_ads' => $listsAll]);

        return $source->fresh();
    }

    /** Stores an ad and runs it through the processor. $facts are the published job data. */
    private function job(array $facts = [], array $ad = [], ?Source $source = null): Job
    {
        static $n = 0;
        $n++;
        $source ??= $this->source();

        $post = RawJobPost::create($ad + [
            'source_id' => $source->id, 'source_url' => "https://x.test/job-{$n}", 'raw_title' => 'Budget Analyst',
            'raw_content' => 'Employer: Example Charity. Qualification: Diploma in Accounting. '.str_repeat('Prepare budgets for the programme. ', 8),
            'structured_data' => $facts ?: null, 'content_hash' => hash('sha256', "j{$n}"),
            'fetched_at' => now(), 'first_seen_at' => now()->subDays(3), 'last_seen_at' => now(),
        ]);

        ProcessRawJobPost::dispatchSync($post->id);

        return Job::where('raw_job_post_id', $post->id)->firstOrFail();
    }

    private function run_(Source $source, string $status = 'success', int $found = 10, string $when = '+0 days'): SourceRun
    {
        return SourceRun::create([
            'source_id' => $source->id, 'status' => $status, 'items_found' => $found,
            'started_at' => now()->modify($when), 'finished_at' => now()->modify($when),
        ]);
    }

    private function history(Job $job): array
    {
        return DB::table('job_status_changes')->where('job_id', $job->id)->orderBy('id')->get()
            ->map(fn ($r) => [$r->from_status, $r->to_status])->all();
    }

    // ---- no qualification: never published without a person ----

    private function blankQualification(): Job
    {
        return $this->job(['valid_through' => '2026-12-01', 'employer' => 'Example Charity'], ['raw_content' => 'Employer: Example Charity. '.str_repeat('Prepare budgets for the programme. ', 8)]);
    }

    public function test_an_ad_with_no_qualification_waits_for_a_person(): void
    {
        $job = $this->blankQualification();

        $this->assertNull($job->qualification_level);
        $this->assertSame('new', $job->status);
        $this->assertSame('Waiting for review: no qualification was found in the ad.', $job->status_reason);

        // the daily sweep does not publish it either
        app(\App\Processing\JobStatusService::class)->sweep();
        $this->assertSame('new', $job->fresh()->status);
    }

    public function test_marking_it_reviewed_or_publishing_it_releases_it(): void
    {
        $reviewed = $this->blankQualification();
        $reviewed->update(['review_status' => 'reviewed']); // what "Mark as reviewed" does
        app(\App\Processing\JobStatusService::class)->evaluate($reviewed->refresh());
        $this->assertSame('published', $reviewed->fresh()->status);

        $published = $this->blankQualification();
        app(\App\Processing\JobStatusService::class)->decide($published, 'published');
        $this->assertSame('published', $published->fresh()->status);
        app(\App\Processing\JobStatusService::class)->sweep();
        $this->assertSame('published', $published->fresh()->status); // the person's choice stands
    }

    // ---- from extraction ----

    public function test_a_good_open_ad_is_published_and_its_history_says_how(): void
    {
        $job = $this->job(['valid_through' => '2026-12-01', 'employer' => 'Example Charity']);

        $this->assertSame('published', $job->status);
        $this->assertSame('system', $job->status_by);
        $this->assertSame([[null, 'new'], ['new', 'published']], $this->history($job));
        $this->assertFalse($job->end_assumed);
        $this->assertSame('2026-12-01', $job->ends_on->toDateString());
    }

    public function test_a_thin_ad_stays_new_until_a_person_looks(): void
    {
        $job = $this->job([], ['raw_content' => 'Short.']);

        $this->assertSame('needs_review', $job->review_status);
        $this->assertSame('new', $job->status);
        $this->assertStringContainsString('Waiting for review', $job->status_reason);
    }

    // ---- dates ----

    public function test_a_past_closing_date_expires_the_job_even_though_the_ad_is_still_online(): void
    {
        $job = $this->job(['valid_through' => '2026-11-07'], ['last_seen_at' => now()]); // seen again today

        $this->assertSame('expired', $job->status);
        $this->assertStringContainsString('closing date (2026-11-07) has passed', $job->status_reason);
    }

    public function test_the_closing_day_itself_is_still_open(): void
    {
        $this->assertSame('published', $this->job(['valid_through' => '2026-11-08'])->status);
    }

    public function test_an_ad_with_no_closing_date_gets_an_assumed_end_and_is_not_open_forever(): void
    {
        $recent = $this->job([], ['first_seen_at' => now()->subDays(10)]);
        $old = $this->job([], ['first_seen_at' => now()->subDays(60)]);

        $this->assertSame('published', $recent->status);
        $this->assertTrue($recent->end_assumed);
        $this->assertSame('2026-12-13', $recent->ends_on->toDateString()); // 10 days ago + 45
        $this->assertSame('expired', $old->status);
        $this->assertStringContainsString('No closing date was given; the assumed end', $old->status_reason);
    }

    public function test_the_posted_date_is_used_for_the_assumed_end_before_first_seen(): void
    {
        $job = $this->job(['date_posted' => '2026-09-01'], ['first_seen_at' => now()->subDay()]);

        $this->assertSame('2026-10-16', $job->ends_on->toDateString());
        $this->assertSame('expired', $job->status);
    }

    public function test_the_assumed_age_is_a_setting(): void
    {
        config(['job_intel.assumed_ad_age_days' => 5]);

        $this->assertSame('expired', $this->job([], ['first_seen_at' => now()->subDays(10)])->status);
    }

    public function test_a_given_closing_date_replaces_the_assumed_end_when_the_ad_changes(): void
    {
        $job = $this->job([], ['source_url' => 'https://x.test/same', 'first_seen_at' => now()->subDays(60)]);
        $this->assertSame('expired', $job->status);

        $v2 = RawJobPost::create([
            'source_id' => $job->rawPost->source_id, 'source_url' => 'https://x.test/same', 'raw_title' => 'Budget Analyst',
            'raw_content' => 'Employer: Example Charity. Qualification: Diploma in Accounting. '.str_repeat('Prepare budgets. ', 20), 'structured_data' => ['valid_through' => '2026-12-20'],
            'content_hash' => hash('sha256', 'v2'), 'fetched_at' => now(), 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        ProcessRawJobPost::dispatchSync($v2->id);

        $fresh = $job->fresh();
        $this->assertSame('published', $fresh->status); // a date the ad now gives wins over the assumption
        $this->assertFalse($fresh->end_assumed);
    }

    public function test_the_daily_sweep_expires_jobs_as_time_passes(): void
    {
        $job = $this->job(['valid_through' => '2026-11-20']);
        $this->assertSame('published', $job->status);

        Carbon::setTestNow('2026-11-21 04:00:00');
        $this->artisan('jobs:sweep-status')->expectsOutputToContain('published to expired')->assertSuccessful();

        $this->assertSame('expired', $job->fresh()->status);
        $this->assertSame([[null, 'new'], ['new', 'published'], ['published', 'expired']], $this->history($job));
    }

    public function test_a_sweep_with_nothing_to_change_adds_no_history(): void
    {
        $job = $this->job(['valid_through' => '2026-12-01']);
        $before = DB::table('job_status_changes')->count();

        $this->assertSame([], app(JobStatusService::class)->sweep());
        $this->assertSame([], app(JobStatusService::class)->sweep());

        $this->assertSame($before, DB::table('job_status_changes')->count());
        $this->assertSame('published', $job->fresh()->status);
    }

    // ---- not a vacancy ----

    public function test_a_tender_notice_is_rejected_but_a_tender_clerk_is_a_job(): void
    {
        $tender = $this->job([], ['raw_title' => 'Tender for the supply of office furniture']);
        $clerk = $this->job([], ['raw_title' => 'Tender Clerk']);
        $bid = $this->job([], ['raw_title' => 'Invitation to Bid: Road works']);

        $this->assertSame('rejected', $tender->status);
        $this->assertStringContainsString('tender or notice', $tender->status_reason);
        $this->assertSame('rejected', $bid->status);
        $this->assertSame('published', $clerk->status);
    }

    // ---- the source ----

    public function test_a_full_list_source_closes_an_ad_missing_from_two_successful_runs(): void
    {
        $source = $this->source(true);
        $job = $this->job(['valid_through' => '2026-12-31'], ['last_seen_at' => now()->subDays(5)], $source);
        $this->run_($source, 'success', 10, '-3 days');
        $this->run_($source, 'success', 10, '-1 day');

        $this->artisan('jobs:sweep-status')->expectsOutputToContain('published to closed')->assertSuccessful();

        $fresh = $job->fresh();
        $this->assertSame('closed', $fresh->status);
        $this->assertStringContainsString('missing from the last 2 successful runs', $fresh->status_reason);
    }

    public function test_one_missing_run_is_not_enough(): void
    {
        $source = $this->source(true);
        $job = $this->job(['valid_through' => '2026-12-31'], ['last_seen_at' => now()->subDays(5)], $source);
        $this->run_($source, 'success', 10, '-1 day');

        app(JobStatusService::class)->sweep();

        $this->assertSame('published', $job->fresh()->status);
    }

    public function test_failed_partial_and_empty_runs_prove_nothing(): void
    {
        $source = $this->source(true);
        $job = $this->job(['valid_through' => '2026-12-31'], ['last_seen_at' => now()->subDays(5)], $source);
        $this->run_($source, 'failed', 0, '-4 days');
        $this->run_($source, 'partial', 3, '-3 days');
        $this->run_($source, 'success', 0, '-2 days'); // "found nothing" looks like a broken page, not 'all ads closed'
        $this->run_($source, 'success', 10, '-1 day');

        app(JobStatusService::class)->sweep();

        $this->assertSame('published', $job->fresh()->status);
    }

    public function test_a_source_that_does_not_list_everything_never_closes_ads(): void
    {
        $source = $this->source(false); // like JobWeb: its feed shows only the latest ads
        $job = $this->job(['valid_through' => '2026-12-31'], ['last_seen_at' => now()->subDays(5)], $source);
        foreach (['-4 days', '-3 days', '-2 days', '-1 day'] as $when) {
            $this->run_($source, 'success', 10, $when);
        }

        app(JobStatusService::class)->sweep();

        $this->assertSame('published', $job->fresh()->status);
    }

    public function test_an_ad_seen_in_a_later_run_is_not_missing(): void
    {
        $source = $this->source(true);
        $job = $this->job(['valid_through' => '2026-12-31'], ['last_seen_at' => now()->subHours(2)], $source);
        $this->run_($source, 'success', 10, '-5 days');
        $this->run_($source, 'success', 10, '-4 days'); // before it was last seen

        app(JobStatusService::class)->sweep();

        $this->assertSame('published', $job->fresh()->status);
    }

    public function test_a_closed_job_reopens_when_the_source_lists_it_again(): void
    {
        $source = $this->source(true);
        $job = $this->job(['valid_through' => '2026-12-31'], ['last_seen_at' => now()->subDays(5)], $source);
        $this->run_($source, 'success', 10, '-3 days');
        $this->run_($source, 'success', 10, '-1 day');
        app(JobStatusService::class)->sweep();
        $this->assertSame('closed', $job->fresh()->status);

        $job->rawPost->update(['last_seen_at' => now()]); // seen again in a newer run

        app(JobStatusService::class)->sweep();

        $this->assertSame('published', $job->fresh()->status);
        $this->assertSame([[null, 'new'], ['new', 'published'], ['published', 'closed'], ['closed', 'published']], $this->history($job));
    }

    public function test_an_older_version_seen_again_counts_as_the_ad_being_listed(): void
    {
        $source = $this->source(true);
        $job = $this->job(['valid_through' => '2026-12-31'], ['source_url' => 'https://x.test/v', 'last_seen_at' => now()->subDays(5)], $source);
        RawJobPost::create([ // an older edit of the same address, touched by a recent run
            'source_id' => $source->id, 'source_url' => 'https://x.test/v', 'raw_title' => 'Budget Analyst', 'raw_content' => 'old',
            'content_hash' => hash('sha256', 'older'), 'fetched_at' => now(), 'first_seen_at' => now()->subDays(9), 'last_seen_at' => now(),
        ]);
        $this->run_($source, 'success', 10, '-3 days');
        $this->run_($source, 'success', 10, '-1 day');

        app(JobStatusService::class)->sweep();

        $this->assertSame('published', $job->fresh()->status);
    }

    // ---- people and duplicates ----

    public function test_a_person_can_reject_and_it_stays_rejected(): void
    {
        $job = $this->job(['valid_through' => '2026-12-31']);

        app(JobStatusService::class)->decide($job, 'rejected', 'A course advert');
        app(JobStatusService::class)->sweep();

        $fresh = $job->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('person', $fresh->status_by);
        $this->assertSame('A course advert', $fresh->status_reason);
    }

    public function test_a_person_can_publish_what_the_rules_rejected_and_the_dates_still_apply(): void
    {
        $job = $this->job(['valid_through' => '2026-11-20'], ['raw_title' => 'Tender for the supply of office furniture']);
        $this->assertSame('rejected', $job->status);

        app(JobStatusService::class)->decide($job, 'published', 'It is a real vacancy');
        $this->assertSame('published', $job->fresh()->status);

        app(JobStatusService::class)->sweep();
        $this->assertSame('published', $job->fresh()->status); // not rejected again

        Carbon::setTestNow('2026-11-25 04:00:00');
        app(JobStatusService::class)->sweep();
        $this->assertSame('expired', $job->fresh()->status); // but the closing date is still respected
    }

    public function test_a_duplicate_is_left_alone_by_the_sweep(): void
    {
        $job = $this->job(['valid_through' => '2026-11-01']); // would expire
        $job->update(['status' => 'duplicate']);

        $this->assertSame([], app(JobStatusService::class)->sweep());
        $this->assertNull(app(JobStatusService::class)->evaluate($job->fresh())); // also when asked about it directly
        $this->assertSame('duplicate', $job->fresh()->status);
    }

    public function test_an_unknown_status_is_refused(): void
    {
        $job = $this->job(['valid_through' => '2026-12-31']);

        $this->expectException(InvalidArgumentException::class);
        app(JobStatusService::class)->decide($job, 'open');
    }

    public function test_the_seven_statuses_are_the_ones_in_the_plan(): void
    {
        $this->assertSame(['new', 'processing', 'published', 'expired', 'closed', 'rejected', 'duplicate'], Job::STATUSES);
    }

    // ---- the source setting ----

    public function test_the_full_list_setting_is_off_by_default_and_saved_from_the_form(): void
    {
        $this->signIn();
        $source = $this->source();
        $this->assertFalse($source->lists_all_open_ads);

        $form = [
            'name' => $source->name, 'source_type' => $source->source_type, 'website_url' => $source->website_url,
            'job_listing_url' => $source->job_listing_url, 'collection_method' => $source->collection_method,
            'collector' => $source->collector, 'collection_frequency' => $source->collection_frequency,
            'min_interval_seconds' => $source->min_interval_seconds ?? 1, 'retain_raw' => '1',
        ];

        $this->put("/sources/{$source->id}", $form + ['lists_all_open_ads' => '1'])->assertRedirect("/sources/{$source->id}");
        $this->assertTrue($source->fresh()->lists_all_open_ads);

        $this->put("/sources/{$source->id}", $form + ['lists_all_open_ads' => '0']);
        $this->assertFalse($source->fresh()->lists_all_open_ads);

        $this->get("/sources/{$source->id}/edit")->assertOk()->assertSee('One run lists every ad that is currently open');
    }
}
