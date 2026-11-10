<?php

namespace Tests\Feature;

use App\Models\Job;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesJobs;
use Tests\TestCase;

/** The baseline report is read-only, and sorts vacancies without a qualification by why. */
class AuditBaselineTest extends TestCase
{
    use MakesJobs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['job_intel.collector_contact' => 'owner@example.com']);
        $this->seed(SourceSeeder::class);
    }

    private function vacancy(string $title, string $body): Job
    {
        static $n = 0;
        $n++;

        return $this->ad($this->sourceSet()['company'], ['employer' => "Employer {$n} Ltd"], ['raw_title' => $title, 'raw_content' => "<p>{$body} Reference {$n}.</p>"]);
    }

    public function test_it_reports_without_changing_anything(): void
    {
        $this->vacancy('Accountant', 'Requirements: a Bachelor of Science in Accounting is required. '.str_repeat('The accountant prepares monthly reports for the finance team. ', 8));
        $tables = ['jobs', 'job_qualifications', 'job_skills', 'raw_job_posts', 'job_experiences'];
        $before = array_map(fn ($t) => DB::table($t)->count(), $tables);
        $hash = DB::table('jobs')->get()->toJson();

        $this->artisan('audit:baseline')->assertSuccessful()->expectsOutputToContain('1. What is stored')->expectsOutputToContain('Vacancies with no recognised qualification');

        $this->assertSame($before, array_map(fn ($t) => DB::table($t)->count(), $tables));
        $this->assertSame($hash, DB::table('jobs')->get()->toJson());
    }

    public function test_it_gives_a_reason_for_each_vacancy_without_a_qualification(): void
    {
        $this->vacancy('Cleaner', 'Duties: keep the offices tidy and report broken fittings every morning. '.str_repeat('The cleaner follows the checklist for each floor. ', 8));
        $thin = $this->vacancy('Driver', 'Drive.');

        $this->artisan('audit:baseline')
            ->expectsOutputToContain('ad appears to state none')
            ->expectsOutputToContain('ad text too short')
            ->assertSuccessful();

        $this->assertNotNull($thin->id);
    }
}
