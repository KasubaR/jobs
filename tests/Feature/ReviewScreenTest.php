<?php

namespace Tests\Feature;

use App\Models\Job;
use App\Processing\RequirementApplier;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesJobs;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/** The review screen: wording the reader could not place, settled once for every vacancy that used it. */
class ReviewScreenTest extends TestCase
{
    use MakesJobs, RefreshDatabase, SignsIn;

    protected function setUp(): void
    {
        parent::setUp();
        config(['job_intel.collector_contact' => 'owner@example.com']);
        $this->seed(SourceSeeder::class);
    }

    private function vacancy(string $text, int $n): Job
    {
        return $this->ad($this->sourceSet()['company'], ['employer' => "Employer {$n} Ltd"], ['raw_title' => 'Officer', 'raw_content' => '<p>'.nl2br(e($text)).'</p>']);
    }

    private function field(string $key): int
    {
        return (int) DB::table('fields_of_study')->where('key', $key)->value('id');
    }

    public function test_it_needs_a_signed_in_administrator(): void
    {
        $this->get(route('review.index'))->assertRedirect();
        $this->post(route('review.store'), [])->assertRedirect();
    }

    public function test_unplaced_wording_is_grouped_across_vacancies(): void
    {
        $this->signIn();
        $this->vacancy("Requirements:\nDiploma in Security Studies.", 1);
        $this->vacancy("Requirements:\nDiploma in security studies.", 2);
        $this->vacancy("Requirements:\nDegree in Accounting.", 3);   // placed: not shown

        $this->get(route('review.index'))->assertOk()->assertSee('Security Studies')->assertDontSee('>Accounting<', false);
        $this->assertSame(1, DB::table('job_qualifications')->whereNotNull('candidate_text')->selectRaw('count(distinct lower(candidate_text)) as n')->value('n'));
    }

    public function test_mapping_settles_every_vacancy_teaches_the_field_and_survives_a_re_read(): void
    {
        $this->signIn();
        $a = $this->vacancy("Requirements:\nDiploma in Security Studies.", 1);
        $b = $this->vacancy("Requirements:\nDiploma in Security Studies.", 2);
        $this->assertSame('needs_review', $a->fresh()->analysis_status);

        $this->post(route('review.store'), ['wording' => 'security studies', 'level' => 'diploma', 'action' => 'map', 'field_id' => $this->field('social-sciences')])
            ->assertRedirect(route('review.index'))->assertSessionHas('status');

        foreach ([$a, $b] as $job) {
            $row = $job->qualifications()->first();
            $this->assertNull($row->candidate_text);
            $this->assertSame($this->field('social-sciences'), $row->field_id);
            $this->assertTrue((bool) $row->reviewed);
            $this->assertSame('person', $row->source);
            $this->assertNotNull($row->directory_qualification_id);
            $this->assertSame('done', $job->fresh()->analysis_status);
            $this->assertSame('Security Studies.', mb_substr($row->evidence, -17), 'the ad\'s own wording is untouched');
        }

        // the matcher learned it: a new ad matches by itself
        $c = $this->vacancy("Requirements:\nDiploma in Security Studies.", 3);
        $this->assertNull($c->qualifications()->first()->candidate_text);
        $this->assertSame($this->field('social-sciences'), $c->qualifications()->first()->field_id);

        // a re-read leaves the person's decision alone and adds nothing
        app(RequirementApplier::class)->apply($a->refresh(), $a->rawPost);
        $this->assertSame(1, $a->qualifications()->count());
        $this->assertTrue((bool) $a->qualifications()->first()->reviewed);

        $this->get(route('review.index'))->assertSee('Nothing is waiting');
    }

    public function test_not_a_qualification_removes_it_and_a_re_read_does_not_bring_it_back(): void
    {
        $this->signIn();
        $job = $this->vacancy("Requirements:\nDiploma in Security Studies.", 1);

        $this->post(route('review.store'), ['wording' => 'security studies', 'level' => 'diploma', 'action' => 'remove'])->assertRedirect();

        $this->assertTrue((bool) $job->qualifications()->first()->removed);
        app(RequirementApplier::class)->apply($job->refresh(), $job->rawPost);
        $this->assertSame(1, $job->qualifications()->count());
        $this->assertTrue((bool) $job->qualifications()->first()->removed);
    }

    public function test_keep_as_written_accepts_the_wording_without_changing_the_taxonomy(): void
    {
        $this->signIn();
        $job = $this->vacancy("Requirements:\nDiploma in Security Studies.", 1);
        $aliases = DB::table('fields_of_study')->pluck('aliases', 'id');

        $this->post(route('review.store'), ['wording' => 'security studies', 'level' => 'diploma', 'action' => 'keep'])->assertRedirect();

        $this->assertTrue((bool) $job->qualifications()->first()->reviewed);
        $this->assertSame('security studies', mb_strtolower($job->qualifications()->first()->candidate_text));
        $this->assertEquals($aliases, DB::table('fields_of_study')->pluck('aliases', 'id'));
    }

    public function test_it_refuses_wording_that_is_not_waiting_and_a_map_without_a_field(): void
    {
        $this->signIn();
        $this->vacancy("Requirements:\nDiploma in Security Studies.", 1);

        $this->post(route('review.store'), ['wording' => 'something else', 'level' => 'diploma', 'action' => 'remove'])->assertStatus(422);
        $this->post(route('review.store'), ['wording' => 'security studies', 'level' => 'diploma', 'action' => 'map'])->assertStatus(422);
        $this->post(route('review.store'), ['wording' => 'security studies', 'level' => 'diploma', 'action' => 'map', 'field_id' => 999999])->assertSessionHasErrors('field_id');
        $this->assertSame(0, DB::table('job_qualifications')->where('reviewed', true)->count());
    }
}
