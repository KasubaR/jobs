<?php

namespace Tests\Feature;

use App\Taxonomy\LinkStatsBuilder;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesJobs;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/** Industry is said once per employer and reaches every vacancy and the qualification statistics. */
class EmployerIndustryTest extends TestCase
{
    use MakesJobs, RefreshDatabase, SignsIn;

    protected function setUp(): void
    {
        parent::setUp();
        config(['job_intel.collector_contact' => 'owner@example.com']);
        $this->seed(SourceSeeder::class);
    }

    private function vacancy(string $employer, string $text = "Requirements:\nACCA is required.")
    {
        return $this->ad($this->sourceSet()['company'], ['employer' => $employer], ['raw_title' => 'Accountant', 'raw_content' => '<p>'.nl2br(e($text)).'</p>']);
    }

    private function industry(string $code): int
    {
        return (int) DB::table('industries')->where('code', $code)->value('id');
    }

    public function test_it_needs_a_signed_in_administrator(): void
    {
        $this->get(route('employers.index'))->assertRedirect();
        $this->put(route('employers.update', 1), [])->assertRedirect();
    }

    public function test_employers_without_an_industry_are_listed_busiest_first_with_a_suggestion_that_is_not_saved(): void
    {
        $this->signIn();
        $this->vacancy('Kansanshi Mining Ltd');
        $this->vacancy('Quiet Employer Ltd');
        $this->vacancy('Kansanshi Mining Ltd', "Requirements:\nCIMA is required.");

        $this->get(route('employers.index'))->assertOk()->assertSeeInOrder(['Kansanshi Mining', 'Quiet Employer'])->assertSee('Suggested from the name');
        $this->assertSame(0, DB::table('employers')->whereNotNull('industry_id')->count(), 'a suggestion is never saved by itself');
    }

    public function test_saving_assigns_the_industry_to_the_employer_and_clearing_removes_it(): void
    {
        $this->signIn();
        $job = $this->vacancy('Some Employer Ltd');
        $mining = $this->industry('B');

        $this->put(route('employers.update', $job->employer_id), ['industry_id' => $mining])->assertRedirect()->assertSessionHas('status');
        $this->assertSame($mining, (int) DB::table('employers')->where('id', $job->employer_id)->value('industry_id'));
        $this->get(route('employers.index', ['show' => 'unassigned']))->assertSee('Every employer has an industry');

        $this->put(route('employers.update', $job->employer_id), ['industry_id' => ''])->assertRedirect();
        $this->assertNull(DB::table('employers')->where('id', $job->employer_id)->value('industry_id'));
    }

    public function test_it_refuses_an_unknown_industry_or_employer(): void
    {
        $this->signIn();
        $job = $this->vacancy('Some Employer Ltd');

        $this->put(route('employers.update', $job->employer_id), ['industry_id' => 999999])->assertSessionHasErrors('industry_id');
        $this->put(route('employers.update', 999999), ['industry_id' => $this->industry('B')])->assertNotFound();
    }

    public function test_the_industry_reaches_the_qualification_statistics(): void
    {
        $this->signIn();
        $finance = $this->industry('K');
        foreach (['One Ltd', 'Two Ltd'] as $name) {
            $this->put(route('employers.update', $this->vacancy($name)->employer_id), ['industry_id' => $finance]);
        }
        $this->vacancy('Three Ltd');   // no industry: not counted for one

        (new LinkStatsBuilder)->rebuild();

        $acca = DB::table('directory_qualifications')->where('key', 'professional.acca')->value('id');
        $row = DB::table('qualification_link_stats')->where('directory_qualification_id', $acca)->where('link_type', 'industry')->where('target', (string) $finance)->first();
        $this->assertSame(2, $row->jobs);
        $this->assertSame(3, $row->qualification_jobs, 'the share is of all vacancies asking for it, so a missing industry is not hidden');
    }
}
