<?php

namespace Tests\Feature;

use App\Extraction\RequirementReader;
use App\Models\Job;
use App\Processing\RequirementApplier;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesJobs;
use Tests\TestCase;

/** Phase 7, step 3: what an ad asks for, read from its text, with how strongly and in the ad's own words. */
class RequirementReaderTest extends TestCase
{
    use MakesJobs, RefreshDatabase;

    private function read(string $body, ?string $education = null, ?string $experience = null): array
    {
        return app(RequirementReader::class)->read($body, $education, $experience);
    }

    /** @return array<string, array> the qualification items by named key, or "level/fieldKey" */
    private function quals(array $read): array
    {
        $fields = DB::table('fields_of_study')->pluck('key', 'id');
        $out = [];
        foreach ($read['qualifications'] as $q) {
            $label = $q['named_key'] ?? (($q['education_level'] ?? '-').'/'.($q['field_id'] ? $fields[$q['field_id']] : ($q['candidate_text'] ? 'candidate:'.$q['candidate_text'] : '-')));
            $out[$label] = $q;
        }

        return $out;
    }

    // ---- the same qualification, worded several ways ----

    public function test_different_wordings_of_one_qualification_reach_the_same_match(): void
    {
        foreach (["Bachelor's Degree in Accounting", 'BSc in Accountancy', 'First Degree in Accounting', 'Degree in Accounting'] as $wording) {
            $q = $this->quals($this->read("Requirements:\n{$wording}"));
            $this->assertArrayHasKey('bachelor/accounting', $q, $wording);
            $this->assertSame($wording, $q['bachelor/accounting']['evidence'], 'the exact wording is kept');
        }

        $q = $this->quals($this->read("Requirements:\nDegree in Accounting and Finance"));
        $this->assertCount(1, $q, 'accounting and finance is one field, not two requirements');
    }

    public function test_a_named_award_is_not_also_counted_as_level_and_field(): void
    {
        $q = $this->quals($this->read("Requirements:\nBachelor of Engineering in Mechanical Engineering"));

        $this->assertCount(1, $q);
        $this->assertSame(DB::table('fields_of_study')->where('key', 'mechanical-engineering')->value('id'), $q['beng']['field_id'], 'the award keeps the narrower field the ad gave');
    }

    // ---- how strongly ----

    public function test_requirement_level_comes_from_the_cue_words_and_the_section(): void
    {
        $read = $this->read("Key Responsibilities:\n- Prepare monthly reports using Excel.\nRequirements:\n- Applicants must hold a Diploma in Accounting.\n- ACCA is an added advantage.\nDesirable:\n- CIMA qualification.");
        $q = $this->quals($read);

        $this->assertSame('required', $q['diploma/accounting']['requirement_level']);
        $this->assertSame('advantage', $q['acca']['requirement_level']);
        $this->assertSame('preferred', $q['cima']['requirement_level']);

        $excel = DB::table('skills')->where('name', 'like', '%Excel%')->value('id');
        $this->assertSame('mentioned', collect($read['skills'])->firstWhere('skill_id', $excel)['requirement_level'], 'a duty mentions a skill, it does not require it');
    }

    public function test_a_duty_never_becomes_a_qualification_or_experience_requirement(): void
    {
        $read = $this->read("Duties:\n- Supervise staff with a degree of independence.\n- Guide interns with 2 years experience.");

        $this->assertSame([], $read['qualifications']);
        $this->assertSame([], $read['experiences']);
    }

    // ---- either-or ----

    public function test_either_or_credentials_share_an_alternative_group(): void
    {
        $q = $this->quals($this->read("Requirements:\nACCA or CIMA qualification.\nCPA is required."));

        $this->assertNotNull($q['acca']['alternative_group']);
        $this->assertSame($q['acca']['alternative_group'], $q['cima']['alternative_group']);
        $this->assertNull($q['cpa']['alternative_group']);
    }

    public function test_diploma_or_degree_is_two_alternatives_sharing_the_field(): void
    {
        $q = $this->quals($this->read("Requirements:\nA Diploma or Degree in Accounting."));

        $this->assertArrayHasKey('diploma/accounting', $q);
        $this->assertArrayHasKey('bachelor/accounting', $q);
        $this->assertNotNull($q['diploma/accounting']['alternative_group']);
        $this->assertSame($q['diploma/accounting']['alternative_group'], $q['bachelor/accounting']['alternative_group']);
    }

    // ---- never guessing ----

