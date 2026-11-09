<?php

namespace Tests\Feature;

use App\Analytics\AdvertisedDemand;
use App\Models\Job;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesJobs;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/** The one definition of advertised demand, and the Insights page built on it. */
class AdvertisedDemandTest extends TestCase
{
    use MakesJobs, RefreshDatabase, SignsIn;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['job_intel.collector_contact' => 'owner@example.com']);
        $this->seed(SourceSeeder::class);
    }

    private function vacancy(string $requirements, string $title = 'Accountant'): Job
    {
        $this->n++;

        return $this->ad($this->sourceSet()['company'], ['employer' => "Employer {$this->n} Ltd"], ['raw_title' => $title, 'raw_content' => '<p>Requirements:<br>'.nl2br(e($requirements)).'</p>']);
    }

    private function many(int $count, string $requirements, string $title = 'Accountant'): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->vacancy($requirements, $title);
        }
    }

    private function acca(AdvertisedDemand $d): ?object
    {
        return $d->qualificationsAsked()->firstWhere('name', 'ACCA');
    }

    // ---- what counts ----

    public function test_a_vacancy_counts_once_and_copies_and_rejected_ads_are_left_out(): void
    {
        $this->many(5, 'ACCA is required.');
        Job::first()->update(['status' => 'duplicate']);
        Job::orderBy('id')->skip(1)->first()->update(['status' => 'rejected']);

        $d = new AdvertisedDemand;

        $this->assertSame(3, $d->counts()['collected']);
        $this->assertSame(3, $this->acca($d)->vacancies);
    }

    public function test_unread_ads_are_a_gap_not_an_ad_that_asks_for_nothing(): void
    {
        $this->many(4, 'ACCA is required.');
        $unread = $this->vacancy('ACCA is required.');
        $unread->update(['analysis_status' => 'no_text']);

        $counts = (new AdvertisedDemand)->counts();

        $this->assertSame(5, $counts['collected']);
        $this->assertSame(4, $counts['analysed']);
        $this->assertSame(4, $counts['stating']);
    }

    public function test_only_required_and_preferred_items_that_were_not_removed_count(): void
    {
        $this->vacancy('ACCA is required.');
        $this->vacancy('ACCA is preferred.');
        $this->vacancy('ACCA is an added advantage.');
        $removed = $this->vacancy('ACCA is required.');
        $removed->qualifications()->update(['removed' => true]);

        $acca = $this->acca(new AdvertisedDemand);

        $this->assertSame(2, $acca->vacancies);
        $this->assertSame(1, $acca->required);
    }

    public function test_the_same_qualification_asked_twice_in_one_vacancy_counts_once(): void
    {
        $this->vacancy("ACCA is required.\nACCA is preferred.");

        $this->assertSame(1, $this->acca(new AdvertisedDemand)->vacancies);
    }

    public function test_open_only_leaves_out_closed_vacancies(): void
    {
        $this->many(3, 'ACCA is required.');
        Job::first()->update(['status' => 'expired']);

        $this->assertSame(3, $this->acca(new AdvertisedDemand('all'))->vacancies);
        $this->assertSame(2, $this->acca(new AdvertisedDemand('open'))->vacancies);
    }

    // ---- shares and small samples ----

    public function test_a_share_is_of_the_vacancies_read_and_small_samples_show_no_share(): void
    {
        $this->many(5, 'ACCA is required.');
        $this->many(5, 'CIMA is required.', 'Analyst');
        $this->vacancy('CPA is required.', 'Auditor');
        $this->many(2, 'Excel.', 'Clerk');          // read, but ask for no qualification

        $d = new AdvertisedDemand;
        $asked = $d->qualificationsAsked();

        $this->assertSame(13, $d->counts()['analysed']);
        $this->assertSame(11, $d->counts()['stating']);
        $this->assertSame(38.0, (float) $this->acca($d)->share, '5 of the 13 read, not of the 11 that state one');
        $this->assertTrue($this->acca($d)->reliable);

        $cpa = $asked->firstWhere('name', 'CPA');
        $this->assertSame(1, $cpa->vacancies);
        $this->assertFalse($cpa->reliable);
        $this->assertNull($cpa->share, 'one vacancy is not a share');
    }

    // ---- entry level ----

    public function test_entry_level_means_a_junior_title_or_at_most_a_year_of_experience_never_silence(): void
    {
        $this->many(2, "Bachelor's Degree in Accounting.", 'Junior Accountant');                          // title says so
        $this->many(2, "Bachelor's Degree in Accounting.\n1 year experience.");                           // a year
        $this->many(2, "Bachelor's Degree in Accounting.\nMinimum 5 years experience.", 'Senior Accountant');
        $this->many(2, "Bachelor's Degree in Accounting.");                                               // says nothing about experience

        $entry = (new AdvertisedDemand)->entryLevel();

        $this->assertSame(4, $entry['vacancies']);
        $this->assertFalse($entry['reliable'], '4 is below the minimum sample');
        $this->assertSame(4, $entry['levels']->firstWhere('key', 'bachelor')->vacancies);
    }

    // ---- professional qualifications alongside degrees ----

    public function test_professional_qualifications_are_counted_with_and_without_a_degree_in_the_same_vacancy(): void
    {
        $this->many(3, "ACCA is required.\nBachelor's Degree in Accounting.");
        $this->many(2, 'ACCA is required.', 'Bookkeeper');
        $this->many(2, "Bachelor's Degree in Accounting.", 'Analyst');

        $pairs = (new AdvertisedDemand)->professionalWithDegrees();
        $acca = $pairs['qualifications']->firstWhere('name', 'ACCA');

        $this->assertSame(5, $pairs['degree_vacancies']);
        $this->assertSame(3, $pairs['with_professional']);
        $this->assertSame([5, 3], [$acca->vacancies, $acca->with_degree]);
        $this->assertSame(60.0, (float) $acca->share);
    }

    // ---- by occupation and by industry ----

    public function test_qualifications_are_counted_within_an_occupation_beside_the_overall_share(): void
    {
        $this->many(5, 'ACCA is required.', 'Accountant');
        $this->many(5, 'CIMA is required.', 'Security Officer');

        $d = new AdvertisedDemand;
        $groups = $d->groups('occupation');

        $this->assertSame(['24' => 5, '54' => 5], $groups['groups']->pluck('vacancies', 'key')->all());
        $this->assertSame(10, $groups['known']);

        $in = $d->qualificationsIn('occupation', '24');
        $acca = $in['qualifications']->firstWhere('name', 'ACCA');
        $this->assertSame(5, $in['vacancies']);
        $this->assertSame(100.0, (float) $acca->share, 'of the 5 vacancies in the group');
        $this->assertSame(50.0, (float) $acca->overall_share, 'of all 10 read: so it is common here, not everywhere');
        $this->assertNull($in['qualifications']->firstWhere('name', 'CIMA'), 'another occupation\'s qualification is not in this group');

        $unit = $d->qualificationsIn('occupation', '2411', 'unit');
        $this->assertSame(5, $unit['vacancies']);
    }

    public function test_a_small_group_shows_counts_but_no_shares(): void
    {
        $this->many(4, "ACCA is required.\nBachelor's Degree in Accounting.", 'Accountant');

        $in = (new AdvertisedDemand)->qualificationsIn('occupation', '24');

        $this->assertFalse($in['reliable']);
        $this->assertSame(4, $in['qualifications']->firstWhere('name', 'ACCA')->vacancies);
        $this->assertNull($in['qualifications']->firstWhere('name', 'ACCA')->share);
        $this->assertTrue($in['levels']->isEmpty());
    }

    public function test_industry_comes_from_the_employer_and_vacancies_without_one_are_not_in_a_group(): void
    {
        $this->many(6, 'ACCA is required.', 'Accountant');
        $finance = (int) DB::table('industries')->where('code', 'K')->value('id');
        DB::table('employers')->whereIn('id', Job::orderBy('id')->take(5)->pluck('employer_id'))->update(['industry_id' => $finance]);

        $d = new AdvertisedDemand;
        $groups = $d->groups('industry');

        $this->assertSame(5, $groups['known']);
        $this->assertSame(6, $groups['analysed']);
        $this->assertSame([(string) $finance => 5], $groups['groups']->pluck('vacancies', 'key')->all());
        $this->assertSame(5, $d->qualificationsIn('industry', (string) $finance)['qualifications']->firstWhere('name', 'ACCA')->vacancies);
    }

    public function test_the_group_page_lists_groups_shows_a_selection_and_refuses_unknown_dimensions(): void
    {
        $this->signIn();
        $this->many(5, 'ACCA is required.', 'Accountant');

        $this->get(route('insights.group', 'occupation'))->assertOk()->assertSee('Choose an occupation')->assertSee('known for 5 of 5 vacancies read', false);
        $this->get(route('insights.group', ['dimension' => 'occupation', 'group' => '24']))->assertOk()->assertSee('ACCA')->assertSee('overall 100%', false)
            ->assertSee('not the Zambian labour market', false);
        $this->get(route('insights.group', 'industry'))->assertOk()->assertSee('No vacancy has a known industry yet');
        $this->get('/insights/colours')->assertNotFound();
        $this->get(route('insights.group', ['dimension' => 'occupation', 'level' => 'nonsense']))->assertSessionHasErrors('level');
    }

    // ---- over time ----

    private function posted(string $date, string $requirements, string $source = 'company'): Job
    {
        $this->n++;

        return $this->ad($this->sourceSet()[$source], ['employer' => "Employer {$this->n} Ltd", 'date_posted' => $date, 'valid_through' => '2027-06-30'],
            ['raw_title' => 'Accountant', 'raw_content' => '<p>Requirements:<br>'.nl2br(e($requirements)).'</p>']);
    }

    private function acca_id(): int
    {
        return (int) DB::table('directory_qualifications')->where('key', 'professional.acca')->value('id');
    }

    public function test_a_trend_is_by_month_with_shares_only_for_months_with_enough_vacancies_and_gaps_shown(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->posted('2026-08-10', 'ACCA is required.');
            $this->posted('2026-08-12', 'Excel.');
        }
        for ($i = 0; $i < 3; $i++) {
            $this->posted('2026-10-05', 'ACCA is required.');
        }

        $months = (new AdvertisedDemand)->trend(['type' => 'qualification', 'id' => $this->acca_id()])->keyBy('month');

        $this->assertSame(['2026-08', '2026-09', '2026-10'], $months->keys()->all());
        $this->assertSame([20, 10], [$months['2026-08']->vacancies, $months['2026-08']->asking]);
        $this->assertSame(50.0, (float) $months['2026-08']->share);
        $this->assertSame(0, $months['2026-09']->vacancies, 'a month with nothing is shown, not skipped');
        $this->assertNull($months['2026-10']->share, '3 vacancies is too few for a share');
        $this->assertSame(3, $months['2026-10']->asking, 'but the count is still shown');
        $this->assertTrue($months['2026-10']->thin);
    }

    public function test_a_change_in_which_sources_were_collected_is_flagged_not_hidden(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->posted('2026-08-10', 'ACCA is required.', 'company');
            $this->posted('2026-09-10', 'ACCA is required.', 'boardA');
        }
        for ($i = 0; $i < 4; $i++) {
            $this->posted('2026-10-10', 'ACCA is required.', 'boardA');
        }

        $months = (new AdvertisedDemand)->trend(['type' => 'qualification', 'id' => $this->acca_id()])->keyBy('month');

        $this->assertNull($months['2026-08']->mix_shift, 'nothing before the first month to compare with');
        $this->assertTrue($months['2026-09']->mix_changed, 'all from one source, then all from another');
        $this->assertSame(100, $months['2026-09']->mix_shift);
        $this->assertFalse($months['2026-10']->mix_changed, 'the same source as the month before');
    }

    public function test_a_level_can_be_followed_and_the_month_falls_back_to_when_the_ad_was_first_collected(): void
    {
        $this->posted('2026-08-10', "Bachelor's Degree in Accounting.");
        $job = $this->posted('2026-08-11', "Bachelor's Degree in Accounting.");
        $job->update(['posted_date' => null, 'first_seen_at' => '2026-09-20 10:00:00']);

        $months = (new AdvertisedDemand)->trend(['type' => 'level', 'key' => 'bachelor'])->keyBy('month');

        $this->assertSame(1, $months['2026-08']->asking);
        $this->assertSame(1, $months['2026-09']->asking);
    }

    public function test_the_trends_page_explains_the_limits_and_does_not_declare_a_direction(): void
    {
        $this->signIn();
        $this->posted('2026-08-10', 'ACCA is required.');

        $this->get(route('insights.trends'))->assertOk()
            ->assertSee('Changes over time')->assertSee('not the Zambian labour market', false)
            ->assertSee('different sources or employers were collected', false)
            ->assertSee('does not say a qualification is "growing"', false)->assertSee('Aug 2026')->assertSee('too few to read');
        $this->get(route('insights.trends', ['subject' => 'level:bachelor']))->assertOk()->assertSee('Bachelor');
        $this->get(route('insights.trends', ['subject' => 'qualification:999999']))->assertOk()->assertSee('Nothing to follow');
        $this->get(route('insights.trends', ['province' => 999999]))->assertSessionHasErrors('province');
    }

    // ---- supply and demand ----

    private function provider(string $key, string $name): int
    {
        return DB::table('organizations')->insertGetId(['key' => $key, 'name' => $name, 'type' => 'university', 'country' => 'ZM', 'origin' => 'person', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function programme(int $qualificationId, int $providerId, bool $verified = false): void
    {
        DB::table('programmes')->insert(['provider_id' => $providerId, 'qualification_id' => $qualificationId, 'verified_at' => $verified ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_supply_is_set_beside_demand_without_claiming_either_is_complete(): void
    {
        $this->many(5, "ACCA is required.\nBachelor's Degree in Accounting.");
        $cima = (int) DB::table('directory_qualifications')->where('key', 'professional.cima')->value('id');
        $bacc = (int) DB::table('directory_qualifications')->where('key', 'academic.bachelor.accounting')->value('id');
        $a = $this->provider('a', 'Provider A');
        $b = $this->provider('b', 'Provider B');
        $this->programme($this->acca_id(), $a, true);
        $this->programme($this->acca_id(), $b);
        $this->programme($cima, $a);              // offered, but no vacancy asks for it

        $report = (new \App\Analytics\SupplyVsDemand(new AdvertisedDemand))->report();
        $rows = $report['rows']->keyBy('name');

        $this->assertSame(['recorded', 2, 2, 1, 5], [$rows['ACCA']->status, $rows['ACCA']->providers, $rows['ACCA']->programmes, $rows['ACCA']->verified, $rows['ACCA']->vacancies]);
        $this->assertSame('none_recorded', $rows["Bachelor's degree in Accounting"]->status, 'asked for, but no provider is recorded here');
        $this->assertSame(['not_asked', 0], [$rows['CIMA']->status, $rows['CIMA']->vacancies]);
        $this->assertSame([2, 1, 3, 2, 1], [$report['asked'], $report['asked_with_provider'], $report['programmes'], $report['providers'], $report['verified_programmes']]);
        $this->assertGreaterThan(0, $bacc);
    }

    public function test_the_supply_page_warns_that_the_provider_side_is_incomplete(): void
    {
        $this->signIn();
        $this->many(5, 'ACCA is required.');

        $this->get(route('insights.supply'))->assertOk()
            ->assertSee('only what has been entered into this directory', false)->assertSee('No provider recorded')
            ->assertSee('not as a finding about the education system', false)->assertSee('not the Zambian labour market', false);
    }

    // ---- CSV export ----

    private function csv(string $report, array $query = []): array
    {
        $response = $this->get(route('insights.export', ['report' => $report] + $query))->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $rows = array_map(fn ($l) => str_getcsv($l, ',', '"', ''), preg_split('/\R/', trim(ltrim($response->streamedContent(), "\xEF\xBB\xBF"))));

        return [array_shift($rows), $rows];
    }

    public function test_a_report_downloads_as_csv_with_its_denominator_and_reliability(): void
    {
        $this->signIn();
        $this->many(5, 'ACCA is required.');
        $this->vacancy('CIMA is required.', 'Analyst');

        [$header, $rows] = $this->csv('qualifications');

        $this->assertSame(['qualification', 'kind', 'vacancies', 'required_vacancies', 'share_percent', 'vacancies_read', 'reliable'], $header);
        $byName = collect($rows)->keyBy(0);
        $this->assertSame(['5', '83', '6', 'yes'], [$byName['ACCA'][2], $byName['ACCA'][4], $byName['ACCA'][5], $byName['ACCA'][6]]);
        $this->assertSame(['1', '', 'no'], [$byName['CIMA'][2], $byName['CIMA'][4], $byName['CIMA'][6]], 'a small sample has a count and no share');
    }

    public function test_every_report_can_be_downloaded_and_follows_the_filters(): void
    {
        $this->signIn();
        $this->many(5, "ACCA is required.\nBachelor's Degree in Accounting.\n1 year experience.");
        Job::first()->update(['status' => 'expired']);

        foreach (['levels', 'entry', 'professional', 'group', 'trends', 'supply'] as $report) {
            $this->csv($report);
        }
        [, $all] = $this->csv('qualifications');
        [, $open] = $this->csv('qualifications', ['scope' => 'open']);
        $this->assertSame('5', collect($all)->firstWhere(0, 'ACCA')[2]);
        $this->assertSame('4', collect($open)->firstWhere(0, 'ACCA')[2]);

        [, $trend] = $this->csv('trends', ['subject' => 'level:bachelor']);
        $this->assertNotEmpty($trend);
        [, $group] = $this->csv('group', ['dimension' => 'occupation', 'group' => '24']);
        $this->assertSame('Business and Administration Professionals', $group[0][0] ?? null);
    }

    public function test_a_name_that_would_run_as_a_spreadsheet_formula_is_defused(): void
    {
        $this->signIn();
        $q = DB::table('directory_qualifications')->insertGetId(['key' => 'evil', 'name' => '=HYPERLINK("http://example.test","click")', 'kind' => 'academic', 'status' => 'unverified', 'origin' => 'person', 'created_at' => now(), 'updated_at' => now()]);
        $this->programme($q, $this->provider('p', 'P'));

        [, $rows] = $this->csv('supply');

        $this->assertStringStartsWith("'=HYPERLINK", collect($rows)->firstWhere(fn ($r) => str_contains($r[0], 'HYPERLINK'))[0]);
    }

    public function test_export_needs_a_sign_in_and_refuses_unknown_reports_and_bad_filters(): void
    {
        $this->get(route('insights.export', 'qualifications'))->assertRedirect();

        $this->signIn();
        $this->get('/insights/export/secrets')->assertNotFound();
        $this->get(route('insights.export', ['report' => 'qualifications', 'scope' => 'x']))->assertSessionHasErrors('scope');
    }

    // ---- the page ----

    public function test_the_page_needs_a_signed_in_administrator(): void
    {
        $this->get(route('insights.index'))->assertRedirect();
    }

    public function test_the_page_says_what_the_figures_are_and_are_not_and_shows_the_coverage(): void
    {
        $this->signIn();
        $this->many(5, 'ACCA is required.');
        $this->vacancy('ACCA is required.')->update(['analysis_status' => 'no_text']);

        $this->get(route('insights.index'))->assertOk()
            ->assertSee('advertised demand in the sources collected here', false)
            ->assertSee('not the Zambian labour market', false)
            ->assertSee('1 vacancy could not be read')
            ->assertSee('ACCA')
            ->assertSee('too few to read as a pattern', false);
    }

    public function test_an_empty_page_says_nothing_was_measured_instead_of_showing_zeros(): void
    {
        $this->signIn();

        $this->get(route('insights.index'))->assertOk()->assertSee('nothing to measure');
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_filters_are_validated(): void
    {
        $this->signIn();

        $this->get(route('insights.index', ['scope' => 'everything']))->assertSessionHasErrors('scope');
        $this->get(route('insights.index', ['province' => 999999]))->assertSessionHasErrors('province');
    }
}
