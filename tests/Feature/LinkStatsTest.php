<?php

namespace Tests\Feature;

use App\Models\Job;
use App\Taxonomy\LinkStatsBuilder;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesJobs;
use Tests\TestCase;

/** Observed links: which occupations, skills and industries go with a qualification, counted from vacancies. */
class LinkStatsTest extends TestCase
{
    use MakesJobs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['job_intel.collector_contact' => 'owner@example.com']);
        $this->seed(SourceSeeder::class);
    }

    private function vacancy(string $title, string $text, ?string $employer = null): Job
    {
        static $n = 0;
        $n++;

        return $this->ad($this->sourceSet()['company'], ['employer' => $employer ?? "Employer {$n} Ltd"], ['raw_title' => $title, 'raw_content' => '<p>'.nl2br(e($text)).'</p>']);
    }

    private function acca(): int
    {
        return (int) DB::table('directory_qualifications')->where('key', 'professional.acca')->value('id');
    }

    private function stat(string $type, string $target): ?object
    {
        return DB::table('qualification_link_stats')->where('directory_qualification_id', $this->acca())->where('link_type', $type)->where('target', $target)->first();
    }

    public function test_counts_the_occupations_and_skills_that_go_with_a_qualification(): void
    {
        $accountant = DB::table('occupations')->where('code', '2411')->value('code');
        $this->assertNotNull($accountant);

        $this->vacancy('Accountant', "Requirements:\nACCA is required.\nProficiency in Excel.");
        $this->vacancy('Senior Accountant', "Requirements:\nACCA is preferred.\nExcel.");
        $this->vacancy('Accountant', "Requirements:\nCIMA qualification.\nExcel.");        // no ACCA: not counted for ACCA

        $written = (new LinkStatsBuilder)->rebuild();

        $occupation = $this->stat('occupation', (string) $accountant);
        $this->assertSame(2, $occupation->jobs);
        $this->assertSame(2, $occupation->qualification_jobs, 'the sample size is stored beside the count');
        $this->assertSame(1, $occupation->required_jobs, 'one ad requires it, one only asks for it');

        $excel = DB::table('skills')->where('name', 'like', '%Excel%')->value('id');
        $this->assertSame(2, $this->stat('skill', (string) $excel)->jobs);
        $this->assertGreaterThan(0, $written['occupation']);
    }

    public function test_a_vacancy_counts_once_and_copies_rejected_ads_and_removed_items_do_not_count(): void
    {
        $accountant = (string) DB::table('occupations')->where('code', '2411')->value('code');
        $kept = $this->vacancy('Accountant', "Requirements:\nACCA.\nExcel.");
        $copy = $this->vacancy('Accountant', "Requirements:\nACCA.\nExcel.");
        $rejected = $this->vacancy('Accountant', "Requirements:\nACCA.");
        $removed = $this->vacancy('Accountant', "Requirements:\nACCA.");

        $copy->update(['status' => 'duplicate']);
        $rejected->update(['status' => 'rejected']);
        $removed->qualifications()->update(['removed' => true]);

        (new LinkStatsBuilder)->rebuild();

        $this->assertSame(1, $this->stat('occupation', $accountant)->jobs);
        $this->assertSame(1, $this->stat('occupation', $accountant)->qualification_jobs);
    }

    public function test_industry_comes_from_the_job_or_its_employer(): void
    {
        $industry = DB::table('industries')->orderBy('id')->value('id');
        $job = $this->vacancy('Accountant', "Requirements:\nACCA.");
        DB::table('employers')->where('id', $job->employer_id)->update(['industry_id' => $industry]);

        (new LinkStatsBuilder)->rebuild();

        $this->assertSame(1, $this->stat('industry', (string) $industry)->jobs);
    }

    public function test_rebuilding_replaces_old_numbers_and_the_command_runs(): void
    {
        $job = $this->vacancy('Accountant', "Requirements:\nACCA.");
        Artisan::call('stats:rebuild');
        $this->assertGreaterThan(0, DB::table('qualification_link_stats')->count());

        $job->qualifications()->update(['removed' => true]);
        Artisan::call('stats:rebuild');

        $this->assertSame(0, DB::table('qualification_link_stats')->count(), 'nothing stale survives');
    }

    public function test_it_is_scheduled(): void
    {
        $this->assertTrue(collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->contains(fn ($e) => str_contains($e->command, 'stats:rebuild')));
    }
}
