<?php

namespace Tests\Feature;

use App\Models\Job;
use App\Models\JobExperience;
use App\Models\JobQualification;
use App\Models\JobSkill;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\MakesJobs;
use Tests\TestCase;

/** Phase 7, step 2: a job can hold what the ad requires, with how strongly, and old rows still work. */
class ExtractedItemsSchemaTest extends TestCase
{
    use MakesJobs, RefreshDatabase;

    private Job $job;

    protected function setUp(): void
    {
        parent::setUp();
        config(['job_intel.collector_contact' => 'owner@example.com']);
        $this->seed(SourceSeeder::class);
        $this->job = $this->ad($this->sourceSet()['company']);
        foreach (['job_skills', 'job_qualifications', 'job_experiences'] as $t) {
            DB::table($t)->where('job_id', $this->job->id)->delete();   // these tests are about the schema, not about what the reader found
        }
        $this->job->forceFill(['analysis_status' => 'pending', 'analysis_method' => null, 'analysis_version' => 0, 'analysed_at' => null, 'occupation_code' => null, 'seniority' => null])->save();
    }

    private function id(string $table, string $key = 'key', ?string $value = null): int
    {
        $q = DB::table($table);

        return (int) ($value ? $q->where($key, $value)->value('id') : $q->value('id'));
    }

    // ---- the brief's Financial Accountant, as stored ----

