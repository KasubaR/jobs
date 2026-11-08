<?php

namespace Tests\Feature;

use App\Events\RawJobPostStored;
use App\Import\AdFileParser;
use App\Import\AdImporter;
use App\Import\ImportFileException;
use App\Models\RawJobPost;
use App\Models\Source;
use App\Models\SourceRun;
use App\Models\User;
use App\Services\SourceManager;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->seed(SourceSeeder::class);
    }

    private function tsc(bool $ready = true): Source
    {
        $source = Source::where('name', 'Teaching Service Commission')->first();

        if ($ready) {
            app(SourceManager::class)->decideAccess($source, 'approved', 'Public notices.');
            app(SourceManager::class)->activate($source->fresh());
        }

        return $source->fresh();
    }

    private function parse(string $content, string $format): array
    {
        return app(AdFileParser::class)->parse($content, $format);
    }

    private function csvOf(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($out, $row, ',', '"', '');
        }
        rewind($out);

        return stream_get_contents($out);
    }

    // ---- reading files ----

    public function test_reads_a_json_list_and_a_json_object_that_names_its_source(): void
    {
        $list = $this->parse('[{"title":"A","content":"a"},{"title":"B","content":"b"}]', 'json');
        $object = $this->parse('{"source":"Teaching Service Commission","ads":[{"title":"A","content":"a"}]}', 'json');

        $this->assertCount(2, $list['rows']);
        $this->assertSame([1, 2], array_column($list['rows'], 'row'));
        $this->assertNull($list['source']);
        $this->assertSame('Teaching Service Commission', $object['source']);
    }

    public function test_reads_csv_with_a_byte_order_mark_semicolons_tabs_and_blank_lines(): void
    {
        $comma = $this->parse("\xEF\xBB\xBFtitle,content\r\nA,text a\r\n\r\nB,text b\r\n", 'csv');
        $semi = $this->parse("title;content;url\nA;text a;https://x.test/a\n", 'csv');
        $tab = $this->parse("title\tcontent\nA\ttext a\n", 'csv');

        $this->assertSame(['A', 'B'], array_column(array_column($comma['rows'], 'data'), 'title'));
        $this->assertSame([2, 4], array_column($comma['rows'], 'row'), 'row numbers match the spreadsheet row');
        $this->assertSame('https://x.test/a', $semi['rows'][0]['data']['url']);
        $this->assertSame('text a', $tab['rows'][0]['data']['content']);
    }

    public function test_ad_text_may_span_lines_inside_quotes_and_columns_may_be_in_any_order(): void
    {
        $csv = $this->csvOf([['content', 'title', 'ignored'], ["Line one\nLine two, with a comma", 'Teacher', 'x']]);

        $row = $this->parse($csv, 'csv')['rows'][0]['data'];

        $this->assertSame("Line one\nLine two, with a comma", $row['content']);
        $this->assertSame('Teacher', $row['title']);
        $this->assertArrayNotHasKey('ignored', $row);
    }

    public function test_windows_1252_files_from_excel_are_read_as_text(): void
    {
        $csv = mb_convert_encoding("title,content\nCafé Manager,Apply to Zambézi Café\n", 'Windows-1252', 'UTF-8');

        $row = $this->parse($csv, 'csv')['rows'][0]['data'];

        $this->assertSame('Café Manager', $row['title']);
        $this->assertStringContainsString('Zambézi', $row['content']);
    }

    public function test_files_that_cannot_be_used_are_refused_with_a_reason(): void
    {
        $cases = [
            ['', 'csv', 'empty'],
            ["   \n", 'json', 'empty'],
            ['url,content\nx,y', 'csv', '"title" is missing'],
            ["title,url\nA,https://x.test", 'csv', '"content" is missing'],
            ['{not json', 'json', 'not valid JSON'],
            ['{"ads":"nope"}', 'json', '"ads" list'],
            ['{"other":1}', 'json', '"ads" list'],
            ['title,content', 'xlsx', 'Only .csv and .json'],
        ];

        foreach ($cases as [$content, $format, $needle]) {
            try {
                $this->parse($content, $format);
                $this->fail("Expected a refusal for: {$needle}");
            } catch (ImportFileException $e) {
                $this->assertStringContainsString($needle, $e->getMessage());
            }
        }
    }

    public function test_too_many_ads_or_too_many_bytes_are_refused(): void
    {
        config(['job_intel.import_max_rows' => 3, 'job_intel.import_max_bytes' => 400]);

        $rows = array_fill(0, 4, ['title' => 'A', 'content' => 'a']);
        try {
            $this->parse(json_encode($rows), 'json');
            $this->fail('Expected too many ads.');
        } catch (ImportFileException $e) {
            $this->assertStringContainsString('at most 3', $e->getMessage());
        }

        try {
            $this->parse(str_repeat('x', 401), 'csv');
            $this->fail('Expected too large.');
        } catch (ImportFileException $e) {
            $this->assertStringContainsString('larger than', $e->getMessage());
        }

        $csv = "title,content\n".str_repeat("A,a\n", 4);
        $this->expectException(ImportFileException::class);
        $this->parse($csv, 'csv');
    }

    public function test_format_comes_from_the_file_name(): void
    {
        $this->assertSame('csv', AdFileParser::formatFor('ads.CSV'));
        $this->assertSame('json', AdFileParser::formatFor('/tmp/ads.json'));
        $this->assertNull(AdFileParser::formatFor('ads.xlsx'));
        $this->assertNull(AdFileParser::formatFor('ads'));
    }

    // ---- storing a file ----

    public function test_a_file_becomes_one_run_through_the_normal_rules(): void
    {
        Event::fake([RawJobPostStored::class]);
        $source = $this->tsc();
        $csv = $this->csvOf([
            ['title', 'url', 'content', 'external_id'],
            ['Teacher at TSC', 'https://tsc.test/a', 'Apply to hr@tsc.example today', 'T-1'],
            ['Nurse at TSC', '', 'Apply now', ''],
        ]);

        $run = app(AdImporter::class)->import($source, $csv, 'csv');

        $this->assertSame(1, SourceRun::count());
        $this->assertSame(['success', 'import', 2, 2, 0], [$run->status, $run->trigger, $run->items_found, $run->items_new, $run->items_failed]);
        $this->assertSame(2, RawJobPost::count());

        $teacher = RawJobPost::where('raw_title', 'Teacher at TSC')->first();
        $this->assertStringNotContainsString('hr@tsc.example', $teacher->raw_content);
        $this->assertSame('T-1', $teacher->external_id);
        $this->assertSame('pending', $teacher->processing_status);
        $this->assertStringStartsWith('manual:', RawJobPost::where('raw_title', 'Nurse at TSC')->value('source_url'));
        Event::assertDispatchedTimes(RawJobPostStored::class, 2);
        $this->assertNotNull($source->fresh()->last_collection_at);
        Http::assertNothingSent();
    }

    public function test_a_bad_row_is_reported_with_its_row_number_and_the_rest_is_stored(): void
    {
        $source = $this->tsc();
        $csv = $this->csvOf([
            ['title', 'url', 'content'],
            ['Good ad', '', 'text'],
            ['', '', 'no title here'],
            ['Bad link', 'javascript:alert(1)', 'text'],
            ['Another good ad', '', 'text'],
        ]);

        $run = app(AdImporter::class)->import($source, $csv, 'csv');

        $this->assertSame(['partial', 4, 2, 2], [$run->status, $run->items_found, $run->items_new, $run->items_failed]);
        $messages = $run->errors()->pluck('message')->implode(' | ');
        $this->assertStringContainsString('Row 3: Both a title and the ad text are required', $messages);
        $this->assertStringContainsString('Row 4 (Bad link): The link must be a full http(s) address', $messages);
        $this->assertSame(2, RawJobPost::count());
    }

    public function test_a_file_where_nothing_can_be_stored_is_a_failed_run(): void
    {
        $source = $this->tsc();

        $run = app(AdImporter::class)->import($source, '[{"title":"","content":""},{"nope":1}]', 'json');

        $this->assertSame(['failed', 2, 2, 0], [$run->status, $run->items_found, $run->items_failed, $run->items_new]);
        $this->assertNull($source->fresh()->last_collection_at);
        $this->assertSame(0, RawJobPost::count());
    }

    public function test_an_empty_list_is_a_failed_run_that_says_so(): void
    {
        $run = app(AdImporter::class)->import($this->tsc(), '[]', 'json');

        $this->assertSame('failed', $run->status);
        $this->assertSame('The file contains no ads.', $run->errors()->first()->message);
    }

    public function test_importing_the_same_file_again_changes_nothing_and_an_edit_is_a_new_version(): void
    {
        $source = $this->tsc();
        $json = fn (string $text) => json_encode([['title' => 'Teacher', 'url' => 'https://tsc.test/a', 'content' => $text]]);

        app(AdImporter::class)->import($source, $json('Apply by 30 April'), 'json');
        $again = app(AdImporter::class)->import($source, $json('Apply by 30 April'), 'json');
        $this->assertSame([0, 0, 1], [$again->items_new, $again->items_updated, $again->items_unchanged]);
        $this->assertSame(1, RawJobPost::count());

        Event::fake([RawJobPostStored::class]);
        $edited = app(AdImporter::class)->import($source, $json('Apply by 15 May'), 'json');
        $this->assertSame(1, $edited->items_updated);
        $this->assertSame(2, RawJobPost::count());
        Event::assertDispatched(RawJobPostStored::class, fn ($e) => $e->reason === 'updated');
    }

    public function test_the_same_ad_twice_in_one_file_is_stored_once(): void
    {
        $ad = ['title' => 'Teacher', 'url' => 'https://tsc.test/a', 'content' => 'text'];

        $run = app(AdImporter::class)->import($this->tsc(), json_encode([$ad, $ad]), 'json');

        $this->assertSame([1, 1, 2], [$run->items_new, $run->items_unchanged, $run->items_found]);
        $this->assertSame(1, RawJobPost::count());
    }

    public function test_a_source_that_refuses_retention_keeps_no_raw_text_from_files_either(): void
    {
        $source = $this->tsc();
        $source->update(['retain_raw' => false]);

        app(AdImporter::class)->import($source->fresh(), '[{"title":"A","content":"secret text"}]', 'json');

        $this->assertNull(RawJobPost::first()->raw_content);
    }

    public function test_only_approved_active_manual_sources_take_imports(): void
    {
        $importer = app(AdImporter::class);
        $json = '[{"title":"A","content":"a"}]';

        foreach ([$this->tsc(false), Source::where('name', 'JobWeb Zambia')->first(), Source::where('name', 'UN Jobs')->first()] as $source) {
            try {
                $importer->import($source, $json, 'json');
                $this->fail("{$source->name} should be refused");
            } catch (RuntimeException) {
                $this->assertSame(0, RawJobPost::count(), $source->name);
            }
        }

        $this->assertSame(0, SourceRun::count());
    }

    // ---- facts supplied with an ad ----

    public function test_structured_facts_are_kept_cleaned_and_emails_removed(): void
    {
        $facts = ['employer' => 'Example Council (jobs@council.test)', 'salary' => ['min' => 9000, 'max' => 12000], 'locations' => [['locality' => 'Kitwe']]];
        $csv = $this->csvOf([['title', 'content', 'structured'], ['Teacher', 'text', json_encode($facts)]]);

        app(AdImporter::class)->import($this->tsc(), $csv, 'csv');

        $stored = RawJobPost::first()->structured_data;
        $this->assertSame('Example Council ([email removed])', $stored['employer']);
        $this->assertSame(9000, $stored['salary']['min']);
        $this->assertSame('Kitwe', $stored['locations'][0]['locality']);
    }

    public function test_structured_data_is_kept_small_and_plain(): void
    {
        $deep = ['a' => ['b' => ['c' => ['d' => ['e' => 'too deep']]]], 'html' => '<script>alert(1)</script>Plain', 'long' => str_repeat('x', 5000), 'obj' => new \stdClass];
        $run = app(AdImporter::class)->import($this->tsc(), json_encode([['title' => 'A', 'content' => 'a', 'structured' => $deep]]), 'json');

        $stored = RawJobPost::first()->structured_data;
        $this->assertSame('success', $run->status);
        $this->assertSame(1000, strlen($stored['long']));
        $this->assertSame('alert(1)Plain', $stored['html']);
        $this->assertArrayNotHasKey('e', $stored['a']['b']['c']['d'] ?? []);
    }

    public function test_structured_data_that_is_not_an_object_or_is_far_too_big_is_a_row_error(): void
    {
        $big = array_fill(0, 50, str_repeat('y', 900));
        $rows = [
            ['title' => 'List', 'content' => 'a', 'structured' => ['one', 'two']],
            ['title' => 'Big', 'content' => 'a', 'structured' => ['facts' => array_fill_keys(range(1, 40), str_repeat('y', 900))]],
            ['title' => 'Fine', 'content' => 'a', 'structured' => '{"employer":"X"}'],
        ];

        $run = app(AdImporter::class)->import($this->tsc(), json_encode($rows), 'json');

        $this->assertSame(['partial', 1, 2], [$run->status, $run->items_new, $run->items_failed]);
        $this->assertSame('X', RawJobPost::first()->structured_data['employer']);
        $this->assertNotEmpty($big);
    }

    // ---- collect:import ----

    private function file(string $name, string $content): string
    {
        $path = storage_path("framework/testing/{$name}");
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);

        return $path;
    }

    public function test_the_command_imports_a_file_for_a_named_source(): void
    {
        $this->tsc();
        $path = $this->file('ads.json', '[{"title":"Teacher","content":"Apply"}]');

        $this->artisan('collect:import', ['file' => $path, '--source' => 'Teaching Service Commission'])
            ->expectsOutputToContain('Teaching Service Commission: success. Discovered 1, new 1')
            ->assertSuccessful();

        $this->assertSame(1, RawJobPost::count());
    }

    public function test_the_command_finds_the_source_by_id_or_from_the_json_file(): void
    {
        $source = $this->tsc();
        $byId = $this->file('by-id.json', '[{"title":"A","content":"a"}]');
        $named = $this->file('named.json', json_encode(['source' => $source->id, 'ads' => [['title' => 'B', 'content' => 'b']]]));

        $this->artisan('collect:import', ['file' => $byId, '--source' => (string) $source->id])->assertSuccessful();
        $this->artisan('collect:import', ['file' => $named])->assertSuccessful();

        $this->assertSame(2, RawJobPost::count());
    }

    public function test_the_command_prints_a_summary_for_scripts_with_the_row_problems(): void
    {
        $this->tsc();
        $path = $this->file('mixed.json', '[{"title":"Good","content":"a"},{"title":"","content":""}]');

        $exit = \Artisan::call('collect:import', ['file' => $path, '--source' => 'Teaching Service Commission', '--json' => true]);
        $result = json_decode(trim(\Artisan::output()), true);

        $this->assertSame(0, $exit, 'partial still stored something, so it is a success for the caller');
        $this->assertSame('partial', $result['status']);
        $this->assertSame([2, 1, 0, 0, 1], [$result['discovered'], $result['new'], $result['changed'], $result['unchanged'], $result['failed']]);
        $this->assertSame('Teaching Service Commission', $result['source']);
        $this->assertStringContainsString('Row 2', $result['errors'][0]['message']);
        $this->assertIsInt($result['run_id']);
    }

    public function test_the_command_fails_clearly_for_each_kind_of_mistake(): void
    {
        $this->tsc();
        $good = $this->file('good.json', '[{"title":"A","content":"a"}]');

        $this->artisan('collect:import', ['file' => '/no/such/file.json', '--source' => '1'])->expectsOutputToContain('not found')->assertFailed();
        $this->artisan('collect:import', ['file' => $this->file('ads.txt', 'x'), '--source' => '1'])->expectsOutputToContain('Only .csv and .json')->assertFailed();
        $this->artisan('collect:import', ['file' => $good])->expectsOutputToContain('Say which source')->assertFailed();
        $this->artisan('collect:import', ['file' => $good, '--source' => 'Nobody'])->expectsOutputToContain("No source matches 'Nobody'")->assertFailed();
        $this->artisan('collect:import', ['file' => $this->file('bad.json', '{oops'), '--source' => 'Teaching Service Commission'])->expectsOutputToContain('not valid JSON')->assertFailed();

        config(['job_intel.import_max_bytes' => 5]);
        $this->artisan('collect:import', ['file' => $good, '--source' => 'Teaching Service Commission'])->expectsOutputToContain('larger than')->assertFailed();

        $this->assertSame(0, RawJobPost::count());
    }

    public function test_the_command_refuses_a_source_that_may_not_take_imports_and_a_run_of_nothing_fails(): void
    {
        $this->tsc(false); // pending
        $path = $this->file('p.json', '[{"title":"A","content":"a"}]');
        $this->artisan('collect:import', ['file' => $path, '--source' => 'Teaching Service Commission'])->expectsOutputToContain('not approved and active')->assertFailed();

        $this->tsc();
        $this->artisan('collect:import', ['file' => $this->file('none.json', '[{"title":"","content":""}]'), '--source' => 'Teaching Service Commission'])
            ->expectsOutputToContain('failed')->assertFailed();
    }

    public function test_the_command_error_is_json_too_when_asked(): void
    {
        $this->artisan('collect:import', ['file' => '/no/such.json', '--source' => '1', '--json' => true])
            ->expectsOutputToContain('"status":"error"')->assertFailed();
    }

    // ---- the screen ----

    private function upload(Source $source, string $name, string $content, string $mime = 'text/plain')
    {
        return $this->post("/sources/{$source->id}/import", ['file' => UploadedFile::fake()->createWithContent($name, $content)]);
    }

    public function test_the_import_button_appears_only_for_ready_manual_sources(): void
    {
        $this->signIn();

        $this->get("/sources/{$this->tsc(false)->id}")->assertOk()->assertDontSee('Import file');
        $this->get('/sources/'.Source::where('name', 'JobWeb Zambia')->value('id'))->assertOk()->assertDontSee('Import file');

        $this->tsc();
        $this->get('/sources/'.Source::where('name', 'Teaching Service Commission')->value('id'))
            ->assertOk()->assertSee('Import file')->assertSee('Download a sample CSV');
    }

    public function test_uploading_a_csv_stores_the_ads_and_links_to_the_run(): void
    {
        $this->signIn();
        $source = $this->tsc();

        $response = $this->upload($source, 'ads.csv', "title,content\nTeacher,Apply now\nNurse,Apply now\n");

        $run = SourceRun::first();
        $response->assertSessionHas('status', fn ($m) => str_contains($m, 'Import done') && str_contains($m, 'new 2'))
            ->assertSessionHas('run_id', $run->id);
        $this->assertSame(2, RawJobPost::count());

        $this->get("/sources/{$source->id}")->assertSee("/runs/{$run->id}", false)->assertSee('View the run');
    }

    public function test_a_partly_bad_upload_says_so_and_a_useless_one_is_an_error(): void
    {
        $this->signIn();
        $source = $this->tsc();

        $this->upload($source, 'ads.json', '[{"title":"A","content":"a"},{"title":"","content":""}]')
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'partly done'));

        $this->upload($source, 'bad.json', '{oops')->assertSessionHas('error', fn ($m) => str_contains($m, 'not valid JSON'));
        $this->upload($source, 'ads.xlsx', 'whatever')->assertSessionHas('error', fn ($m) => str_contains($m, 'Only .csv and .json'));
        $this->post("/sources/{$source->id}/import", [])->assertSessionHasErrors('file');
    }

    public function test_an_oversized_upload_is_refused(): void
    {
        $this->signIn();
        config(['job_intel.import_max_bytes' => 1024]);

        $this->upload($this->tsc(), 'big.csv', "title,content\n".str_repeat("A,aaaaaaaaaaaaaaaaaaaa\n", 200))->assertSessionHasErrors('file');
        $this->assertSame(0, RawJobPost::count());
    }

    public function test_the_upload_is_refused_for_sources_that_cannot_take_imports(): void
    {
        $this->signIn();

        $this->upload($this->tsc(false), 'ads.json', '[{"title":"A","content":"a"}]')->assertSessionHas('error');
        $this->upload(Source::where('name', 'JobWeb Zambia')->first(), 'ads.json', '[{"title":"A","content":"a"}]')->assertSessionHas('error');
        $this->assertSame(0, RawJobPost::count());
    }

    public function test_the_sample_csv_downloads_and_can_be_imported_as_it_is(): void
    {
        $this->signIn();
        $source = $this->tsc();

        $download = $this->get("/sources/{$source->id}/import-sample")->assertOk();
        $this->assertStringContainsString('text/csv', $download->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $download->headers->get('Content-Disposition'));

        $run = app(AdImporter::class)->import($source, $download->getContent(), 'csv');
        $this->assertSame(['success', 2, 0], [$run->status, $run->items_new, $run->items_failed]);
        $this->assertStringContainsString("Closing date: 30 April 2027", RawJobPost::where('external_id', 'EC-2027-001')->value('raw_content'));
        $this->assertSame('Example Council', RawJobPost::where('external_id', 'EC-2027-001')->first()->structured_data['employer']);
    }

    public function test_import_routes_need_a_signed_in_admin(): void
    {
        $source = $this->tsc();

        $this->post("/sources/{$source->id}/import", [])->assertRedirect('/login');
        $this->get("/sources/{$source->id}/import-sample")->assertRedirect('/login');

        $this->actingAs(User::factory()->create(['email' => 'intruder@example.com']));
        $this->get("/sources/{$source->id}/import-sample")->assertForbidden();
        $this->assertSame(0, RawJobPost::count());
    }
}
