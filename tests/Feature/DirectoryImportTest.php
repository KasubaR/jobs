<?php

namespace Tests\Feature;

use App\Import\DirectoryImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/** Loading directory records from CSV: organizations, qualifications, recognitions, programmes, each with its source. */
class DirectoryImportTest extends TestCase
{
    use RefreshDatabase;

    private function import(string $type, string $csv): array
    {
        return app(DirectoryImporter::class)->import($type, $csv);
    }

    private function orgs(): void
    {
        $this->import('organizations', "key,name,type,website,aliases\nzica,Zambia Institute of Chartered Accountants,regulator,https://zica.example.test,ZICA|The Institute\ncavendish,Cavendish University,university,,\n");
    }

    public function test_organizations_are_created_then_updated_not_duplicated(): void
    {
        $this->orgs();
        $again = $this->import('organizations', "key,name,type\nzica,ZICA (renamed),regulator\n");

        $this->assertSame([0, 1, 1], [$again['created'], $again['updated'], $again['read']]);
        $this->assertSame(2, DB::table('organizations')->whereIn('key', ['zica', 'cavendish'])->count());
        $this->assertSame('ZICA (renamed)', DB::table('organizations')->where('key', 'zica')->value('name'));
        $this->assertSame(['ZICA', 'The Institute'], json_decode(DB::table('organizations')->where('key', 'zica')->value('aliases'), true), 'a file without an aliases column leaves them alone');
        $this->assertSame('https://zica.example.test', DB::table('organizations')->where('key', 'zica')->value('website'));
    }

    public function test_a_qualification_is_verified_only_with_a_source_and_a_date(): void
    {
        $this->orgs();
        $result = $this->import('qualifications', implode("\n", [
            'key,name,kind,level,field,awarding_organization,duration_months,source_url,verified_on,verified_by',
            'cert.one,Unsourced Certificate,professional,certificate,,zica,12,,,',
            'cert.two,Sourced Diploma,academic,diploma,accounting,cavendish,36,https://example.test/prospectus,2026-10-01,Sunny',
            'cert.three,Source but no date,academic,diploma,,cavendish,,https://example.test/x,,',
        ]));

        $this->assertSame(3, $result['created']);
        $status = DB::table('directory_qualifications')->pluck('status', 'key');
        $this->assertSame('unverified', $status['cert.one']);
        $this->assertSame('verified', $status['cert.two']);
        $this->assertSame('unverified', $status['cert.three'], 'a link without a verification date does not verify');

        $evidence = DB::table('evidence')->where('subject_type', \App\Models\DirectoryQualification::class)->get();
        $this->assertCount(2, $evidence);
        $this->assertSame('Sunny', $evidence->firstWhere('url', 'https://example.test/prospectus')->verified_by);
        $this->assertSame(36, (int) DB::table('directory_qualifications')->where('key', 'cert.two')->value('typical_duration_months'));
    }

    public function test_recognitions_and_programmes_link_by_key_and_carry_their_evidence(): void
    {
        $this->orgs();
        $this->import('qualifications', "key,name,kind\ndip.acc,Diploma in Accounting,academic\n");

        $rec = $this->import('recognitions', "qualification,authority,status,scope,source_document,verified_on\ndip.acc,zica,recognised,for ZICA exemptions,ZICA circular 2026/4,2026-10-02\n");
        $prog = $this->import('programmes', "qualification,provider,mode,duration_months,source_url,verified_on\ndip.acc,cavendish,part_time,24,https://example.test/dip,2026-10-03\n");

        $this->assertSame([1, 1], [$rec['created'], $prog['created']]);
        $this->assertNotNull(DB::table('recognitions')->value('verified_at'));
        $this->assertSame('recognition', DB::table('evidence')->where('document', 'ZICA circular 2026/4')->value('claim'));
        $this->assertSame('offered', DB::table('evidence')->where('url', 'https://example.test/dip')->value('claim'));

        // loading the same files again changes nothing
        $this->import('recognitions', "qualification,authority,status,scope,source_document,verified_on\ndip.acc,zica,recognised,for ZICA exemptions,ZICA circular 2026/4,2026-10-02\n");
        $this->assertSame(1, DB::table('recognitions')->count());
        $this->assertSame(1, DB::table('evidence')->where('claim', 'recognition')->count());
    }

    public function test_reimporting_without_a_source_does_not_unverify(): void
    {
        $this->orgs();
        $this->import('qualifications', "key,name,kind\nq1,Q One,academic\n");
        $this->import('recognitions', "qualification,authority,status,source_url,verified_on\nq1,zica,recognised,https://example.test/r,2026-10-02\n");
        $this->import('recognitions', "qualification,authority,status\nq1,zica,recognised\n");

        $this->assertNotNull(DB::table('recognitions')->value('verified_at'));
    }

    public function test_bad_rows_are_reported_with_their_row_number_and_the_rest_are_stored(): void
    {
        $this->orgs();
        $result = $this->import('qualifications', implode("\n", [
            'key,name,kind,level,source_url',
            'ok.one,Fine,academic,diploma,',
            'bad.kind,Bad kind,hobby,,',
            'bad.level,Bad level,academic,nonsense,',
            'bad.url,Bad url,academic,diploma,javascript:alert(1)',
            ',No key,academic,,',
        ]));

        $this->assertSame(1, $result['created']);
        $this->assertCount(4, $result['errors']);
        $this->assertStringContainsString('Row 3', $result['errors'][0]);
        $this->assertStringContainsString('"kind" is "hobby"', $result['errors'][0]);
        $this->assertStringContainsString('"level" "nonsense" is not known', $result['errors'][1]);
        $this->assertStringContainsString('not a web address', $result['errors'][2]);
        $this->assertStringContainsString('"key" is empty', $result['errors'][3]);
        $this->assertSame(0, DB::table('directory_qualifications')->whereIn('key', ['bad.kind', 'bad.level', 'bad.url'])->count());
    }

    public function test_an_unknown_reference_or_type_is_refused(): void
    {
        $result = $this->import('recognitions', "qualification,authority,status\nnope,zica,recognised\n");
        $this->assertStringContainsString('"qualification" "nope" is not known', $result['errors'][0]);

        $this->expectException(InvalidArgumentException::class);
        $this->import('people', "a\n1\n");
    }

    public function test_semicolons_and_windows_text_are_read_and_the_command_runs(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'dir');
        file_put_contents($file, mb_convert_encoding("key;name;type\nunza;Université de Zambie;university\n", 'Windows-1252', 'UTF-8'));

        $this->assertSame(0, Artisan::call('directory:import', ['type' => 'organizations', 'file' => $file]));
        $this->assertSame('Université de Zambie', DB::table('organizations')->where('key', 'unza')->value('name'));
        $this->assertSame(1, Artisan::call('directory:import', ['type' => 'organizations', 'file' => $file.'.missing']));
        unlink($file);
    }
}
