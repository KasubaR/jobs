<?php

namespace Tests\Feature;

use App\Models\Job;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Concerns\MakesJobs;
use Tests\TestCase;

/** ads:export writes advert text to files for choosing test adverts, and changes nothing. */
class ExportAdsTest extends TestCase
{
    use MakesJobs, RefreshDatabase;

    private const FOLDER = 'ad-export-test';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        config(['job_intel.collector_contact' => 'owner@example.com']);
        $this->seed(SourceSeeder::class);
        $this->dir = storage_path('app/'.self::FOLDER);
        File::deleteDirectory($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function vacancy(string $title, string $body, ?string $occupation = null): Job
    {
        static $n = 0;
        $n++;
        $job = $this->ad($this->sourceSet()['boardA'], ['employer' => "Employer {$n} Ltd"], ['raw_title' => $title, 'raw_content' => "<p>{$body} Reference number {$n} at the {$n} road office.</p>"]);
        $occupation && $job->update(['occupation_code' => $occupation]);

        return $job->fresh();
    }

    private function files(): array
    {
        return collect(File::files($this->dir))->map(fn ($f) => $f->getFilename())->sort()->values()->all();
    }

    public function test_it_writes_one_file_per_vacancy_with_the_text_and_what_was_read(): void
    {
        $job = $this->vacancy('Staff Nurse', 'Requirements:<br>A Diploma in Nursing is required.<br>Registration with the NMCZ.', '2221');

        $this->artisan('ads:export', ['--folder' => self::FOLDER])->assertSuccessful();

        $this->assertContains(sprintf('healthcare-%04d-staff-nurse.txt', $job->id), $this->files());
        $text = File::get($this->dir.'/'.sprintf('healthcare-%04d-staff-nurse.txt', $job->id));
        $this->assertStringContainsString('TITLE: Staff Nurse', $text);
        $this->assertStringContainsString('SECTOR (guess from the title): healthcare', $text);
        $this->assertStringContainsString('A Diploma in Nursing is required.', $text);
        $this->assertStringContainsString('----- ADVERT TEXT -----', $text);
        $this->assertStringContainsString('WHAT THE SYSTEM READ:', $text);
        $this->assertStringNotContainsString('<p>', $text);   // plain text, no markup
    }

    public function test_it_writes_a_combined_file_and_an_index(): void
    {
        $this->vacancy('Accountant', 'Requirements: ACCA.', '2411');
        $this->vacancy('Teacher', 'Requirements: a Diploma in Education.', '2330');

        $this->artisan('ads:export', ['--folder' => self::FOLDER])->assertSuccessful();

        $all = File::get($this->dir.'/ALL-IN-ONE.txt');
        $this->assertStringContainsString('TITLE: Accountant', $all);
        $this->assertStringContainsString('TITLE: Teacher', $all);
        $this->assertStringContainsString('finance', File::get($this->dir.'/INDEX.txt'));
        $this->assertStringContainsString('education', File::get($this->dir.'/INDEX.txt'));
    }

    public function test_the_sector_and_limit_filters_work(): void
    {
        $this->vacancy('Staff Nurse', 'Requirements: a Diploma in Nursing.', '2221');
        $this->vacancy('Accountant', 'Requirements: ACCA.', '2411');
        $this->vacancy('Auditor', 'Requirements: CPA.', '2411');

        $this->artisan('ads:export', ['--folder' => self::FOLDER, '--sector' => 'finance', '--limit' => 1])->assertSuccessful();

        $adverts = array_values(array_filter($this->files(), fn ($f) => str_starts_with($f, 'finance-') || str_starts_with($f, 'healthcare-')));
        $this->assertCount(1, $adverts);
        $this->assertStringStartsWith('finance-', $adverts[0]);
    }

    public function test_copies_and_rejected_ads_are_left_out_unless_asked_for(): void
    {
        $copy = $this->vacancy('Cook', 'Requirements: a certificate in catering.');
        $copy->update(['status' => 'rejected']);

        $this->artisan('ads:export', ['--folder' => self::FOLDER])->assertSuccessful();
        $this->assertSame([], array_values(array_filter($this->files(), fn ($f) => str_contains($f, 'cook'))));

        $this->artisan('ads:export', ['--folder' => self::FOLDER, '--all' => true])->assertSuccessful();
        $this->assertNotEmpty(array_filter($this->files(), fn ($f) => str_contains($f, 'cook')));
    }

    public function test_an_advert_whose_text_was_cleared_has_nothing_to_write(): void
    {
        $job = $this->vacancy('Cleaner', 'Requirements: a certificate.');
        $job->rawPost->update(['raw_content' => null, 'raw_purged_at' => now()]);

        $this->artisan('ads:export', ['--folder' => self::FOLDER])->expectsOutputToContain('0 adverts written')->assertSuccessful();
    }

    public function test_it_changes_nothing_in_the_database(): void
    {
        $this->vacancy('Staff Nurse', 'Requirements: a Diploma in Nursing.', '2221');
        $before = [DB::table('jobs')->get()->toJson(), DB::table('raw_job_posts')->get()->toJson(), DB::table('job_qualifications')->get()->toJson()];

        $this->artisan('ads:export', ['--folder' => self::FOLDER])->assertSuccessful();

        $this->assertSame($before, [DB::table('jobs')->get()->toJson(), DB::table('raw_job_posts')->get()->toJson(), DB::table('job_qualifications')->get()->toJson()]);
    }

    public function test_the_folder_must_be_a_plain_name_inside_storage(): void
    {
        foreach (['../public', '..\\x', 'a/b', '', str_repeat('a', 41)] as $bad) {
            $this->artisan('ads:export', ['--folder' => $bad])->expectsOutputToContain('folder name')->assertFailed();
        }
        $this->artisan('ads:export', ['--folder' => self::FOLDER, '--sector' => 'mining'])->expectsOutputToContain('Sector must be')->assertFailed();
    }
}
