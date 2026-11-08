<?php

namespace Tests\Feature;

use App\Models\Job;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesJobs;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/** Phase 6, step 6: the screens for the clean dataset and the decisions a person makes on it. */
class JobScreensTest extends TestCase
{
    use MakesJobs, RefreshDatabase, SignsIn;

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-08 12:00:00');
        config(['job_intel.collector_contact' => 'owner@example.com']);
        $this->seed(SourceSeeder::class);
        $this->s = $this->sourceSet();
    }

    // ---- access ----

    public function test_every_new_screen_needs_a_signed_in_administrator(): void
    {
        $job = $this->ad($this->s['company']);

        foreach (['/jobs', "/jobs/{$job->id}", '/duplicates'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        foreach (["/jobs/{$job->id}/status", "/jobs/{$job->id}/approve", "/jobs/{$job->id}/separate", '/duplicates/1/confirm', '/duplicates/1/reject'] as $url) {
            $this->post($url)->assertRedirect('/login');
        }
    }

    // ---- the list ----

    public function test_the_list_shows_jobs_with_their_status_and_standard_place(): void
    {
        $this->signIn();
        $job = $this->ad($this->s['company'], ['locations' => [['locality' => 'Kitwe', 'region' => 'Copperbelt Province']]]);

        $this->get('/jobs')->assertOk()->assertSee('Mining Engineer')->assertSee('Sample Mining Ltd')->assertSee('Kitwe, Copperbelt')->assertSee('Published')->assertSee('15 Dec 2026');
    }

    public function test_the_list_filters_by_status_source_search_and_review(): void
    {
        $this->signIn();
        $a = $this->ad($this->s['company'], ['employer' => 'Alpha Mining Ltd'], ['raw_title' => 'Mining Engineer']);
        $b = $this->ad($this->s['boardA'], ['employer' => 'Beta Foods Ltd'], ['raw_title' => 'Cook', 'raw_content' => '<p>'.self::cleanerBody().'</p>']);
        $thin = $this->ad($this->s['boardB'], ['employer' => null, 'locations' => null, 'valid_through' => null, 'date_posted' => null], ['raw_title' => 'Odd Job', 'raw_content' => '<p>Short.</p>']);
        $b->update(['status' => 'expired']);

        $this->get('/jobs?status=expired')->assertSee('Cook')->assertDontSee('Mining Engineer')->assertDontSee('Odd Job');
        $this->get('/jobs?source='.$this->s['company']->id)->assertSee('Mining Engineer')->assertDontSee('Cook');
        $this->get('/jobs?q=beta')->assertSee('Cook')->assertDontSee('Alpha Mining');
        $this->get('/jobs?review=needs_review')->assertSee('Odd Job')->assertDontSee('Cook');
        $this->get('/jobs?status=wizard')->assertSessionHasErrors('status');
    }

    public function test_search_treats_percent_and_underscore_as_plain_characters(): void
    {
        $this->signIn();
        $this->ad($this->s['company']);

        $this->get('/jobs?q=%25')->assertOk()->assertDontSee('Mining Engineer'); // "%" is not "match everything"
        $this->get('/jobs?q=_')->assertOk()->assertDontSee('Mining Engineer');
    }

    public function test_text_from_an_ad_is_escaped(): void
    {
        $this->signIn();
        $job = $this->ad($this->s['company']);
        $this->assertSame('Mining Engineer', $this->ad($this->s['boardA'], [], ['raw_title' => '<script>alert(1)</script>Mining Engineer'])->title === 'alert(1)Mining Engineer' ? 'Mining Engineer' : 'x'); // reading strips tags

        // whatever reaches the database, the screens escape it
        $job->update(['title' => '<script>alert(1)</script> Mining Engineer', 'employer_text' => '<img src=x onerror=alert(2)>', 'location_text' => '"><b>x</b>']);

        foreach (['/jobs', "/jobs/{$job->id}"] as $url) {
            $this->get($url)->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<img src=x', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        }
    }

    public function test_an_empty_list_says_how_jobs_appear(): void
    {
        $this->signIn();

        $this->get('/jobs')->assertOk()->assertSee('No jobs yet');
    }

    // ---- one job ----

    public function test_a_job_page_shows_the_original_beside_the_standard_value_and_its_history(): void
    {
        $this->signIn();
        $job = $this->ad($this->s['company'], ['education' => "Bachelor's in Accounting", 'experience' => "3 years' experience", 'employment_type' => ['FULL_TIME']]);

        $this->get("/jobs/{$job->id}")->assertOk()
            ->assertSee("Bachelor&#039;s in Accounting", false)->assertSee("Bachelor&#039;s Degree in Accounting", false)
            ->assertSee('3 years&#039; experience', false)->assertSee('3 Years')->assertSee('FULL_TIME')->assertSee('Full-time')
            ->assertSee('published by the ad')->assertSee('Status history')->assertSee('Passed the checks')->assertSee('Reading log');
    }

    public function test_a_job_page_lists_its_copies_and_the_copy_links_back(): void
    {
        $this->signIn();
        $main = $this->ad($this->s['company']);
        $copy = $this->ad($this->s['boardA']);

        $this->get("/jobs/{$main->id}")->assertOk()->assertSee('Same vacancy elsewhere')->assertSee($this->s['boardA']->name);
        $this->get("/jobs/{$copy->id}")->assertOk()->assertSee('This is a copy of')->assertSee('It is a different vacancy');
    }

    public function test_a_job_page_lists_values_waiting_to_be_mapped(): void
    {
        $this->signIn();
        $job = $this->ad($this->s['company'], ['education' => "Valid driver's licence"]);

        $this->get("/jobs/{$job->id}")->assertSee('could not be standardized')->assertSee('Map them');
    }

    public function test_an_unknown_job_is_not_found(): void
    {
        $this->signIn();

        $this->get('/jobs/999999')->assertNotFound();
        $this->get('/jobs/abc')->assertNotFound();
    }

    // ---- decisions ----

    public function test_a_person_can_reject_with_a_reason_and_it_is_kept(): void
    {
        $this->signIn();
        $job = $this->ad($this->s['company']);

        $this->post("/jobs/{$job->id}/status", ['status' => 'rejected', 'reason' => 'A course advert'])->assertSessionHas('status');

        $fresh = $job->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('person', $fresh->status_by);
        $this->assertSame('A course advert', $fresh->status_reason);
        $this->get("/jobs/{$job->id}")->assertSee('A course advert');
    }

    public function test_a_person_can_publish_a_thin_ad(): void
    {
        $this->signIn();
        $job = $this->ad($this->s['company'], ['employer' => null, 'locations' => null, 'valid_through' => null, 'date_posted' => null], ['raw_content' => '<p>Short.</p>']);
        $this->assertSame('new', $job->status);

        $this->post("/jobs/{$job->id}/status", ['status' => 'published'])->assertSessionHasNoErrors();

        $this->assertSame('published', $job->fresh()->status);
        $this->assertSame('Decided by a person.', $job->fresh()->status_reason);
    }

    public function test_only_publish_and_reject_can_be_chosen_by_hand(): void
    {
        $this->signIn();
        $job = $this->ad($this->s['company']);

        foreach (['expired', 'closed', 'duplicate', 'new', 'open'] as $status) {
            $this->post("/jobs/{$job->id}/status", ['status' => $status])->assertSessionHasErrors('status');
        }
        $this->post("/jobs/{$job->id}/status", ['status' => 'published', 'reason' => str_repeat('x', 300)])->assertSessionHasErrors('reason');
        $this->assertSame('published', $job->fresh()->status);
        $this->assertSame(0, DB::table('job_status_changes')->where('job_id', $job->id)->where('changed_by', 'person')->count());
    }

    public function test_a_copy_cannot_be_decided_until_it_is_separated(): void
    {
        $this->signIn();
        $this->ad($this->s['company']);
        $copy = $this->ad($this->s['boardA']);

        $this->post("/jobs/{$copy->id}/status", ['status' => 'rejected'])->assertSessionHas('error');
        $this->assertSame('duplicate', $copy->fresh()->status);

        $this->post("/jobs/{$copy->id}/separate")->assertSessionHas('status');
        $this->assertSame('published', $copy->fresh()->status);
        $this->assertNull($copy->fresh()->duplicate_of_job_id);
    }

    public function test_separating_a_job_that_is_not_a_copy_is_refused(): void
    {
        $this->signIn();
        $job = $this->ad($this->s['company']);

        $this->post("/jobs/{$job->id}/separate")->assertStatus(422);
    }

    public function test_marking_a_thin_ad_as_reviewed_publishes_it(): void
    {
        $this->signIn();
        $job = $this->ad($this->s['company'], ['employer' => null, 'locations' => null, 'valid_through' => null, 'date_posted' => null], ['raw_content' => '<p>Short.</p>']);
        $this->assertSame('needs_review', $job->review_status);
        $this->assertSame('new', $job->status);

        $this->post("/jobs/{$job->id}/approve")->assertSessionHas('status');

        $fresh = $job->fresh();
        $this->assertSame('reviewed', $fresh->review_status);
        $this->assertSame('published', $fresh->status);
    }

    // ---- possible duplicates ----

    private function possiblePair(): array
    {
        $company = $this->ad($this->s['company']);
        $board = $this->ad($this->s['boardA'], [], ['raw_content' => '<p>'.self::mineBody('drainage').'</p>']);

        return [$company, $board, DB::table('job_duplicate_candidates')->first()];
    }

    public function test_the_duplicates_page_shows_both_ads_the_score_breakdown_and_the_suggested_main(): void
    {
        $this->signIn();
        [$company, $board, $pair] = $this->possiblePair();

        $this->get('/duplicates')->assertOk()
            ->assertSee("Score {$pair->score}")->assertSee($company->title)->assertSee($this->s['company']->name)->assertSee($this->s['boardA']->name)
            ->assertSee('Ad text')->assertSee('Same vacancy')->assertSee('Different vacancies')
            ->assertSee('The suggested main ad is');
    }

    public function test_the_duplicates_page_says_when_nothing_waits_and_lists_automatic_merges(): void
    {
        $this->signIn();
        $this->get('/duplicates')->assertOk()->assertSee('Nothing is waiting for a decision');

        $this->ad($this->s['company']);
        $this->ad($this->s['boardA']); // identical text: merged automatically

        $this->get('/duplicates')->assertSee('Matched automatically')->assertSee('same text, same employer');
    }

    public function test_confirming_links_the_pair_with_the_chosen_main(): void
    {
        $this->signIn();
        [$company, $board, $pair] = $this->possiblePair();

        $this->post("/duplicates/{$pair->id}/confirm", ['main' => $board->id])->assertRedirect('/duplicates')->assertSessionHas('status');

        $this->assertSame('duplicate', $company->fresh()->status);
        $this->assertSame($board->id, $company->fresh()->duplicate_of_job_id);
        $this->assertSame('published', $board->fresh()->status);
        $this->assertSame('confirmed', DB::table('job_duplicate_candidates')->first()->outcome);
        $this->get('/duplicates')->assertSee('Nothing is waiting');
    }

    public function test_the_main_must_be_one_of_the_two_ads(): void
    {
        $this->signIn();
        [$company, $board, $pair] = $this->possiblePair();
        $stranger = $this->ad($this->s['gov'], ['employer' => 'Other Ltd'], ['raw_title' => 'Cook', 'raw_content' => '<p>'.self::cleanerBody().'</p>']);

        $this->post("/duplicates/{$pair->id}/confirm", ['main' => $stranger->id])->assertSessionHasErrors('main');
        $this->post("/duplicates/{$pair->id}/confirm", [])->assertSessionHasErrors('main');
        $this->assertSame('possible', DB::table('job_duplicate_candidates')->first()->outcome);
        $this->assertSame('published', $board->fresh()->status);
    }

    public function test_rejecting_keeps_them_apart_and_they_are_not_proposed_again(): void
    {
        $this->signIn();
        [$company, $board, $pair] = $this->possiblePair();

        $this->post("/duplicates/{$pair->id}/reject")->assertRedirect('/duplicates');

        $this->assertSame('rejected', DB::table('job_duplicate_candidates')->first()->outcome);
        $this->assertSame('published', $board->fresh()->status);
        app(\App\Duplicates\DuplicateDetector::class)->detect($board->fresh());
        $this->assertSame('rejected', DB::table('job_duplicate_candidates')->first()->outcome); // still decided
        $this->get('/duplicates')->assertSee('Nothing is waiting');
    }

    public function test_a_decided_or_unknown_pair_cannot_be_decided_again(): void
    {
        $this->signIn();
        [$company, $board, $pair] = $this->possiblePair();
        $this->post("/duplicates/{$pair->id}/reject");

        $this->post("/duplicates/{$pair->id}/confirm", ['main' => $company->id])->assertNotFound();
        $this->post("/duplicates/{$pair->id}/reject")->assertNotFound();
        $this->post('/duplicates/999999/reject')->assertNotFound();
        $this->assertSame('rejected', DB::table('job_duplicate_candidates')->first()->outcome);
    }

    public function test_the_menu_links_to_the_new_screens(): void
    {
        $this->signIn();

        $this->get('/')->assertSee('href="'.route('jobs.index').'"', false)->assertSee('Possible duplicates')->assertSee('Values to map');
    }
}
