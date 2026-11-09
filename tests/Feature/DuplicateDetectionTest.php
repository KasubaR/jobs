<?php

namespace Tests\Feature;

use App\Duplicates\DuplicateDetector;
use App\Duplicates\DuplicateScorer;
use App\Jobs\ProcessRawJobPost;
use App\Models\Job;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesJobs;
use Tests\TestCase;

/** Phase 6, step 5: the same vacancy on several sites becomes one main job; look-alikes stay separate. */
class DuplicateDetectionTest extends TestCase
{
    use MakesJobs, RefreshDatabase;

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-08 12:00:00');
        config(['job_intel.collector_contact' => 'owner@example.com']);
        $this->seed(SourceSeeder::class);
        $this->s = $this->sourceSet();
    }

    private function pairs(): array
    {
        return DB::table('job_duplicate_candidates')->orderBy('id')->get()->all();
    }

    private function score(Job $a, Job $b): array
    {
        return app(DuplicateScorer::class)->score($a->fresh(), $b->fresh());
    }

    // ---- the three-site example from the brief ----

    public function test_the_same_vacancy_on_the_company_site_and_two_boards_becomes_one_main_job(): void
    {
        $company = $this->ad($this->s['company']);
        $boardA = $this->ad($this->s['boardA'], ['employer' => 'SAMPLE MINING LIMITED'], ['raw_title' => 'URGENT: Mining Engineer – Vacancy (2 positions)', 'raw_content' => '<p>Share this job</p><p>'.self::mineBody().'</p><p>How to apply: email the HR office.</p>']);
        $boardB = $this->ad($this->s['boardB'], ['employer' => 'Sample Mining'], ['raw_title' => 'Mining Engineers']);

        $company = $company->fresh();
        $this->assertSame('published', $company->status);
        $this->assertNull($company->duplicate_of_job_id);

        foreach ([$boardA, $boardB] as $copy) {
            $copy = $copy->fresh();
            $this->assertSame('duplicate', $copy->status);
            $this->assertSame($company->id, $copy->duplicate_of_job_id); // the employer's own site is the main one
            $this->assertStringContainsString("Same vacancy as job #{$company->id}", $copy->status_reason);
            $this->assertSame($this->s['boardA']->id === $copy->rawPost->source_id ? 'URGENT: Mining Engineer – Vacancy (2 positions)' : 'Mining Engineers', $copy->title); // it keeps its own record
            $this->assertNotNull($copy->source_url);
        }

        $this->assertSame(2, DB::table('job_duplicate_candidates')->where('outcome', 'auto')->count());
        $this->assertSame('exact_text', DB::table('job_duplicate_candidates')->orderBy('id')->value('reason'));
    }

    public function test_a_reworded_copy_is_proposed_not_merged_when_it_is_not_certain_enough(): void
    {
        $company = $this->ad($this->s['company']);
        $copy = $this->ad($this->s['boardA'], ['employer' => 'Sample Mining Limited'], ['raw_content' => '<p>'.self::mineBody('drainage').'</p>']);

        $score = $this->score($company, $copy);
        $this->assertGreaterThanOrEqual(70, $score['score']);
        $this->assertLessThan(90, $score['score']);

        $this->assertSame('published', $copy->fresh()->status);
        $pair = $this->pairs()[0];
        $this->assertSame('possible', $pair->outcome);
        $this->assertSame($score['score'], (int) $pair->score);
        $this->assertArrayHasKey('parts', json_decode($pair->breakdown, true));
    }

    public function test_a_score_at_the_automatic_line_merges_and_the_line_is_a_setting(): void
    {
        config(['job_intel.duplicates.auto' => 75]);
        $company = $this->ad($this->s['company']);
        $copy = $this->ad($this->s['boardA'], ['employer' => 'Sample Mining Limited'], ['raw_content' => '<p>'.self::mineBody('drainage').'</p>']);

        $this->assertSame('duplicate', $copy->fresh()->status);
        $this->assertSame($company->id, $copy->fresh()->duplicate_of_job_id);
        $this->assertStringContainsString('similarity score', $copy->fresh()->status_reason);
        $this->assertSame('score', $this->pairs()[0]->reason);
    }

    public function test_the_same_specific_application_link_is_a_certain_match(): void
    {
        $a = $this->ad($this->s['company'], ['application_url' => 'https://apply.example.com/mining-engineer'], ['raw_content' => '<p>Apply online: https://apply.example.com/mining-engineer?utm_source=x</p><p>'.self::mineBody().'</p>']);
        $b = $this->ad($this->s['boardA'], ['employer' => 'Someone Else Ltd'], ['raw_content' => '<p>Apply online: https://www.apply.example.com/mining-engineer/</p><p>'.self::mineBody('drainage').'</p>']);

        $this->assertSame('duplicate', $b->fresh()->status);
        $this->assertSame('exact_url', $this->pairs()[0]->reason);
    }

    // ---- who becomes the main ad ----

    public function test_a_later_company_ad_becomes_the_main_one_and_the_boards_follow_it(): void
    {
        $boardA = $this->ad($this->s['boardA']);
        $boardB = $this->ad($this->s['boardB']);
        $this->assertSame($boardA->id, $boardB->fresh()->duplicate_of_job_id); // the older board is the main one so far

        $company = $this->ad($this->s['company']);

        $this->assertNull($company->fresh()->duplicate_of_job_id);
        $this->assertSame('published', $company->fresh()->status);
        $this->assertSame($company->id, $boardA->fresh()->duplicate_of_job_id);
        $this->assertSame($company->id, $boardB->fresh()->duplicate_of_job_id); // re-pointed, not chained
        $this->assertSame('duplicate', $boardA->fresh()->status);
    }

    public function test_government_beats_a_board_when_there_is_no_company_ad(): void
    {
        $board = $this->ad($this->s['boardA']);
        $gov = $this->ad($this->s['gov']);

        $this->assertSame($gov->id, $board->fresh()->duplicate_of_job_id);
    }

    public function test_a_copy_of_a_copy_joins_the_main_job_directly(): void
    {
        $company = $this->ad($this->s['company']);
        $a = $this->ad($this->s['boardA']);
        $b = $this->ad($this->s['boardB']);

        $this->assertSame($company->id, $a->fresh()->duplicate_of_job_id);
        $this->assertSame($company->id, $b->fresh()->duplicate_of_job_id);
        $this->assertSame(2, Job::where('duplicate_of_job_id', $company->id)->count());
        $this->assertSame(0, Job::whereIn('duplicate_of_job_id', [$a->id, $b->id])->count());
    }

    // ---- look-alikes that must stay separate ----

    public function test_the_same_title_in_another_town_is_a_separate_opening_unless_the_text_is_the_same(): void
    {
        $solwezi = $this->ad($this->s['company']);
        $kitwe = $this->ad($this->s['boardA'], ['locations' => [['locality' => 'Kitwe']]], ['raw_content' => '<p>'.self::mineBody('drainage').'</p>']);

        $this->assertSame('published', $kitwe->fresh()->status);
        $this->assertSame([], $this->pairs()); // not even proposed
        $this->assertContains('Different places, and the text is not the same: treated as separate openings.', $this->score($solwezi, $kitwe)['breakdown']['notes']);

        // the very same text for two places is one ad listed twice
        $same = $this->ad($this->s['boardB'], ['locations' => [['locality' => 'Kitwe']]]);
        $this->assertSame('duplicate', $same->fresh()->status);
    }

    public function test_an_ad_posted_months_apart_is_a_new_opening(): void
    {
        // everything else agrees (same employer, title, place, closing date and text), only the posting date is months apart
        $old = $this->ad($this->s['company'], ['date_posted' => '2026-08-01'], ['first_seen_at' => now()->subDays(100)]);
        $new = $this->ad($this->s['boardA'], ['date_posted' => '2026-11-01']);

        $this->assertSame('published', $new->fresh()->status);
        $this->assertSame([], $this->pairs());

        // and the score itself refuses it, not only the search for candidates
        $r = $this->score($old, $new);
        $this->assertLessThanOrEqual(69, $r['score']);
        $this->assertFalse($r['exact']);
        $this->assertStringContainsString('days apart: a re-advertisement', implode(' ', $r['breakdown']['notes']));
    }

    public function test_closing_dates_far_apart_mean_a_new_opening(): void
    {
        $a = $this->ad($this->s['company'], ['valid_through' => '2026-12-15']);
        $b = $this->ad($this->s['boardA'], ['valid_through' => '2027-02-20']);

        $this->assertSame('published', $b->fresh()->status);
        $this->assertSame([], $this->pairs());
        $this->assertNotEmpty($this->score($a, $b)['breakdown']['notes']);
    }

    public function test_an_agency_named_as_employer_is_never_merged_automatically(): void
    {
        $a = $this->ad($this->s['boardA'], ['employer' => 'Talent Recruitment Services']);
        $b = $this->ad($this->s['boardB'], ['employer' => 'Talent Recruitment Services']); // identical text, same "employer"

        $this->assertSame('published', $b->fresh()->status);
        $result = $this->score($a, $b);
        $this->assertTrue($result['no_auto']);
        $this->assertFalse($result['exact']);
        $this->assertSame('possible', $this->pairs()[0]->outcome); // a person decides
    }

    public function test_two_ads_from_the_same_source_are_left_for_a_person(): void
    {
        $a = $this->ad($this->s['boardA']);
        $b = $this->ad($this->s['boardA']); // the site lists the same text twice: two openings or one?

        $this->assertSame('published', $b->fresh()->status);
        $this->assertSame('possible', $this->pairs()[0]->outcome);
        $this->assertFalse($this->score($a, $b)['exact']);
    }

    public function test_the_same_source_rule_holds_even_when_the_score_is_high_enough(): void
    {
        config(['job_intel.duplicates.auto' => 75]); // a score that would merge two ads from different sources
        $a = $this->ad($this->s['boardA']);
        $b = $this->ad($this->s['boardA'], [], ['raw_content' => '<p>'.self::mineBody('drainage').'</p>']);

        $r = $this->score($a, $b);
        $this->assertGreaterThanOrEqual(75, $r['score']);
        $this->assertTrue($r['no_auto']);
        $this->assertSame('published', $b->fresh()->status);
        $this->assertSame('possible', $this->pairs()[0]->outcome);

        $other = $this->ad($this->s['boardB'], [], ['raw_content' => '<p>'.self::mineBody('drainage').'</p>']);
        $this->assertSame('duplicate', $other->fresh()->status); // the same score from another source does merge
    }

    public function test_a_generic_careers_link_does_not_merge_different_jobs(): void
    {
        $careers = ['application_url' => 'https://example.com/careers'];
        $miner = $this->ad($this->s['company'], $careers + ['employer' => 'Sample Mining Ltd']);
        $cleaner = $this->ad($this->s['boardA'], $careers + ['employer' => 'Sample Mining Ltd'], ['raw_title' => 'Office Cleaner', 'raw_content' => '<p>'.self::cleanerBody().'</p>']);

        $this->assertSame('published', $cleaner->fresh()->status);
        $this->assertFalse($this->score($miner, $cleaner)['exact']);
        $this->assertSame([], $this->pairs());
    }

    public function test_a_different_employer_or_a_different_role_is_not_a_duplicate(): void
    {
        $company = $this->ad($this->s['company']);
        $otherEmployer = $this->ad($this->s['boardA'], ['employer' => 'Another Mine Plc']);
        $intern = $this->ad($this->s['boardB'], [], ['raw_title' => 'Mining Engineering Intern', 'raw_content' => '<p>'.self::cleanerBody().'</p>']);

        $this->assertSame('published', $otherEmployer->fresh()->status);
        $this->assertSame('published', $intern->fresh()->status);
        $this->assertLessThan(70, $this->score($company, $intern)['score']);
    }

    public function test_ads_with_no_text_to_compare_can_only_be_proposed_never_merged(): void
    {
        $a = $this->ad($this->s['company'], [], ['raw_content' => '<p>Apply soon.</p>']);
        $b = $this->ad($this->s['boardA'], [], ['raw_content' => '<p>Apply soon.</p>']);

        $r = $this->score($a, $b);
        $this->assertNull($b->fresh()->content_fingerprint);
        $this->assertLessThan(90, $r['score']); // everything but the text agrees, and it still cannot reach the line
        $this->assertSame('published', $b->fresh()->status);
    }

    public function test_a_rejected_job_is_not_matched(): void
    {
        $company = $this->ad($this->s['company']);
        $company->update(['status' => 'rejected', 'status_by' => 'person']);

        $board = $this->ad($this->s['boardA']);

        $this->assertSame('published', $board->fresh()->status);
        $this->assertSame([], $this->pairs());
    }

    // ---- editing and people ----

    public function test_a_copy_whose_ad_changes_to_something_else_is_released(): void
    {
        $company = $this->ad($this->s['company']);
        $board = $this->ad($this->s['boardA']);
        $this->assertSame('duplicate', $board->fresh()->status);

        // the board edits the ad into a different job entirely
        $v2 = \App\Models\RawJobPost::create([
            'source_id' => $this->s['boardA']->id, 'source_url' => $board->source_url, 'raw_title' => 'Office Cleaner',
            'raw_content' => '<p>'.self::cleanerBody().'</p>', 'structured_data' => ['employer' => 'Another Employer Ltd', 'valid_through' => '2026-12-20'],
            'content_hash' => hash('sha256', 'v2'), 'fetched_at' => now(), 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        ProcessRawJobPost::dispatchSync($v2->id);

        $fresh = $board->fresh();
        $this->assertNull($fresh->duplicate_of_job_id);
        $this->assertSame('published', $fresh->status);
        $this->assertSame('published', $company->fresh()->status);
        $this->assertContains(['duplicate', 'new'], DB::table('job_status_changes')->where('job_id', $board->id)->get()->map(fn ($r) => [$r->from_status, $r->to_status])->all());
    }

    public function test_a_person_can_say_a_merged_pair_is_different_and_it_stays_apart(): void
    {
        $company = $this->ad($this->s['company']);
        $board = $this->ad($this->s['boardA']);
        $this->assertSame('duplicate', $board->fresh()->status);

        app(DuplicateDetector::class)->separate($board->fresh());

        $this->assertSame('published', $board->fresh()->status);
        $this->assertNull($board->fresh()->duplicate_of_job_id);
        $this->assertSame('rejected', $this->pairs()[0]->outcome);
        $this->assertSame('person', $this->pairs()[0]->decided_by);

        // the same ad arriving again is not linked again
        ProcessRawJobPost::dispatchSync(\App\Models\RawJobPost::create([
            'source_id' => $this->s['boardA']->id, 'source_url' => $board->source_url, 'raw_title' => 'Mining Engineer', 'raw_content' => '<p>'.self::mineBody().' Updated.</p>',
            'structured_data' => ['employer' => 'Sample Mining Ltd', 'locations' => [['locality' => 'Solwezi']], 'valid_through' => '2026-12-15', 'date_posted' => '2026-11-01'],
            'content_hash' => hash('sha256', 'again'), 'fetched_at' => now(), 'first_seen_at' => now(), 'last_seen_at' => now(),
        ])->id);

        $this->assertSame('published', $board->fresh()->status);
        $this->assertSame(1, DB::table('job_duplicate_candidates')->count());
    }

    public function test_a_person_confirming_a_possible_pair_links_them_with_the_main_they_choose(): void
    {
        $company = $this->ad($this->s['company']);
        $board = $this->ad($this->s['boardA'], [], ['raw_content' => '<p>'.self::mineBody('drainage').'</p>']);
        $pair = $this->pairs()[0];
        $this->assertSame('possible', $pair->outcome);

        app(DuplicateDetector::class)->confirm($pair, $board->id); // a person prefers the board's version

        $this->assertSame('duplicate', $company->fresh()->status);
        $this->assertSame($board->id, $company->fresh()->duplicate_of_job_id);
        $this->assertSame('confirmed', $this->pairs()[0]->outcome);
        $history = DB::table('job_status_changes')->where('job_id', $company->id)->orderByDesc('id')->first();
        $this->assertSame('person', $history->changed_by);
    }

    public function test_a_possible_pair_that_stops_matching_is_dropped(): void
    {
        $company = $this->ad($this->s['company']);
        $board = $this->ad($this->s['boardA'], [], ['raw_content' => '<p>'.self::mineBody('drainage').'</p>']);
        $this->assertCount(1, $this->pairs());

        ProcessRawJobPost::dispatchSync(\App\Models\RawJobPost::create([
            'source_id' => $this->s['boardA']->id, 'source_url' => $board->source_url, 'raw_title' => 'Office Cleaner', 'raw_content' => '<p>'.self::cleanerBody().'</p>',
            'structured_data' => ['employer' => 'Another Employer Ltd'], 'content_hash' => hash('sha256', 'changed'), 'fetched_at' => now(), 'first_seen_at' => now(), 'last_seen_at' => now(),
        ])->id);

        $this->assertSame([], $this->pairs());
    }

    public function test_processing_the_same_ad_again_adds_no_second_pair_or_link(): void
    {
        $this->ad($this->s['company']);
        $board = $this->ad($this->s['boardA']);

        app(DuplicateDetector::class)->detect($board->fresh());
        app(DuplicateDetector::class)->detect($board->fresh());

        $this->assertSame(1, DB::table('job_duplicate_candidates')->count());
        $this->assertSame(1, Job::where('status', 'duplicate')->count());
    }

    // ---- groups of copies ----

    private function groupWithAReword(): array
    {
        $company = $this->ad($this->s['company']);
        $copy = $this->ad($this->s['boardA']);                                                  // same text: merged automatically
        $reword = $this->ad($this->s['boardB'], [], ['raw_content' => '<p>'.self::mineBody('drainage').'</p>']); // reworded: for a person

        return [$company, $copy, $reword];
    }

    public function test_a_reworded_copy_of_a_group_is_proposed_once_not_once_per_member(): void
    {
        [$company, $copy, $reword] = $this->groupWithAReword();

        $this->assertSame('duplicate', $copy->fresh()->status);
        $this->assertSame(1, DB::table('job_duplicate_candidates')->where('outcome', 'possible')->count()); // one question for the group
        $this->assertSame(2, DB::table('job_duplicate_candidates')->count());                                // plus the automatic pair
        $this->assertSame('published', $reword->fresh()->status);
    }

    public function test_the_reworded_ad_arriving_first_still_leaves_one_question_for_the_group(): void
    {
        $reword = $this->ad($this->s['boardB'], [], ['raw_content' => '<p>'.self::mineBody('drainage').'</p>']);
        $company = $this->ad($this->s['company']);   // a question: is the reworded ad the same?
        $copy = $this->ad($this->s['boardA']);       // merges with the company ad, and is a second way to ask the same question

        $this->assertSame('duplicate', $copy->fresh()->status);
        $possible = DB::table('job_duplicate_candidates')->where('outcome', 'possible')->get();
        $this->assertCount(1, $possible, 'the same question was kept twice, once through each member of the group');
        $this->assertContains($reword->id, [$possible[0]->job_a_id, $possible[0]->job_b_id]);
    }

    public function test_confirming_one_pair_leaves_no_stale_question_about_the_same_group(): void
    {
        $reword = $this->ad($this->s['boardB'], [], ['raw_content' => '<p>'.self::mineBody('drainage').'</p>']);
        $company = $this->ad($this->s['company']);
        $copy = $this->ad($this->s['boardA']);
        $pair = DB::table('job_duplicate_candidates')->where('outcome', 'possible')->first();

        app(DuplicateDetector::class)->confirm($pair, $company->id);

        $this->assertSame(0, DB::table('job_duplicate_candidates')->where('outcome', 'possible')->count());
        $this->assertSame($company->id, $reword->fresh()->duplicate_of_job_id);
        $this->assertSame($company->id, $copy->fresh()->duplicate_of_job_id); // still one level
    }

    public function test_one_question_per_group_also_when_the_company_ad_came_first(): void
    {
        $company = $this->ad($this->s['company']);
        $reword = $this->ad($this->s['boardB'], [], ['raw_content' => '<p>'.self::mineBody('drainage').'</p>']); // a question about the company ad
        $copy = $this->ad($this->s['boardA']);                                                                 // joins the company ad, asks it again

        $this->assertSame('duplicate', $copy->fresh()->status);
        $this->assertSame(1, DB::table('job_duplicate_candidates')->where('outcome', 'possible')->count());
    }

    public function test_confirming_a_pair_drops_other_questions_that_it_answers(): void
    {
        $company = $this->ad($this->s['company']);
        $copy = $this->ad($this->s['boardA']);
        $reword = $this->ad($this->s['boardB'], [], ['raw_content' => '<p>'.self::mineBody('drainage').'</p>']);
        $first = DB::table('job_duplicate_candidates')->where('outcome', 'possible')->first();
        $otherMember = in_array($company->id, [$first->job_a_id, $first->job_b_id], true) ? $copy->id : $company->id;
        [$lo, $hi] = $otherMember < $reword->id ? [$otherMember, $reword->id] : [$reword->id, $otherMember];
        DB::table('job_duplicate_candidates')->insert(['job_a_id' => $lo, 'job_b_id' => $hi, 'score' => 71, 'reason' => 'score', 'outcome' => 'possible', 'created_at' => now(), 'updated_at' => now()]); // the same question, asked through the other copy

        app(DuplicateDetector::class)->confirm($first, $company->id);

        $this->assertSame(0, DB::table('job_duplicate_candidates')->where('outcome', 'possible')->count());
    }

    public function test_a_question_about_two_ads_that_end_up_in_one_group_is_dropped(): void
    {
        $company = $this->ad($this->s['company']);
        $first = $this->ad($this->s['boardA']);
        // a question left over from before the two were joined (here made by hand)
        DB::table('job_duplicate_candidates')->where('job_a_id', $company->id)->where('job_b_id', $first->id)->update(['outcome' => 'possible', 'score' => 75]);
        $this->assertSame(1, DB::table('job_duplicate_candidates')->where('outcome', 'possible')->count());

        $this->ad($this->s['boardB']); // joins the same group

        $this->assertSame(0, DB::table('job_duplicate_candidates')->where('outcome', 'possible')->count());
    }

    public function test_choosing_a_copy_as_the_main_chooses_its_group(): void
    {
        [$company, $copy, $reword] = $this->groupWithAReword();
        $pair = DB::table('job_duplicate_candidates')->where('outcome', 'possible')->first();
        $copyIsInPair = in_array($copy->id, [$pair->job_a_id, $pair->job_b_id], true);

        // whichever member was shown, naming the copy as main keeps the group's own main (the copy points at it)
        app(DuplicateDetector::class)->confirm($pair, $copyIsInPair ? $copy->id : $company->id);

        $this->assertNull($company->fresh()->duplicate_of_job_id);
        $this->assertSame($company->id, $reword->fresh()->duplicate_of_job_id);
    }

    public function test_saying_different_is_said_about_the_whole_group(): void
    {
        [$company, $copy, $reword] = $this->groupWithAReword();
        $pair = DB::table('job_duplicate_candidates')->where('outcome', 'possible')->first();

        app(DuplicateDetector::class)->reject($pair);

        // the reworded ad is "different" from the company ad and from its copy, so no other member brings the question back
        $this->assertSame(0, DB::table('job_duplicate_candidates')->where('outcome', 'possible')->count());
        $this->assertSame(2, DB::table('job_duplicate_candidates')->where('outcome', 'rejected')->count());
        app(DuplicateDetector::class)->detect($reword->fresh());
        $this->assertSame(0, DB::table('job_duplicate_candidates')->where('outcome', 'possible')->count());
        $this->assertSame('published', $reword->fresh()->status);
    }

    // ---- the scorer on its own ----

    public function test_the_scorer_reads_two_jobs_and_writes_nothing(): void
    {
        $a = $this->ad($this->s['company']);
        $b = $this->ad($this->s['boardA'], [], ['raw_content' => '<p>'.self::mineBody('drainage').'</p>']);
        $before = [DB::table('jobs')->count(), DB::table('job_duplicate_candidates')->count(), DB::table('job_status_changes')->count()];

        $this->score($a, $b);

        $this->assertSame($before, [DB::table('jobs')->count(), DB::table('job_duplicate_candidates')->count(), DB::table('job_status_changes')->count()]);
    }

    public function test_title_similarity(): void
    {
        $this->assertSame(1.0, DuplicateScorer::similarity('mining engineer', 'mining engineer'));
        $this->assertSame(0.0, DuplicateScorer::similarity('mining engineer', 'office cleaner'));
        $this->assertSame(0.0, DuplicateScorer::similarity(null, 'mining engineer'));
        $this->assertEqualsWithDelta(0.25, DuplicateScorer::similarity('mining engineer', 'mining engineering intern'), 0.001);
    }

    public function test_the_weights_add_up_to_one_hundred(): void
    {
        $this->assertSame(100, array_sum(config('job_intel.duplicates.weights')));
        $this->assertGreaterThan(config('job_intel.duplicates.possible'), config('job_intel.duplicates.auto'));
    }
}