    public function test_a_job_can_be_saved_with_everything_the_ad_requires(): void
    {
        $job = $this->job;
        $excel = $this->id('skills', 'name', 'Microsoft Excel');
        $award = DB::table('academic_awards')->where('level', 'bachelor')->value('id');
        $acca = $this->id('professional_qualifications', 'key', 'acca');
        $cima = $this->id('professional_qualifications', 'key', 'cima');
        $accounting = $this->id('fields_of_study', 'key', 'accounting');

        $job->skills()->create(['skill_id' => $excel, 'requirement_level' => 'required', 'source' => 'rule', 'confidence' => 90, 'evidence' => 'Proficiency in Microsoft Excel.']);
        $job->qualifications()->create(['education_level' => 'bachelor', 'academic_award_id' => $award, 'field_id' => $accounting, 'requirement_level' => 'required', 'alternative_group' => 1, 'confidence' => 85]);
        $job->qualifications()->create(['professional_qualification_id' => $acca, 'requirement_level' => 'advantage', 'alternative_group' => 2, 'evidence' => 'ACCA or CIMA qualification is an added advantage.']);
        $job->qualifications()->create(['professional_qualification_id' => $cima, 'requirement_level' => 'advantage', 'alternative_group' => 2]);
        $job->experiences()->create(['min_years' => 3, 'area' => 'relevant', 'requirement_level' => 'required']);
        $job->update([
            'occupation_code' => '2411', 'seniority' => 'standard', 'department' => 'Finance', 'salary_period' => 'month',
            'application_method' => 'email', 'analysis_status' => 'done', 'analysis_method' => 'rules', 'analysis_version' => 1, 'analysed_at' => now(),
        ]);

        $job = $job->fresh();
        $this->assertCount(1, $job->skills);
        $this->assertCount(3, $job->qualifications);
        $this->assertSame(1, $job->experiences->count());
        $this->assertSame('advantage', $job->qualifications[1]->requirement_level);
        $this->assertSame($job->qualifications[1]->alternative_group, $job->qualifications[2]->alternative_group); // ACCA or CIMA: one either-or group
        $this->assertNotSame($job->qualifications[0]->alternative_group, $job->qualifications[1]->alternative_group);
        $this->assertSame('2411', $job->occupation_code);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $job->analysed_at);
    }

    // ---- old rows and queries keep working ----

    public function test_rows_written_the_old_way_get_matching_defaults(): void
    {
        $skill = $this->id('skills');
        DB::table('job_skills')->insert(['job_id' => $this->job->id, 'skill_id' => $skill, 'is_required' => true]);
        DB::table('job_experiences')->insert(['job_id' => $this->job->id, 'min_years' => 2]);

        $item = JobSkill::first();
        $this->assertSame('required', $item->requirement_level);
        $this->assertSame('rule', $item->source);
        $this->assertNull($item->alternative_group);
        $this->assertFalse($item->reviewed);
        $this->assertFalse($item->removed);
        $this->assertSame(0, $item->confidence);
        $this->assertSame('required', JobExperience::first()->requirement_level);
    }

    public function test_a_qualification_row_no_longer_needs_the_old_reference_list(): void
    {
        DB::table('job_qualifications')->insert(['job_id' => $this->job->id, 'education_level' => 'diploma']);   // no qualification_id
        $old = DB::table('qualifications')->insertGetId(['name' => 'Legacy', 'level' => 'diploma', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('job_qualifications')->insert(['job_id' => $this->job->id, 'qualification_id' => $old, 'is_required' => false]);  // the old way still works

        $this->assertSame(2, JobQualification::count());
    }

    public function test_a_new_job_is_waiting_to_be_read(): void
    {
        $job = $this->job->fresh();

        $this->assertSame('pending', $job->analysis_status);
        $this->assertSame(0, $job->analysis_version);
        $this->assertNull($job->analysed_at);
        $this->assertNull($job->occupation_code);
        $this->assertContains($job->analysis_status, Job::ANALYSIS_STATUSES);
    }

    // ---- links ----

    public function test_removing_a_dictionary_entry_keeps_the_item_but_unlinks_it(): void
    {
        $acca = $this->id('professional_qualifications', 'key', 'acca');
        $item = $this->job->qualifications()->create(['professional_qualification_id' => $acca, 'candidate_text' => 'ACCA']);

        DB::table('professional_qualifications')->where('id', $acca)->delete();

        $this->assertNull($item->fresh()->professional_qualification_id);
        $this->assertSame('ACCA', $item->fresh()->candidate_text);
    }

    public function test_deleting_a_job_deletes_its_items(): void
    {
        $this->job->skills()->create(['skill_id' => $this->id('skills')]);
        $this->job->qualifications()->create(['education_level' => 'bachelor']);
        $this->job->experiences()->create(['min_years' => 1]);

        $this->job->delete();

        $this->assertSame([0, 0, 0], [JobSkill::count(), JobQualification::count(), JobExperience::count()]);
    }

    public function test_a_skill_cannot_be_listed_twice_for_one_job(): void
    {
        $skill = $this->id('skills');
        $this->job->skills()->create(['skill_id' => $skill]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->job->skills()->create(['skill_id' => $skill]);
    }

    // ---- levels, and what counts ----

    public function test_only_required_and_preferred_count_and_removed_items_never_do(): void
    {
        $this->job->qualifications()->create(['education_level' => 'bachelor', 'requirement_level' => 'required']);
        $this->job->qualifications()->create(['education_level' => 'masters', 'requirement_level' => 'preferred']);
        $this->job->qualifications()->create(['education_level' => 'phd', 'requirement_level' => 'advantage']);
        $this->job->qualifications()->create(['education_level' => 'diploma', 'requirement_level' => 'mentioned']);
        $this->job->qualifications()->create(['education_level' => 'certificate', 'requirement_level' => 'required', 'removed' => true]);

        $this->assertEqualsCanonicalizing(['bachelor', 'masters'], JobQualification::counted()->pluck('education_level')->all());
        $this->assertCount(4, JobQualification::live()->get());   // advantage and mentioned are kept, just not counted
        $this->assertSame(['required', 'preferred', 'advantage', 'mentioned'], JobQualification::LEVELS);
    }

    public function test_an_item_a_person_decided_is_final(): void
    {
        $this->assertFalse((new JobSkill(['source' => 'rule']))->isFinal());
        $this->assertTrue((new JobSkill(['source' => 'person']))->isFinal());
        $this->assertTrue((new JobSkill(['source' => 'ai', 'reviewed' => true]))->isFinal());
        $this->assertTrue((new JobSkill(['source' => 'rule', 'removed' => true]))->isFinal());
    }

    // ---- evidence follows the raw text ----

    public function test_clearing_old_raw_text_clears_the_evidence_but_keeps_what_was_learned(): void
    {
        $old = $this->job;
        $old->rawPost->update(['first_seen_at' => now()->subDays(200)]);
        $fresh = $this->ad($this->sourceSet()['boardA'], ['employer' => 'Other Ltd'], ['raw_title' => 'Cook', 'raw_content' => '<p>'.self::cleanerBody().'</p>']);

        foreach ([$old, $fresh] as $job) {
            $job->skills()->create(['skill_id' => $this->id('skills'), 'evidence' => 'Proficiency in Excel']);
            $job->qualifications()->create(['education_level' => 'bachelor', 'evidence' => 'Bachelor degree']);
            $job->experiences()->create(['min_years' => 3, 'evidence' => 'Minimum 3 years']);
        }

        Artisan::call('raw:purge');

        foreach (['job_skills', 'job_qualifications', 'job_experiences'] as $table) {
            $this->assertNull(DB::table($table)->where('job_id', $old->id)->value('evidence'), "{$table} evidence of the purged ad");
            $this->assertSame(1, DB::table($table)->where('job_id', $old->id)->count(), "{$table} item kept");
            $this->assertNotNull(DB::table($table)->where('job_id', $fresh->id)->value('evidence'), "{$table} evidence of a recent ad");
        }
    }

    // ---- the migration itself ----

    public function test_the_migration_rolls_back_and_forward(): void
    {
        $this->job->qualifications()->create(['education_level' => 'bachelor']);

        Artisan::call('migrate:rollback', ['--step' => 4]);   // the link stats, the fold, the directory tables, then this migration
        $this->assertFalse(Schema::hasColumn('jobs', 'analysis_status'));
        $this->assertFalse(Schema::hasColumn('job_skills', 'requirement_level'));
        $this->assertFalse(Schema::hasColumn('job_qualifications', 'academic_award_id'));

        Artisan::call('migrate');
        $this->assertTrue(Schema::hasColumn('jobs', 'analysis_status'));
        $this->assertTrue(Schema::hasColumn('job_qualifications', 'field_id'));
        $this->assertTrue(Schema::hasColumn('job_experiences', 'alternative_group'));
    }
}
