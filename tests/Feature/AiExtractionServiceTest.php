<?php

namespace Tests\Feature;

use App\Ai\AiFailure;
use App\Ai\Extraction\AiEligibility;
use App\Jobs\ExtractJobQualifications;
use App\Models\Job;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\MakesJobs;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/** The AI extraction service end to end, with saved answers only. */
class AiExtractionServiceTest extends TestCase
{
    use MakesJobs, RefreshDatabase, SignsIn;

    private const URL = 'generativelanguage.googleapis.com/*';

    private const DEGREE = 'Applicants must hold a Bachelor of Engineering.';

    private const EXCEL = 'Microsoft Excel is an added advantage.';

    private const YEARS = 'Minimum five (5) years of experience in a hospital.';

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        config(['job_intel.collector_contact' => 'owner@example.com']);
        config(['job_intel.ai' => array_replace(config('job_intel.ai'), [
            'enabled' => true, 'max_attempts' => 2, 'daily_call_limit' => 200, 'daily_budget_usd' => 0,
            'gemini' => ['key' => 'test-key', 'model' => 'test-model', 'base_url' => 'https://generativelanguage.googleapis.com/v1beta'],
        ])]);
        $this->seed(SourceSeeder::class);
        $this->s = $this->sourceSet();
        $this->s['boardA']->update(['ai_allowed' => true, 'retain_raw' => true]);
    }

    private static int $n = 0;

    /** A vacancy the rules have read and found no qualification in, beside a sentence that names one. */
    private function vacancy(array $over = []): Job
    {
        self::$n++;
        $n = self::$n;
        $job = $this->ad($this->s['boardA'], ['employer' => "Employer {$n} Ltd"], $over + [
            'raw_title' => "Officer {$n}",
            'raw_content' => '<p>Requirements:<br>'.self::DEGREE.'<br>'.self::EXCEL.'<br>'.self::YEARS."<br>Our office number {$n} is near the {$n} road and serves clients each day.</p>",
        ]);
        DB::table('job_qualifications')->where('job_id', $job->id)->delete();   // the rules found nothing
        DB::table('job_skills')->where('job_id', $job->id)->delete();
        DB::table('job_experiences')->where('job_id', $job->id)->delete();
        $job->forceFill(['analysis_status' => 'done', 'analysis_method' => 'rules'])->save();

        return $job->fresh();
    }

    private function item(array $own, string $quote, string $type = 'mandatory', float $confidence = 0.9, ?int $group = null): array
    {
        return $own + ['requirement_type' => $type, 'alternative_group' => $group, 'original_text' => $quote, 'confidence' => $confidence];
    }

    private function answer(array $sections = []): array
    {
        return array_replace(['qualification_found' => ! empty($sections['qualifications']), 'qualifications' => [], 'professional_registrations' => [], 'experience_requirements' => [], 'skills' => [], 'other_requirements' => [], 'extraction_notes' => []], $sections);
    }

    private function good(): array
    {
        return $this->answer([
            'qualifications' => [$this->item(['name' => 'Bachelor of Engineering', 'level' => 'bachelor', 'field' => 'engineering'], self::DEGREE)],
            'skills' => [$this->item(['name' => 'Microsoft Excel', 'type' => 'software'], self::EXCEL, 'advantage')],
            'experience_requirements' => [$this->item(['years_min' => 5, 'years_max' => null, 'area' => 'a hospital'], self::YEARS)],
        ]);
    }

    private function reply(array $answer): void
    {
        Http::fake([self::URL => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($answer)]]]]], 'usageMetadata' => ['promptTokenCount' => 500, 'candidatesTokenCount' => 80]])]);
    }

    private function extractNow(Job $job, bool $force = false): void
    {
        ExtractJobQualifications::dispatchSync($job->id, $force);
    }

    private function rows(string $table, Job $job): \Illuminate\Support\Collection
    {
        return DB::table($table)->where('job_id', $job->id)->get();
    }

    private function logs(Job $job): string
    {
        return DB::table('extraction_logs')->where('job_id', $job->id)->pluck('message')->implode(' | ');
    }

    // ---- the whole path ----

    public function test_a_good_answer_is_stored_as_ai_rows_beside_the_rules_own(): void
    {
        $job = $this->vacancy();
        $this->reply($this->good());

        $this->extractNow($job);

        $q = $this->rows('job_qualifications', $job)->sole();
        $this->assertSame(['ai', 'required', 'bachelor', 90, self::DEGREE], [$q->source, $q->requirement_level, $q->education_level, (int) $q->confidence, $q->evidence]);
        $this->assertNotNull($q->academic_award_id);
        $this->assertNotNull($q->directory_qualification_id);
        $this->assertSame(self::DEGREE, $q->original_text);

        $skill = $this->rows('job_skills', $job)->sole();
        $this->assertSame(['ai', 'advantage', 0], [$skill->source, $skill->requirement_level, (int) $skill->is_required]);
        $this->assertSame('Microsoft Excel', DB::table('skills')->where('id', $skill->skill_id)->value('name'));

        $exp = $this->rows('job_experiences', $job)->sole();
        $this->assertSame([5, 'a hospital', 'ai'], [(int) $exp->min_years, $exp->area, $exp->source]);

        $this->assertSame('mixed', $job->fresh()->analysis_method);
        $this->assertStringContainsString('AI read: 3 added', $this->logs($job));
        $usage = DB::table('ai_usage')->sole();
        $this->assertSame(['extract', 'ok', $job->id], [$usage->purpose, $usage->status, (int) $usage->job_id]);
        $this->assertSame(\App\Ai\Extraction\QualificationSchema::PROMPT_VERSION, (int) $usage->prompt_version);   // the answer is tied to the wording that produced it
    }

    public function test_the_request_carries_the_rules_and_the_fenced_ad_and_nothing_else_of_the_job(): void
    {
        $job = $this->vacancy();
        $this->reply($this->good());

        $this->extractNow($job);

        Http::assertSent(function (Request $r) {
            $prompt = $r['contents'][0]['parts'][0]['text'];

            return str_contains($r['systemInstruction']['parts'][0]['text'], 'Never invent')
                && str_starts_with($prompt, '<<<ADVERT') && str_contains($prompt, self::DEGREE)
                && ! str_contains($prompt, 'owner@example.com');
        });
    }

    // ---- never invent, never force ----

    public function test_an_invented_qualification_is_not_stored_and_is_logged(): void
    {
        $job = $this->vacancy();
        $this->reply($this->answer(['qualifications' => [$this->item(['name' => 'Master of Laws', 'level' => 'masters', 'field' => 'law'], 'A Master of Laws is required.')]]));

        $this->extractNow($job);

        $this->assertCount(0, $this->rows('job_qualifications', $job));
        $this->assertStringContainsString('1 rejected by the checks', $this->logs($job));
    }

    public function test_wording_the_dictionary_does_not_know_is_kept_as_candidate_text(): void
    {
        $job = $this->vacancy(['raw_content' => '<p>Requirements: A Certificate in Basket Weaving is required. Office near the 777 road, open daily for clients.</p>']);
        $this->reply($this->answer(['qualifications' => [$this->item(['name' => 'Certificate in Basket Weaving', 'level' => 'certificate', 'field' => null], 'A Certificate in Basket Weaving is required.')]]));

        $this->extractNow($job, force: true);

        $q = $this->rows('job_qualifications', $job)->sole();
        $this->assertSame('Certificate in Basket Weaving', $q->candidate_text);
        $this->assertNull($q->academic_award_id);
        $this->assertNull($q->directory_qualification_id);
    }

    public function test_an_unknown_skill_is_counted_not_stored(): void
    {
        $job = $this->vacancy(['raw_content' => '<p>Requirements: A diploma. Proficiency in Zorblax is required. Office near the 888 road, open daily for clients.</p>']);
        $this->reply($this->answer(['skills' => [$this->item(['name' => 'Zorblax', 'type' => 'technical'], 'Proficiency in Zorblax is required.')]]));

        $this->extractNow($job);

        $this->assertCount(0, $this->rows('job_skills', $job));
        $this->assertStringContainsString('1 skills not in the dictionary', $this->logs($job));
    }

    public function test_an_answer_that_is_not_json_changes_nothing(): void
    {
        $job = $this->vacancy();
        Http::fake([self::URL => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Sure! The ad asks for a degree.']]]]]])]);

        $this->extractNow($job);

        $this->assertCount(0, $this->rows('job_qualifications', $job));
        $this->assertStringContainsString('AI answer refused', $this->logs($job));
        $this->assertSame('rules', $job->fresh()->analysis_method);
    }

    // ---- the rules' and a person's rows come first ----

    private function existing(Job $job, array $over = []): void
    {
        $award = DB::table('academic_awards')->where('name', 'Bachelor of Engineering')->first();
        DB::table('job_qualifications')->insert($over + [
            'job_id' => $job->id, 'academic_award_id' => $award->id, 'education_level' => $award->level, 'requirement_level' => 'advantage',
            'is_required' => false, 'source' => 'rule', 'confidence' => 80, 'reviewed' => false, 'removed' => false,
        ] + ['field_id' => DB::table('fields_of_study')->where('key', $award->field_key)->value('id')]);
    }

    public function test_an_item_the_rules_already_have_is_not_added_twice(): void
    {
        $job = $this->vacancy();
        $this->existing($job, ['requirement_level' => 'required', 'is_required' => true]);
        $this->reply($this->good());

        $this->extractNow($job, force: true);

        $this->assertCount(1, $this->rows('job_qualifications', $job));
        $this->assertStringContainsString('1 already known', $this->logs($job));
    }

    public function test_when_the_ai_and_the_rules_disagree_on_strength_neither_is_changed_and_the_job_goes_to_review(): void
    {
        $job = $this->vacancy();
        $this->existing($job);   // the rules say advantage; the AI will say mandatory
        $this->reply($this->good());

        $this->extractNow($job);

        $row = $this->rows('job_qualifications', $job)->sole();
        $this->assertSame(['rule', 'advantage'], [$row->source, $row->requirement_level]);
        $this->assertSame('needs_review', $job->fresh()->analysis_status);
        $this->assertStringContainsString('1 disagree with the rules', $this->logs($job));
    }

    public function test_an_item_a_person_removed_does_not_come_back(): void
    {
        $job = $this->vacancy();
        $this->existing($job, ['removed' => true, 'source' => 'person', 'requirement_level' => 'required']);
        $this->reply($this->good());

        $this->extractNow($job, force: true);

        $this->assertSame(1, $this->rows('job_qualifications', $job)->count());
        $this->assertTrue((bool) $this->rows('job_qualifications', $job)->sole()->removed);
    }

    public function test_either_or_groups_are_numbered_after_the_ones_already_stored(): void
    {
        $job = $this->vacancy(['raw_content' => '<p>Requirements: ACCA or CIMA is an added advantage. Office near the 999 road, open daily for clients.</p>']);
        DB::table('job_skills')->insert(['job_id' => $job->id, 'skill_id' => DB::table('skills')->value('id'), 'requirement_level' => 'required', 'alternative_group' => 4, 'source' => 'rule', 'is_required' => true]);
        $quote = 'ACCA or CIMA is an added advantage.';
        $this->reply($this->answer(['professional_registrations' => [
            $this->item(['name' => 'ACCA'], $quote, 'advantage', 0.9, 1),
            $this->item(['name' => 'CIMA'], $quote, 'advantage', 0.9, 1),
        ]]));

        $this->extractNow($job, force: true);

        $groups = $this->rows('job_qualifications', $job)->pluck('alternative_group')->unique()->all();
        $this->assertSame([5], array_map('intval', $groups));   // group 1 became 5: it cannot merge with an existing group
        $this->assertCount(2, $this->rows('job_qualifications', $job));
    }

    // ---- who may be sent ----

    public function test_who_may_be_sent(): void
    {
        $e = app(AiEligibility::class);
        $ok = $this->vacancy();
        $this->assertNull($e->reason($ok));

        $off = $this->vacancy();
        $this->s['boardA']->update(['ai_allowed' => false]);
        $this->assertStringContainsString('does not allow AI', $e->reason($off->fresh()->load('rawPost.source')));
        $this->s['boardA']->update(['ai_allowed' => true, 'retain_raw' => true]);

        $copy = $this->vacancy();
        $copy->update(['status' => 'duplicate']);
        $this->assertStringContainsString('copy or rejected', $e->reason($copy->fresh()->load('rawPost.source')));

        $unread = $this->vacancy();
        $unread->update(['analysis_status' => 'pending']);
        $this->assertStringContainsString('not read it yet', $e->reason($unread->fresh()->load('rawPost.source')));

        $found = $this->vacancy();
        $this->existing($found, ['requirement_level' => 'required']);
        $this->assertStringContainsString('already found', $e->reason($found->fresh()->load('rawPost.source')));
        $this->assertNull($e->reason($found->fresh()->load('rawPost.source'), force: true));

        $plain = $this->vacancy(['raw_content' => '<p>Duties: keep the offices tidy, report faults and follow the daily checklist for each floor of the building.</p>']);
        $this->assertStringContainsString('no sign of a qualification', $e->reason($plain->fresh()->load('rawPost.source')));
    }

    public function test_a_source_that_keeps_no_text_is_never_sent(): void
    {
        $job = $this->vacancy();
        $this->s['boardA']->update(['retain_raw' => false]);

        $this->assertStringContainsString('not kept', app(AiEligibility::class)->reason($job->fresh()->load('rawPost.source'), force: true));
        $this->extractNow($job, force: true);
        Http::assertNothingSent();
    }

    public function test_a_vacancy_is_sent_once(): void
    {
        $job = $this->vacancy();
        $this->reply($this->answer());   // the model finds nothing; still one look is enough

        $this->extractNow($job);
        $this->extractNow($job);

        Http::assertSentCount(1);
        $this->assertStringContainsString('Already read by the AI', $this->logs($job));
    }

    public function test_a_switched_off_service_sends_nothing_and_leaves_the_job_alone(): void
    {
        Http::fake();
        $job = $this->vacancy();
        config(['job_intel.ai.enabled' => false]);

        $this->extractNow($job);

        Http::assertNothingSent();
        $this->assertCount(0, $this->rows('job_qualifications', $job));
    }

    // ---- failures ----

    public function test_a_refused_key_is_logged_and_not_retried(): void
    {
        $job = $this->vacancy();
        Http::fake([self::URL => Http::response(['error' => ['message' => 'bad key']], 403)]);

        $this->extractNow($job);

        Http::assertSentCount(1);
        $this->assertStringContainsString('AI failed', $this->logs($job));
        $this->assertCount(0, $this->rows('job_qualifications', $job));
    }

    public function test_a_passing_failure_is_handed_back_to_the_queue_to_try_again(): void
    {
        $job = $this->vacancy();
        Http::fake([self::URL => Http::response('busy', 503)]);

        $this->expectException(AiFailure::class);
        $this->extractNow($job);
    }

    // ---- the command and the switch ----

    public function test_the_command_lists_without_sending_on_a_dry_run(): void
    {
        Http::fake();
        $this->vacancy();
        config(['job_intel.ai.enabled' => false]);

        $this->artisan('ai:extract', ['--dry-run' => true])->expectsOutputToContain('Would send 1 vacancies')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_the_command_refuses_when_the_ai_is_off(): void
    {
        Http::fake();
        $this->vacancy();
        config(['job_intel.ai.enabled' => false]);

        $this->artisan('ai:extract')->expectsOutputToContain('switched off')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_the_command_sends_eligible_vacancies_up_to_the_limit(): void
    {
        $this->reply($this->good());
        foreach (range(1, 3) as $i) {
            $this->vacancy();
        }

        $this->artisan('ai:extract', ['--limit' => 2])->expectsOutputToContain('Sent 2 vacancies')->assertSuccessful();

        Http::assertSentCount(2);
    }

    public function test_the_command_says_why_one_named_vacancy_cannot_go(): void
    {
        $job = $this->vacancy();
        $job->update(['status' => 'rejected']);

        $this->artisan('ai:extract', ['--job' => $job->id])->expectsOutputToContain('copy or rejected')->assertFailed();
    }

    public function test_every_source_starts_with_ai_off_and_the_form_offers_the_switch(): void
    {
        $this->assertSame(0, DB::table('sources')->where('ai_allowed', true)->where('id', '!=', $this->s['boardA']->id)->count());

        $this->signIn();
        $this->get('/sources/create')->assertOk()->assertSee('name="ai_allowed"', false)->assertSee("Allow an AI service to read this source's ads", false);
    }
}
