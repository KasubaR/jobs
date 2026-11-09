<?php

namespace Tests\Feature;

use App\Extraction\RequirementReader;
use App\Import\DirectoryImporter;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Concerns\MakesJobs;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/** Roles, official-vs-comparison relationships, the change log, and the employer's own phrase kept for good. */
class ProvenanceTest extends TestCase
{
    use MakesJobs, RefreshDatabase, SignsIn;

    private function import(string $type, string $csv): array
    {
        return app(DirectoryImporter::class)->import($type, $csv);
    }

    private function qualifications(): void
    {
        $this->import('qualifications', "key,name,kind\nq.one,Qualification One,academic\nq.two,Qualification Two,academic\n");
    }

    private function logs(string $table, string $field): \Illuminate\Support\Collection
    {
        return DB::table('data_change_logs')->where('table_name', $table)->where('field', $field)->orderBy('id')->get();
    }

    // ---- organization roles ----

    public function test_every_organization_has_its_role_and_one_record_can_hold_several(): void
    {
        $this->assertSame(0, DB::table('organizations')->whereNotIn('id', DB::table('organization_roles')->select('organization_id'))->count(), 'folded organizations got their role');

        $this->import('organizations', "key,name,type\nunza,University of Zambia,university|awarding_body\n");
        $this->import('organizations', "key,name,type\nunza,University of Zambia,university\n");   // a later file naming one role takes nothing away

        $org = DB::table('organizations')->where('key', 'unza')->first();
        $this->assertSame('university', $org->type, 'the first role is the primary one');
        $this->assertEqualsCanonicalizing(['university', 'awarding_body'], DB::table('organization_roles')->where('organization_id', $org->id)->pluck('role')->all());
        $this->assertSame(1, DB::table('organizations')->where('key', 'unza')->count(), 'one record, not one per role');
    }

    public function test_an_unknown_role_is_refused(): void
    {
        $result = $this->import('organizations', "key,name,type\nx,X,university|hobby\n");

        $this->assertSame(0, $result['created']);
        $this->assertStringContainsString('"type" is "hobby"', $result['errors'][0]);
    }

    // ---- relationships ----

    public function test_a_relationship_is_only_official_with_a_source_and_a_date(): void
    {
        $this->qualifications();

        $r = $this->import('relationships', implode("\n", [
            'from,to,type,basis,source_url,verified_on',
            'q.one,q.two,equivalent,,,',                                                         // no basis: a comparison
            'q.two,q.one,equivalent,official,,',                                                  // official, no source: refused
            'q.one,q.two,progression,official,https://example.test/zaqa,',                        // official, no date: refused
            'q.one,q.two,exemption,official,https://example.test/zaqa,2026-10-02',                // official, sourced and dated
        ]));

        $this->assertSame(2, $r['created']);
        $this->assertCount(2, $r['errors']);
        $this->assertStringContainsString('official determination needs a source', $r['errors'][0]);

        $rows = DB::table('qualification_relationships')->get()->keyBy('type');
        $this->assertSame(['comparison', null], [$rows['equivalent']->basis, $rows['equivalent']->verified_at]);
        $this->assertSame('official', $rows['exemption']->basis);
        $this->assertNotNull($rows['exemption']->verified_at);
        $this->assertSame(1, DB::table('evidence')->where('subject_type', \App\Models\QualificationRelationship::class)->where('claim', 'relationship')->count());
    }

    public function test_a_file_without_a_basis_column_does_not_downgrade_an_official_relationship(): void
    {
        $this->qualifications();
        $this->import('relationships', "from,to,type,basis,source_url,verified_on\nq.one,q.two,equivalent,official,https://example.test/x,2026-10-02\n");

        $this->import('relationships', "from,to,type,note\nq.one,q.two,equivalent,a note\n");

        $row = DB::table('qualification_relationships')->first();
        $this->assertSame(['official', 'a note'], [$row->basis, $row->note]);
        $this->assertNotNull($row->verified_at);
    }

    public function test_bad_relationships_are_refused(): void
    {
        $this->qualifications();

        $r = $this->import('relationships', "from,to,type\nq.one,q.one,equivalent\nq.one,nope,equivalent\nq.one,q.two,twin\n");

        $this->assertSame(0, $r['created']);
        $this->assertStringContainsString('related to itself', $r['errors'][0]);
        $this->assertStringContainsString('"to" "nope" is not known', $r['errors'][1]);
        $this->assertStringContainsString('"type" is "twin"', $r['errors'][2]);
    }

    public function test_the_profile_labels_each_relationship_as_official_or_a_comparison(): void
    {
        $this->signIn();
        $this->qualifications();
        $this->import('relationships', "from,to,type,basis,source_url,verified_on\nq.one,q.two,equivalent,,,\nq.one,q.two,progression,official,https://example.test/zaqa,2026-10-02\n");

        $this->get(route('directory.show', DB::table('directory_qualifications')->where('key', 'q.one')->value('id')))->assertOk()
            ->assertSee('Unverified comparison')->assertSee('Official determination, verified 2 Oct 2026')->assertSee('https://example.test/zaqa');
    }

    // ---- the change log ----

