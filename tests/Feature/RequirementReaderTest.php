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

    // ---- found by reading real ads ----

    public function test_an_employer_describing_its_own_age_is_not_experience(): void
    {
        $read = $this->read("About us:\nWith 75 years of experience, our focus is on children.\nRequirements:\nMinimum of 3 years relevant experience in a finance role.");

        $this->assertSame([3], array_column($read['experiences'], 'min_years'));
    }

    public function test_a_degree_type_with_a_field_is_one_item_the_same_as_the_plain_wording(): void
    {
        $q = $this->read("Requirements:
BSc in Accountancy.");

        $this->assertCount(1, $q['qualifications']);
        $this->assertSame('bachelor', $q['qualifications'][0]['education_level']);
        $this->assertSame(DB::table('fields_of_study')->where('key', 'accounting')->value('id'), $q['qualifications'][0]['field_id']);
    }

    public function test_school_subjects_are_not_a_field_of_study(): void
    {
        $read = $this->read("Requirements:\nSchool Certificate with credit and above in Mathematics and English.");

        $this->assertSame([null], array_column($read['qualifications'], 'field_id'));
    }

    public function test_an_experience_rule_that_names_two_levels_is_not_a_qualification(): void
    {
        $read = $this->read("Requirements:\nMinimum of 5 years (with high school diploma) or 2 years (with bachelor's degree) of relevant experience.");

        $this->assertSame([], array_filter($read['qualifications'], fn ($q) => $q['candidate_text'] !== null));
    }

    public function test_or_its_equivalent_is_not_part_of_the_field(): void
    {
        $read = $this->read("Requirements:\nDegree in Accounting or its equivalent.");

        $this->assertArrayHasKey('bachelor/accounting', $this->quals($read));
    }

    public function test_a_title_with_the_employer_or_a_project_still_finds_its_occupation(): void
    {
        $job = $this->job('Requirements: ACCA.');
        $job->rawPost->update(['raw_title' => 'Accountant – Treasury at Some Employer Ltd']);
        $job->update(['title' => 'Accountant – Treasury at Some Employer Ltd', 'occupation_code' => null]);

        app(RequirementApplier::class)->apply($job->refresh(), $job->rawPost);

        $this->assertSame('2411', $job->fresh()->occupation_code);
    }

    // ---- stored on the job ----

    private function job(string $body, int $n = 0): Job
    {
        config(['job_intel.collector_contact' => 'owner@example.com']);
        if (! \App\Models\Source::exists()) {
            $this->seed(SourceSeeder::class);
        }

        return $this->ad($this->sourceSet()['company'], ['employer' => "Employer {$n} Ltd"], ['raw_title' => 'Accountant', 'raw_content' => '<p>'.nl2br(e($body)).'</p>']);
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

    public function test_the_four_wordings_end_up_on_one_directory_entry(): void
    {
        $ids = [];
        foreach (["Bachelor's Degree in Accounting", 'BSc in Accountancy', 'First Degree in Accounting', 'Degree in Accounting'] as $i => $wording) {
            $job = $this->job("Requirements:\n{$wording}", $i)->refresh();
            $row = $job->qualifications()->first();
            $this->assertSame($wording, $row->evidence, 'the ad\'s own wording is kept on the item');
            $ids[] = $row->directory_qualification_id;
        }

        $this->assertNotNull($ids[0]);
        $this->assertCount(1, array_unique($ids));

        $entry = \App\Models\DirectoryQualification::find($ids[0]);
        $this->assertSame("Bachelor's degree in Accounting", $entry->name);
        $this->assertSame('unverified', $entry->status);
        $this->assertSame('extraction', $entry->origin);
        $this->assertSame(1, \App\Models\DirectoryQualification::where('key', 'academic.bachelor.accounting')->count());
    }

    public function test_a_level_with_no_field_or_an_unknown_field_gets_no_directory_entry(): void
    {
        $before = \App\Models\DirectoryQualification::count();
        $this->job("Requirements:\nA degree.\nDiploma in Underwater Basket Weaving.");

        $this->assertSame($before, \App\Models\DirectoryQualification::count());
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
