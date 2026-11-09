<?php

namespace Tests\Feature;

use App\Models\Job;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesJobs;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/** The Qualifications page: what employers ask for, each vacancy counted once. */
class QualificationsPageTest extends TestCase
{
    use MakesJobs, RefreshDatabase, SignsIn;

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-08 12:00:00');
        config(['job_intel.collector_contact' => 'owner@example.com']);
        $this->seed(SourceSeeder::class);
        $this->s = $this->sourceSet();
    }

    /** A vacancy that is its own thing: a different employer, title and text from every other one made here. */
    private function vacancy(string $title, string $employer, array $facts = [], ?string $body = null): Job
    {
        static $n = 0;
        $n++;

        return $this->ad($this->s['boardA'], ['employer' => $employer] + $facts, [
            'raw_title' => $title,
            'raw_content' => '<p>'.($body ?? "Vacancy number {$n} for {$title}. ".str_repeat("The {$title} works at {$employer} on task {$n} and reports each week. ", 6)).'</p>',
        ]);
    }

    private function page(string $query = ''): \Illuminate\Testing\TestResponse
    {
        return $this->get('/qualifications'.$query);
    }

    // ---- access and menu ----

    public function test_it_needs_a_signed_in_administrator(): void
    {
        $this->get('/qualifications')->assertRedirect('/login');
    }

    public function test_the_menu_leads_with_qualifications_and_the_job_list_is_called_vacancies(): void
    {
        $this->signIn();

        $html = $this->get('/')->getContent();

        $this->assertLessThan(strpos($html, 'Vacancies'), strpos($html, 'Qualifications')); // qualifications first
        $this->assertGreaterThan(strpos($html, 'Dashboard'), strpos($html, 'Qualifications'));
        $this->assertStringContainsString('href="'.route('qualifications.index').'"', $html);
        $this->get('/jobs')->assertOk()->assertSee('Vacancies')->assertDontSee('>Jobs<', false);
    }

    public function test_an_empty_dataset_says_so(): void
    {
        $this->signIn();

        $this->page()->assertOk()->assertSee('No vacancies yet')->assertViewHas('total', 0);
    }

    // ---- the counts ----

    private function four(): array
    {
        return [
            $this->vacancy('Accountant', 'Alpha Ltd', ['education' => "Bachelor's in Accounting", 'experience' => '3 years experience']),
            $this->vacancy('Auditor', 'Beta Ltd', ['education' => 'Bachelor of Science', 'experience' => '3 years experience']),
            $this->vacancy('Nurse', 'Gamma Clinic', ['education' => 'Diploma in Nursing', 'experience' => '5 years experience']),
            $this->vacancy('Cook', 'Delta Foods', []),  // states no qualification
        ];
    }

    public function test_it_counts_levels_qualifications_fields_and_experience_with_their_shares(): void
    {
        $this->signIn();
        $this->four();

        $r = $this->page()->assertOk()
            ->assertViewHas('total', 4)->assertViewHas('read', 3)->assertViewHas('nothing', 1)->assertViewHas('notRecognised', 0);

        $levels = $r->viewData('levels')->pluck('n', 'name')->all();
        $this->assertSame(["Bachelor's Degree" => 2, 'Diploma' => 1], $levels);

        $labels = $r->viewData('labels')->pluck('n', 'name')->all();
        $this->assertSame(1, $labels["Bachelor's Degree in Accounting"]);
        $this->assertSame(1, $labels['Diploma in Nursing']);

        $this->assertSame(['Accounting' => 1, 'Nursing' => 1], $r->viewData('fields')->pluck('n', 'name')->all());
        $this->assertSame(['3 Years' => 2, '5 Years' => 1], $r->viewData('experience')->pluck('n', 'name')->all());
        $this->assertSame(3, $r->viewData('experienceRead'));

        // shown as shares of the vacancies that state one: 2 of 3 is 67%
        $r->assertSee("Bachelor's Degree")->assertSee('67%')->assertSee('33%');
    }

    public function test_a_list_of_subjects_counts_each_subject_once(): void
    {
        $this->signIn();
        $this->vacancy('Buyer', 'Alpha Ltd', ['education' => 'Degree in Supply Chain Management, Procurement, Banking & Finance, Law or Engineering']);
        $this->vacancy('Clerk', 'Beta Ltd', ['education' => 'Degree in Procurement']);

        $r = $this->page()->assertOk();

        $labels = $r->viewData('labels')->pluck('n', 'name')->all();
        $this->assertSame(2, $labels['Degree in Procurement']);
        foreach (['Supply Chain Management', 'Banking & Finance', 'Law', 'Engineering'] as $subject) {
            $this->assertSame(1, $labels["Degree in {$subject}"], $subject);
        }
        $this->assertSame(2, $r->viewData('fields')->pluck('n', 'name')->all()['Procurement']);
        $this->assertSame(2, $r->viewData('labelsNamed'));
    }

    public function test_a_vacancy_on_several_sites_counts_once_and_rejected_ads_not_at_all(): void
    {
        $this->signIn();
        $facts = ['education' => 'Diploma in Nursing'];
        $this->ad($this->s['company'], $facts + ['employer' => 'Sample Mining Ltd']);
        $copy = $this->ad($this->s['boardA'], $facts + ['employer' => 'Sample Mining Ltd']);   // the same vacancy elsewhere
        $this->ad($this->s['gov'], $facts + ['employer' => 'Other Ltd'], ['raw_title' => 'Tender for the supply of uniforms']); // not a vacancy
        $this->assertSame('duplicate', $copy->fresh()->status);

        $this->page()->assertViewHas('total', 1)->assertViewHas('read', 1);
    }

    public function test_text_that_was_found_but_not_recognised_is_counted_apart_and_linked_to_the_screen_that_fixes_it(): void
    {
        $this->signIn();
        $this->vacancy('Driver', 'Epsilon Ltd', ['education' => "Valid driver's licence"]);

        $this->page()->assertViewHas('notRecognised', 1)->assertViewHas('read', 0)->assertSee('Map them')->assertSee(route('values.index'), false);
    }

    public function test_no_template_directive_is_printed_as_text(): void
    {
        $this->signIn();

        foreach ([[], ['no qualification', 'x']] as $case) {
            $this->vacancy('Accountant', 'Alpha Ltd', ['education' => "Valid driver's licence"]);
            $this->vacancy('Auditor', 'Beta Ltd', ['education' => 'Bachelor of Science']);
            $html = $this->page()->getContent();

            foreach (['@else', '@endif', '@if', '@foreach', '@forelse', '@empty'] as $directive) {
                $this->assertStringNotContainsString($directive, $html, "'{$directive}' was printed on the page");
            }
        }
    }

    public function test_the_page_is_honest_that_the_reading_is_partial(): void
    {
        $this->signIn();
        $this->four();

        $this->page()->assertSee('1 of 4 vacancies has no qualification counted below')->assertSee('share of the 3 that were read');
    }

    public function test_there_is_no_warning_when_every_vacancy_states_a_qualification(): void
    {
        $this->signIn();
        $this->vacancy('Accountant', 'Alpha Ltd', ['education' => 'Bachelor of Science']);

        $this->page()->assertDontSee('no qualification counted below');
    }

    /** A vacancy whose requirements section says $line, analysed by the real reader. */
    private function wanting(string $line, int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            $n = random_int(1000, 9999999);
            $this->vacancy("Officer {$n}", "Employer {$n} Ltd", [], "Requirements:<br>{$line}<br>Our office number {$n} serves clients near the {$n} road every working day.");
        }
    }

    private function skillRows(): \Illuminate\Support\Collection
    {
        return $this->page()->viewData('skills')->keyBy('name');
    }

    public function test_skills_are_counted_from_the_whole_ad_once_per_vacancy(): void
    {
        $this->signIn();
        $this->wanting('Proficiency in Microsoft Excel is required.', 2);
        $this->wanting('Knowledge of budgeting is an added advantage.');   // an advantage is not "asked for"

        $skills = $this->skillRows();

        $this->assertSame(2, $skills['Microsoft Excel']->vacancies);
        $this->assertArrayNotHasKey('Budgeting', $skills->all());
        $this->page()->assertDontSee('Skills are not read yet')->assertSee('Microsoft Excel')->assertSee('too few for a share');
    }

    public function test_a_skill_gets_a_share_only_from_five_vacancies(): void
    {
        $this->signIn();
        $this->wanting('Proficiency in Microsoft Excel is required.', 5);

        $row = $this->skillRows()['Microsoft Excel'];

        $this->assertSame(5, $row->vacancies);
        $this->assertSame(100.0, (float) $row->share);
        $this->page()->assertSee('100%');
    }

    public function test_a_skill_on_a_removed_item_or_a_rejected_ad_is_not_counted(): void
    {
        $this->signIn();
        $this->wanting('Proficiency in Microsoft Excel is required.', 2);
        Job::orderBy('id')->first()->skills()->update(['removed' => true]);
        Job::orderBy('id')->skip(1)->first()->update(['status' => 'rejected']);

        $this->assertSame([], $this->skillRows()->all());
        $this->page()->assertSee('No skill has been read');
    }

    // ---- filters ----

    public function test_open_now_leaves_out_expired_vacancies(): void
    {
        $this->signIn();
        $this->vacancy('Accountant', 'Alpha Ltd', ['education' => 'Bachelor of Science']);
        $old = $this->vacancy('Auditor', 'Beta Ltd', ['education' => 'Diploma in Nursing', 'valid_through' => '2026-10-01', 'date_posted' => '2026-09-01']);
        $this->assertSame('expired', $old->fresh()->status);

        $this->page()->assertViewHas('total', 2);
        $this->page('?scope=open')->assertViewHas('total', 1)->assertViewHas('read', 1);
    }

    public function test_the_province_filter_counts_that_provinces_vacancies_including_its_towns(): void
    {
        $this->signIn();
        $this->vacancy('Accountant', 'Alpha Ltd', ['education' => 'Bachelor of Science', 'locations' => [['locality' => 'Solwezi']]]);
        $this->vacancy('Auditor', 'Beta Ltd', ['education' => 'Diploma in Nursing', 'locations' => [['locality' => 'Kitwe']]]);
        $this->vacancy('Teacher', 'Gamma School', ['education' => 'Diploma in Nursing', 'locations' => [['locality' => 'Ndola']]]);
        $copperbelt = DB::table('locations')->where('name', 'Copperbelt')->where('type', 'province')->value('id');

        $r = $this->page("?province={$copperbelt}")->assertViewHas('total', 2);

        $this->assertSame(['Diploma' => 2], $r->viewData('levels')->pluck('n', 'name')->all());
        $this->page()->assertViewHas('total', 3);
    }

    public function test_bad_filters_are_refused(): void
    {
        $this->signIn();
        $town = DB::table('locations')->where('name', 'Kitwe')->value('id');

        $this->page('?scope=everything')->assertSessionHasErrors('scope');
        $this->page('?province=999999')->assertSessionHasErrors('province');
        $this->page("?province={$town}")->assertSessionHasErrors('province'); // a town is not a province
        $this->page('?province=abc')->assertSessionHasErrors('province');
    }

    // ---- safety ----

    public function test_wording_from_an_ad_is_escaped(): void
    {
        $this->signIn();
        $job = $this->vacancy('Accountant', 'Alpha Ltd', ['education' => 'Bachelor of Science']);
        $job->update(['qualification_field' => '<script>alert(1)</script>, <img src=x onerror=alert(2)>']);

        $this->page()->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<img src=x', false)->assertSee('&lt;script&gt;', false);
    }
}