    public function test_an_import_logs_each_field_it_set_or_changed_and_nothing_when_nothing_changed(): void
    {
        $this->import('organizations', "key,name,type\norg,First name,university\n");
        $this->assertGreaterThan(0, DB::table('data_change_logs')->where('table_name', 'organizations')->where('action', 'created')->count());
        $before = DB::table('data_change_logs')->count();

        $this->import('organizations', "key,name,type\norg,First name,university\n");
        $this->assertSame($before, DB::table('data_change_logs')->count(), 'the same file again leaves no entries');

        $this->import('organizations', "key,name,type\norg,Second name,university\n");
        $name = $this->logs('organizations', 'name');
        $this->assertCount(1, $name, 'creating is one entry; only the later rename is a field change');
        $this->assertSame(['First name', 'Second name'], [$name[0]->old_value, $name[0]->new_value]);
        $this->assertSame(['updated', 'command', 'csv_import'], [$name[0]->action, $name[0]->actor, $name[0]->via]);
        $created = DB::table('data_change_logs')->where('table_name', 'organizations')->where('action', 'created')->first();
        $this->assertNull($created->field);
        $this->assertSame('First name', json_decode($created->new_value, true)['name']);
    }

    public function test_the_review_screen_and_the_employers_screen_log_who_changed_what(): void
    {
        config(['job_intel.collector_contact' => 'owner@example.com']);
        $this->seed(SourceSeeder::class);
        $user = $this->signIn();

        $job = $this->ad($this->sourceSet()['company'], ['employer' => 'Some Employer Ltd'], ['raw_title' => 'Officer', 'raw_content' => '<p>Requirements:<br>Diploma in Security Studies.</p>']);
        $field = (int) DB::table('fields_of_study')->where('key', 'social-sciences')->value('id');

        $this->post(route('review.store'), ['wording' => 'security studies', 'level' => 'diploma', 'action' => 'map', 'field_id' => $field])->assertRedirect();
        $this->put(route('employers.update', $job->employer_id), ['industry_id' => 2])->assertRedirect();
        $this->put(route('employers.update', $job->employer_id), ['industry_id' => 2]);   // same value: not logged again

        $fieldLog = $this->logs('job_qualifications', 'field_id')->first();
        $this->assertSame([null, (string) $field, $user->email, 'review_screen'], [$fieldLog->old_value, $fieldLog->new_value, $fieldLog->actor, $fieldLog->via]);
        $this->assertSame('security studies', json_decode($this->logs('fields_of_study', 'aliases')->first()->new_value, true)[count(json_decode($this->logs('fields_of_study', 'aliases')->first()->new_value, true)) - 1]);
        $industry = $this->logs('employers', 'industry_id');
        $this->assertCount(1, $industry);
        $this->assertSame([null, '2', $user->email, 'employers_screen'], [$industry[0]->old_value, $industry[0]->new_value, $industry[0]->actor, $industry[0]->via]);
    }

    public function test_the_profile_shows_its_change_history(): void
    {
        $this->signIn();
        $this->qualifications();
        $this->import('qualifications', "key,name,kind\nq.one,Qualification One (renamed),academic\n");

        $this->get(route('directory.show', DB::table('directory_qualifications')->where('key', 'q.one')->value('id')))->assertOk()
            ->assertSee('Change history')->assertSee('csv import')->assertSee('Qualification One (renamed)');
    }

    // ---- the employer's own phrase ----

    private function read(string $text): array
    {
        return app(RequirementReader::class)->read($text);
    }

    public function test_each_qualification_item_keeps_the_short_phrase_the_employer_wrote(): void
    {
        $items = collect($this->read("Requirements:\nCandidates must hold a First Degree in Accounting and have 3 years experience.\nACCA or CIMA is an advantage.\nBSc in Accountancy, preferably.")['qualifications']);

        $this->assertSame('First Degree in Accounting', $items->firstWhere('education_level', 'bachelor')['original_text']);
        $this->assertSame('ACCA', $items->firstWhere('named_key', 'acca')['original_text']);
        $this->assertSame('CIMA', $items->firstWhere('named_key', 'cima')['original_text']);
        $this->assertTrue($items->every(fn ($i) => mb_strlen($i['original_text']) <= 120));
    }

    public function test_the_phrase_stays_when_the_raw_text_and_the_sentence_are_purged(): void
    {
        config(['job_intel.collector_contact' => 'owner@example.com']);
        $this->seed(SourceSeeder::class);
        $job = $this->ad($this->sourceSet()['company'], [], ['raw_title' => 'Accountant', 'raw_content' => '<p>Requirements:<br>ACCA is required.</p>']);
        $this->assertSame('ACCA', $job->qualifications()->first()->original_text);
        $this->assertNotNull($job->qualifications()->first()->evidence);

        $job->rawPost->update(['first_seen_at' => now()->subDays(200)]);
        Artisan::call('raw:purge');

        $row = $job->qualifications()->first();
        $this->assertNull($row->evidence, 'the sentence goes with the raw text');
        $this->assertSame('ACCA', $row->original_text, 'the employer\'s own phrase stays');
    }

    public function test_the_importer_still_refuses_an_unknown_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->import('people', "a\n1\n");
    }
}
