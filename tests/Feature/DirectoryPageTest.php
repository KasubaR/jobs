<?php

namespace Tests\Feature;

use App\Models\DirectoryQualification;
use App\Models\Organization;
use App\Models\Programme;
use App\Models\Recognition;
use App\Taxonomy\LinkStatsBuilder;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesJobs;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/** The Qualification Directory pages: search, and a profile that keeps existence, recognition, availability and demand apart. */
class DirectoryPageTest extends TestCase
{
    use MakesJobs, RefreshDatabase, SignsIn;

    private function acca(): DirectoryQualification
    {
        return DirectoryQualification::where('key', 'professional.acca')->firstOrFail();
    }

    public function test_the_pages_need_a_signed_in_administrator(): void
    {
        $this->get(route('directory.index'))->assertRedirect();
        $this->get(route('directory.show', $this->acca()))->assertRedirect();
    }

    public function test_search_finds_by_name_or_alias_and_filters_by_kind(): void
    {
        $this->signIn();

        $this->get(route('directory.index', ['q' => 'Chartered Management Accountant']))->assertOk()->assertSee('CIMA')->assertDontSee('ACCA</a>', false);
        $this->get(route('directory.index', ['kind' => 'registration']))->assertOk()->assertDontSee('>ACCA</a>', false);
        $this->get(route('directory.index', ['q' => 'zzzz-nothing']))->assertOk()->assertSee('No qualification matches');
        $this->get(route('directory.index', ['kind' => 'nonsense']))->assertSessionHasErrors('kind');
    }

    public function test_a_new_profile_says_what_is_not_known_and_never_infers_recognition(): void
    {
        $this->signIn();

        $this->get(route('directory.show', $this->acca()))->assertOk()
            ->assertSee('Nobody has checked it against a source yet')
            ->assertSee('No recognition is recorded')
            ->assertSee('No provider is recorded yet')
            ->assertSee('No vacancy asks for this qualification yet')
            ->assertSee('No source is recorded');
    }

    public function test_the_profile_shows_each_fact_with_its_source(): void
    {
        $this->signIn();
        $q = $this->acca();
        $zica = Organization::create(['key' => 'zica', 'name' => 'Zambia Institute of Chartered Accountants', 'type' => 'regulator']);
        $uni = Organization::create(['key' => 'cavendish', 'name' => 'Cavendish University', 'type' => 'university']);
        $r = Recognition::create(['qualification_id' => $q->id, 'authority_id' => $zica->id, 'status' => 'recognised', 'scope' => 'for practice as an accountant', 'verified_at' => '2026-10-01']);
        Programme::create(['provider_id' => $uni->id, 'qualification_id' => $q->id, 'mode' => 'part_time', 'duration_months' => 36]);
        $r->evidence()->create(['claim' => 'recognition', 'url' => 'https://example.test/register', 'retrieved_on' => '2026-10-01', 'verified_by' => 'Sunny', 'verified_at' => '2026-10-01']);
        $r->evidence()->create(['claim' => 'bad link', 'url' => 'javascript:alert(1)']);

        $this->get(route('directory.show', $q))->assertOk()
            ->assertSee('Zambia Institute of Chartered Accountants')->assertSee('for practice as an accountant')->assertSee('verified 1 Oct 2026')
            ->assertSee('Cavendish University')->assertSee('36 mo')
            ->assertSee('https://example.test/register')->assertSee('verified 1 Oct 2026 by Sunny')
            ->assertDontSee('href="javascript:', false);
    }

    public function test_the_profile_shows_demand_wording_and_links_only_from_a_big_enough_sample(): void
    {
        config(['job_intel.collector_contact' => 'owner@example.com']);
        $this->seed(SourceSeeder::class);
        $this->signIn();
        $q = $this->acca();

        $make = fn (string $title, string $employer) => $this->ad($this->sourceSet()['company'], ['employer' => $employer], ['raw_title' => $title, 'raw_content' => '<p>Requirements:<br>ACCA is required.<br>Excel.</p>']);
        $make('Accountant', 'One Ltd');
        $make('Accountant', 'Two Ltd');
        (new LinkStatsBuilder)->rebuild();

        $this->get(route('directory.show', $q))->assertOk()
            ->assertSee('Employer demand')->assertSee('ACCA is required.')
            ->assertSee('Only 2 vacancies counted so far')->assertDontSee('What ads have in common', false);

        foreach (['Three', 'Four', 'Five'] as $name) {
            $make('Accountant', "{$name} Ltd");
        }
        (new LinkStatsBuilder)->rebuild();

        $this->get(route('directory.show', $q))->assertOk()
            ->assertSee('Shares of the 5 vacancies')->assertSee('Accountants')->assertSee('not where the qualification leads');
    }

    public function test_a_missing_qualification_is_a_404(): void
    {
        $this->signIn();
        $this->get('/directory/999999')->assertNotFound();
        $this->assertSame(1, DB::table('directory_qualifications')->where('key', 'professional.acca')->count());
    }
}