    public function test_wording_the_taxonomy_does_not_know_is_kept_as_a_candidate_for_review(): void
    {
        $read = $this->read('Requirements:'."\n".'Degree in Underwater Basket Weaving.');
        $q = $this->quals($read);

        $this->assertArrayHasKey('bachelor/candidate:Underwater Basket Weaving', $q);
        $this->assertNull($q['bachelor/candidate:Underwater Basket Weaving']['field_id']);
        $this->assertTrue($read['review']);
    }

    public function test_words_that_only_look_like_qualifications_are_ignored(): void
    {
        $read = $this->read("Requirements:\nA certificate of good conduct and a high degree of accuracy.");

        $this->assertSame([], $read['qualifications']);
    }

    // ---- experience ----

    public function test_experience_is_read_in_years_with_its_area(): void
    {
        $read = $this->read("Requirements:\nMinimum 3 years experience in mining operations.");

        $this->assertCount(1, $read['experiences']);
        $this->assertSame(3, $read['experiences'][0]['min_years']);
        $this->assertSame('mining operations', $read['experiences'][0]['area']);
        $this->assertSame('required', $read['experiences'][0]['requirement_level']);
    }

    public function test_a_contract_length_is_not_experience(): void
    {
        $this->assertSame([], $this->read("Requirements:\nA 2 year contract renewable.")['experiences']);
    }

    public function test_published_data_is_read_and_marked_as_published(): void
    {
        $read = $this->read('', "Bachelor's Degree in Accounting", '5 years experience');

        $this->assertSame('published', $this->quals($read)['bachelor/accounting']['source']);
        $this->assertSame(5, $read['experiences'][0]['min_years']);
        $this->assertFalse($read['read_text']);
    }

    // ---- stored on the job ----

    private function job(string $body): Job
    {
        config(['job_intel.collector_contact' => 'owner@example.com']);
        $this->seed(SourceSeeder::class);

        return $this->ad($this->sourceSet()['company'], [], ['raw_title' => 'Accountant', 'raw_content' => '<p>'.nl2br(e($body)).'</p>']);
    }

    public function test_processing_stores_the_items_and_the_job_classification(): void
    {
        $job = $this->job("Requirements:\nACCA or CIMA.\nBachelor's Degree in Accounting.\n3 years experience in audit.")->refresh();

        $this->assertSame('done', $job->analysis_status);
        $this->assertSame('rules', $job->analysis_method);
        $this->assertSame(RequirementReader::VERSION, $job->analysis_version);
        $this->assertNotNull($job->analysed_at);
        $this->assertNotNull($job->seniority);

        $named = $job->qualifications()->whereNotNull('professional_qualification_id')->get();
        $this->assertCount(2, $named);
        $this->assertSame(DB::table('directory_qualifications')->where('key', 'professional.acca')->value('id'),
            $named->firstWhere('professional_qualification_id', DB::table('professional_qualifications')->where('key', 'acca')->value('id'))->directory_qualification_id);
        $this->assertSame(1, $job->experiences()->count());
        $this->assertTrue((bool) $job->qualifications()->where('education_level', 'bachelor')->value('is_required'));
    }

    public function test_reading_again_replaces_our_reading_but_keeps_a_persons_decisions(): void
    {
        $job = $this->job("Requirements:\nACCA is required.\nBachelor's Degree in Accounting.");
        $job->qualifications()->whereNotNull('professional_qualification_id')->update(['removed' => true]);   // a person removed it
        $job->qualifications()->where('education_level', 'bachelor')->update(['reviewed' => true, 'requirement_level' => 'preferred']);

        app(RequirementApplier::class)->apply($job->refresh(), $job->rawPost);

        $this->assertSame(1, $job->qualifications()->whereNotNull('professional_qualification_id')->count(), 'removed item is neither brought back nor duplicated');
        $this->assertTrue((bool) $job->qualifications()->whereNotNull('professional_qualification_id')->value('removed'));
        $this->assertSame(1, $job->qualifications()->where('education_level', 'bachelor')->count());
        $this->assertSame('preferred', $job->qualifications()->where('education_level', 'bachelor')->value('requirement_level'));
    }

    public function test_an_ad_with_no_text_and_no_published_facts_is_marked_no_text(): void
    {
        $job = $this->job('Requirements: ACCA.');
        $job->rawPost->update(['raw_content' => null]);
        $job->update(['qualification_text' => null, 'experience_text' => null]);

        app(RequirementApplier::class)->apply($job->refresh(), $job->rawPost->refresh());

        $this->assertSame('no_text', $job->fresh()->analysis_status);
        $this->assertSame('published_only', $job->fresh()->analysis_method);
    }
}
