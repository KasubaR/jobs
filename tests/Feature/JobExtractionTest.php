<?php

namespace Tests\Feature;

use App\Collectors\JobWebFeedCollector;
use App\Jobs\ProcessRawJobPost;
use App\Models\Job;
use App\Models\RawJobPost;
use App\Models\Source;
use App\Processing\ExtractionProcessor;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Phase 6, step 1: a stored ad becomes one `jobs` record. Saved text only; nothing contacts a website. */
class JobExtractionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['job_intel.collector_contact' => 'owner@example.com']);
        $this->seed(SourceSeeder::class);
    }

    private function source(): Source
    {
        return Source::where('name', 'JobWeb Zambia')->first();
    }

    private function ad(array $attributes = []): RawJobPost
    {
        static $n = 0;
        $n++;

        return RawJobPost::create($attributes + [
            'source_id' => $this->source()->id, 'source_url' => "https://x.test/job-{$n}", 'raw_title' => 'Budget Analyst',
            'raw_content' => str_repeat('Prepare budgets for the programme. ', 8), 'content_hash' => hash('sha256', "job{$n}"),
            'fetched_at' => now(), 'first_seen_at' => now()->subDay(), 'last_seen_at' => now(),
        ]);
    }

    private function run_(RawJobPost $post): Job
    {
        ProcessRawJobPost::dispatchSync($post->id);

        return Job::where('raw_job_post_id', $post->id)->firstOrFail();
    }

    // ---- structured data ----

    public function test_published_job_data_fills_the_record_and_is_marked_as_such(): void
    {
        $post = $this->ad(['structured_data' => [
            'title' => 'Senior Budget Analyst', 'employer' => 'Example Charity',
            'locations' => [['locality' => 'Lusaka', 'region' => 'Lusaka Province', 'country' => 'ZM']],
            'date_posted' => '2026-11-01', 'valid_through' => '2026-11-30', 'employment_type' => ['FULL_TIME', 'CONTRACT'],
            'salary' => ['currency' => 'ZMW', 'min' => 12000, 'max' => 15000, 'unit' => 'MONTH'],
            'education' => 'bachelor degree', 'experience' => '36 months',
        ]]);

        $job = $this->run_($post);

        $this->assertSame('Senior Budget Analyst', $job->title);
        $this->assertSame('Example Charity', $job->employer_text);
        $this->assertSame('Lusaka, Lusaka Province', $job->location_text);
        $this->assertSame('2026-11-01', $job->posted_date->toDateString());
        $this->assertSame('2026-11-30', $job->closing_date->toDateString());
        $this->assertSame('FULL_TIME, CONTRACT', $job->employment_type);
        $this->assertSame('ZMW 12,000–15,000 per month', $job->salary_text);
        $this->assertSame(12000.0, $job->salary_min);
        $this->assertSame(15000.0, $job->salary_max);
        $this->assertSame('ZMW', $job->salary_currency);
        $this->assertSame('bachelor degree', $job->qualification_text);
        $this->assertSame('36 months', $job->experience_text);
        $this->assertSame('structured', $job->field_sources['employer_text']);
        $this->assertSame('structured', $job->field_sources['closing_date']);
        $this->assertSame(100, $job->confidence);
        $this->assertSame('auto', $job->review_status);
        $this->assertSame($post->source_url, $job->source_url);
        $this->assertSame('processed', $post->fresh()->processing_status);
    }

    public function test_published_data_beats_a_conflicting_date_in_the_text(): void
    {
        $post = $this->ad([
            'raw_content' => 'Closing date: 30 November 2026',
            'structured_data' => ['valid_through' => '2026-12-15'],
        ]);

        $this->assertSame('2026-12-15', $this->run_($post)->closing_date->toDateString());
    }

    // ---- text rules ----

    public function test_labelled_text_is_read_by_the_built_in_rules(): void
    {
        $post = $this->ad(['raw_content' => implode("\n", [
            '<p>Employer: Sample Mining Ltd</p>', '<p>Location: Solwezi</p>', '<p>Job type: Full time</p>',
            '<p>Closing date: 30th November, 2026</p>', '<p>Date posted: 3/11/2026</p>', '<p>Salary: K15,000 per month</p>',
            '<p>Minimum qualification: Bachelor of Science in Mining Engineering</p>', '<p>Experience: 5 years in a mine</p>',
            '<p>Apply online: https://apply.example.com/mining-engineer.</p>',
        ])]);

        $job = $this->run_($post);

        $this->assertSame('Sample Mining Ltd', $job->employer_text);
        $this->assertSame('Solwezi', $job->location_text);
        $this->assertSame('Full time', $job->employment_type);
        $this->assertSame('2026-11-30', $job->closing_date->toDateString());
        $this->assertSame('2026-11-03', $job->posted_date->toDateString());
        $this->assertSame('K15,000 per month', $job->salary_text);
        $this->assertNull($job->salary_min); // numbers are only taken from published, structured pay
        $this->assertSame('Bachelor of Science in Mining Engineering', $job->qualification_text);
        $this->assertSame('5 years in a mine', $job->experience_text);
        $this->assertSame('https://apply.example.com/mining-engineer', $job->application_url);
        $this->assertSame('rule', $job->field_sources['closing_date']);
    }

    public function test_a_requirements_bullet_without_a_label_gives_the_qualification(): void
    {
        $post = $this->ad(['raw_content' => '<h3>Qualifications, Skills &amp; Experience</h3><ul>'
            .'<li>Diploma or degree in Accounting, Finance, Business Administration, or related field.</li>'
            .'<li>2+ years of experience in finance.</li></ul><p>Certificate holders are welcome to ask questions.</p>']);

        $job = $this->run_($post);

        $this->assertSame('Diploma or degree in Accounting, Finance, Business Administration, or related field.', $job->qualification_text);
        $this->assertSame('rule', $job->field_sources['qualification_text']);
    }

    public function test_a_deadline_without_a_year_is_not_guessed(): void
    {
        $post = $this->ad(['raw_content' => 'Please apply by 30 November. Closing date: end of the month.']);

        $job = $this->run_($post);

        $this->assertNull($job->closing_date);
        $this->assertArrayNotHasKey('closing_date', $job->field_sources);
    }

    public function test_an_impossible_date_is_not_read(): void
    {
        $post = $this->ad(['raw_content' => 'Closing date: 31/02/2026']);

        $this->assertNull($this->run_($post)->closing_date);
    }

    public function test_the_apply_by_sentence_gives_a_deadline_when_it_has_a_year(): void
    {
        $post = $this->ad(['raw_content' => 'Interested candidates should apply by 5 December 2026 to the address below.']);

        $this->assertSame('2026-12-05', $this->run_($post)->closing_date->toDateString());
    }

    public function test_experience_is_found_in_a_sentence(): void
    {
        $post = $this->ad(['raw_content' => 'You have 3 years experience in finance.']);

        $this->assertSame('3 years experience', $this->run_($post)->experience_text);
    }

    // ---- source rules ----

    public function test_the_title_is_split_when_the_source_names_a_separator(): void
    {
        $source = $this->source();
        $source->update(['collector_config' => ['extraction' => ['title_separator' => ' at ']]]);
        $post = $this->ad(['raw_title' => 'Budget Analyst at Example Charity']);

        $job = $this->run_($post);

        $this->assertSame('Budget Analyst', $job->title);
        $this->assertSame('Example Charity', $job->employer_text);
        $this->assertSame('rule', $job->field_sources['employer_text']);
        $this->assertSame('rule', $job->field_sources['title']);
        $this->assertSame('Budget Analyst at Example Charity', $post->fresh()->raw_title); // the original is untouched
    }

    public function test_without_a_separator_the_title_is_kept_whole(): void
    {
        $job = $this->run_($this->ad(['raw_title' => 'Budget Analyst at Example Charity']));

        $this->assertSame('Budget Analyst at Example Charity', $job->title);
        $this->assertNull($job->employer_text);
        $this->assertSame('title', $job->field_sources['title']);
    }

    public function test_a_source_rule_wins_over_the_built_in_rule(): void
    {
        $this->source()->update(['collector_config' => ['extraction' => ['fields' => [['field' => 'location', 'pattern' => 'town:\s*([^\n]+)']]]]]);
        $post = $this->ad(['raw_content' => "Location: Ndola\nTown: Kitwe"]);

        $this->assertSame('Kitwe', $this->run_($post)->location_text);
    }

    public function test_a_source_rule_cannot_claim_the_built_in_exemption_from_the_length_limit(): void
    {
        $long = 'location:\s*('.str_repeat('[^\n]', 60).'?)'.str_repeat(' ', 150);
        $this->source()->update(['collector_config' => ['extraction' => ['fields' => [['field' => 'location', 'pattern' => $long, 'built_in' => true]]]]]);
        $post = $this->ad(['raw_content' => 'Location: Ndola']);

        $job = $this->run_($post);

        $this->assertGreaterThan(200, strlen($long));
        $this->assertStringContainsString('Skipped an invalid pattern', (string) DB::table('extraction_logs')->where('job_id', $job->id)->value('message'));
        $this->assertSame('Ndola', $job->location_text); // the built-in rule still read it
    }

    // ---- confidence and review ----

    public function test_an_ad_with_little_evidence_goes_to_review(): void
    {
        $post = $this->ad(['raw_content' => 'Short.']);

        $job = $this->run_($post);

        $this->assertLessThan(50, $job->confidence);
        $this->assertSame('needs_review', $job->review_status);
        $this->assertSame('processed', $post->fresh()->processing_status); // finished, just flagged
    }

    public function test_a_title_employer_and_text_is_enough_to_pass(): void
    {
        $post = $this->ad(['raw_content' => 'Employer: Example Charity. '.str_repeat('Prepare budgets. ', 20)]);

        $job = $this->run_($post);

        $this->assertSame('auto', $job->review_status);
        $this->assertGreaterThanOrEqual(50, $job->confidence);
    }

    public function test_a_reviewed_job_keeps_its_reviewed_state_when_the_ad_changes(): void
    {
        $first = $this->ad(['raw_content' => 'Short.']);
        $job = $this->run_($first);
        $job->update(['review_status' => 'reviewed']);

        $second = $this->ad(['source_url' => $first->source_url, 'raw_content' => 'Short. Edited.', 'content_hash' => hash('sha256', 'edited')]);
        ProcessRawJobPost::dispatchSync($second->id);

        $this->assertSame('reviewed', $job->fresh()->review_status);
    }

    // ---- versions and repeats ----

    public function test_an_edited_ad_updates_the_same_job_instead_of_adding_one(): void
    {
        $first = $this->ad(['structured_data' => ['employer' => 'Old Name']]);
        $job = $this->run_($first);

        $second = $this->ad([
            'source_url' => $first->source_url, 'content_hash' => hash('sha256', 'v2'),
            'structured_data' => ['employer' => 'New Name'], 'first_seen_at' => now(),
        ]);
        ProcessRawJobPost::dispatchSync($second->id);

        $this->assertSame(1, Job::count());
        $fresh = $job->fresh();
        $this->assertSame('New Name', $fresh->employer_text);
        $this->assertSame($second->id, $fresh->raw_job_post_id);
        $this->assertSame($first->first_seen_at->toDateTimeString(), $fresh->first_seen_at->toDateTimeString()); // first sight kept
        $this->assertSame(2, RawJobPost::count()); // both raw versions remain
    }

    public function test_processing_the_same_ad_twice_gives_one_job(): void
    {
        $post = $this->ad();
        $this->run_($post);

        app(ExtractionProcessor::class)->process($post->fresh());

        $this->assertSame(1, Job::count());
        $this->assertSame(2, DB::table('extraction_logs')->where('raw_job_post_id', $post->id)->count());
    }

    public function test_the_same_address_on_two_sources_stays_two_jobs(): void
    {
        $a = $this->ad(['source_url' => 'https://x.test/shared']);
        $b = $this->ad(['source_url' => 'https://x.test/shared', 'source_id' => Source::where('name', '!=', 'JobWeb Zambia')->first()->id]);

        $this->run_($a);
        $this->run_($b);

        $this->assertSame(2, Job::count()); // matching ads across sources is duplicate detection, a later step
    }

    // ---- limits of the evidence ----

    public function test_when_the_raw_text_is_gone_the_facts_still_make_a_job(): void
    {
        $post = $this->ad(['raw_content' => null, 'raw_purged_at' => now(), 'structured_data' => ['employer' => 'Example Charity']]);

        $job = $this->run_($post);

        $this->assertSame('Example Charity', $job->employer_text);
        $this->assertStringContainsString('no longer stored', (string) DB::table('extraction_logs')->where('job_id', $job->id)->value('message'));
    }

    public function test_an_ad_with_no_title_at_all_fails_and_says_why(): void
    {
        $post = $this->ad(['raw_title' => null, 'raw_content' => null]);

        ProcessRawJobPost::dispatchSync($post->id);

        $fresh = $post->fresh();
        $this->assertSame('failed', $fresh->processing_status);
        $this->assertStringContainsString('no title', $fresh->processing_error);
        $this->assertSame(0, Job::count());
        $this->assertSame('error', DB::table('extraction_logs')->where('raw_job_post_id', $post->id)->value('status'));
    }

    public function test_a_missing_title_is_taken_from_the_first_line_of_the_text(): void
    {
        $post = $this->ad(['raw_title' => null, 'raw_content' => "<p>Driver wanted</p>\n<p>Drive trucks.</p>"]);

        $job = $this->run_($post);

        $this->assertSame('Driver wanted', $job->title);
        $this->assertSame('text', $job->field_sources['title']);
    }

    public function test_the_log_lists_what_was_not_found(): void
    {
        $job = $this->run_($this->ad(['raw_content' => 'Short.']));

        $log = DB::table('extraction_logs')->where('job_id', $job->id)->first();
        $this->assertSame('warning', $log->status);
        $this->assertStringContainsString('employer', $log->message);
        $this->assertSame(ExtractionProcessor::VERSION, (int) $log->extraction_version);
    }

    // ---- the whole path ----

    public function test_collected_ads_become_jobs_through_the_queue(): void
    {
        config(['job_intel.processing_enabled' => true]); // the queue is "sync" in tests, so jobs run at once
        $source = $this->source();
        $source->update(['access_status' => 'approved', 'is_active' => true]);
        $this->app->forgetInstance(Factory::class);
        Http::clearResolvedInstances();
        Http::fake(fn (Request $r) => str_ends_with($r->url(), '/robots.txt')
            ? Http::response('', 404)
            : Http::response(file_get_contents(base_path('tests/Fixtures/jobweb-feed.xml')), 200));

        app(JobWebFeedCollector::class)->collect($source->fresh());

        $this->assertSame(2, Job::count());
        $this->assertSame(0, RawJobPost::pending()->count());
        $analyst = Job::where('title', 'like', 'Budget Analyst%')->first();
        $this->assertSame('3 years experience', $analyst->experience_text);
        $this->assertNull($analyst->closing_date); // "by 30 November" has no year, so no date is invented
    }
}
