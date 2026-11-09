<?php

namespace Tests\Feature;

use App\Models\DirectoryQualification;
use App\Models\Evidence;
use App\Models\Organization;
use App\Models\Programme;
use App\Models\Recognition;
use App\Taxonomy\DefaultTaxonomy;
use App\Taxonomy\DirectoryFold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Database\Seeders\SourceSeeder;
use Tests\Concerns\MakesJobs;
use Tests\TestCase;

/** Qualification Directory: the schema, and the fold of the taxonomy tables into it. */
class QualificationDirectoryTest extends TestCase
{
    use MakesJobs, RefreshDatabase;

    public function test_the_taxonomy_is_folded_in_by_kind_and_nothing_is_dropped(): void
    {
        foreach ([['academic', 'academic_awards'], ['professional', 'professional_qualifications'], ['registration', 'professional_registrations']] as [$kind, $table]) {
            $this->assertSame(DB::table($table)->count(), DirectoryQualification::where('kind', $kind)->count(), $kind);
        }
        $this->assertSame(7, DB::table('qualification_levels')->count());
        $this->assertSame(0, DirectoryQualification::where('status', '!=', 'unverified')->count(), 'a dictionary entry is not a verified fact');
    }

    public function test_a_folded_row_keeps_its_level_field_and_body(): void
    {
        $beng = DirectoryQualification::where('key', 'academic.beng')->firstOrFail();
        $this->assertSame('bachelor', $beng->level->key);
        $this->assertSame('engineering', DB::table('fields_of_study')->where('id', $beng->field_id)->value('key'));

        $acca = DirectoryQualification::where('key', 'professional.acca')->firstOrFail();
        $this->assertSame('Association of Chartered Certified Accountants', $acca->awardingOrganization->name);
        $this->assertContains('ACCA', $acca->aliases);
    }

    public function test_installing_again_changes_nothing_and_never_overwrites_an_edit(): void
    {
        $count = DirectoryQualification::count();
        DirectoryQualification::where('key', 'professional.acca')->update(['name' => 'ACCA (edited)', 'status' => 'verified', 'origin' => 'person']);

        $added = DirectoryFold::install();

        $this->assertSame(0, $added['directory_qualifications']);
        $this->assertSame($count, DirectoryQualification::count());
        $this->assertSame('ACCA (edited)', DirectoryQualification::where('key', 'professional.acca')->value('name'));
    }

    public function test_existing_job_rows_are_pointed_at_the_directory(): void
    {
        config(['job_intel.collector_contact' => 'owner@example.com']);
        $this->seed(SourceSeeder::class);
        $job = $this->ad($this->sourceSet()['company'])->id;
        DB::table('job_qualifications')->where('job_id', $job)->delete();   // the schema is under test, not the reader
        $acca = DB::table('professional_qualifications')->where('key', 'acca')->value('id');
        DB::table('job_qualifications')->insert(['job_id' => $job, 'professional_qualification_id' => $acca]);

        $this->assertSame(1, DirectoryFold::install()['job_qualifications']);

        $this->assertSame(
            DirectoryQualification::where('key', 'professional.acca')->value('id'),
            DB::table('job_qualifications')->where('job_id', $job)->value('directory_qualification_id'),
        );
        $this->assertSame(0, DirectoryFold::install()['job_qualifications']);
    }

    public function test_existence_recognition_availability_and_evidence_are_separate_rows(): void
    {
        $q = DirectoryQualification::where('key', 'professional.acca')->firstOrFail();
        $zica = Organization::create(['key' => 'zica', 'name' => 'Zambia Institute of Chartered Accountants', 'type' => 'regulator']);
        $uni = Organization::create(['key' => 'unza', 'name' => 'University of Zambia', 'type' => 'university']);

        $this->assertSame(0, $q->recognitions()->count(), 'existing says nothing about recognition');

        $r = Recognition::create(['qualification_id' => $q->id, 'authority_id' => $zica->id, 'status' => 'recognised']);
        $p = Programme::create(['provider_id' => $uni->id, 'qualification_id' => $q->id, 'mode' => 'part_time']);
        $r->evidence()->create(['claim' => 'recognition', 'url' => 'https://example.test/register', 'retrieved_on' => '2026-11-09', 'verified_at' => now()]);

        $this->assertSame(1, $q->recognitions()->count());
        $this->assertSame(1, $q->programmes()->count());
        $this->assertSame(1, Evidence::where('subject_type', Recognition::class)->where('subject_id', $r->id)->count());
        $this->assertSame(0, Evidence::where('subject_type', Programme::class)->where('subject_id', $p->id)->count(), 'each fact has its own evidence');
        $this->assertNotNull($r->fresh()->evidence->first()->verified_at);
    }

    public function test_one_authority_has_one_statement_per_qualification(): void
    {
        $q = DirectoryQualification::first();
        $org = Organization::create(['key' => 'x', 'name' => 'X', 'type' => 'regulator']);
        Recognition::create(['qualification_id' => $q->id, 'authority_id' => $org->id]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Recognition::create(['qualification_id' => $q->id, 'authority_id' => $org->id]);
    }

    public function test_the_taxonomy_installer_alone_leaves_the_directory_alone(): void
    {
        $count = DirectoryQualification::count();
        DefaultTaxonomy::install();
        $this->assertSame($count, DirectoryQualification::count());
    }
}
