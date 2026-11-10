<?php

namespace Tests\Feature;

use App\Analytics\AdvertisedDemand;
use App\Models\Job;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesJobs;
use Tests\TestCase;

/** jobs:retire-source marks a retired source's ads as copies of the same vacancy elsewhere, and nothing else. */
class RetireSourceTest extends TestCase
{
    use MakesJobs, RefreshDatabase;

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        config(['job_intel.collector_contact' => 'owner@example.com']);
        $this->seed(SourceSeeder::class);
        $this->s = $this->sourceSet();
    }

    private static int $n = 0;

    /** The retired source gives "Title at Employer" and no employer; the live source gives the title and the employer apart. */
    private function old(string $title): Job
    {
        self::$n++;

        return $this->ad($this->s['boardA'], ['employer' => null], ['raw_title' => $title, 'raw_content' => '<p>'.$this->words('old')."</p>"]);
    }

    private function live(string $title, string $employer, string $kind = 'boardB'): Job
    {
        self::$n++;

        return $this->ad($this->s[$kind], ['employer' => $employer], ['raw_title' => $title, 'raw_content' => '<p>'.$this->words('live')."</p>"]);
    }

    /** Different text each time, so the scorer does not pair the ads before the command does. */
    private function words(string $tag): string
    {
        $n = self::$n;

        return "Advert {$tag} {$n}: the officer handles files, writes reports, meets partners and visits the field office number {$n} near the {$n} road each week.";
    }

    private function retire(array $args = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('jobs:retire-source', ['source' => (string) $this->s['boardA']->id] + $args);
    }

    public function test_an_old_ad_becomes_a_copy_of_its_twin_and_the_twin_stays_the_main_one(): void
    {
        $old = $this->old('Training Officer at BURN');
        $twin = $this->live('Training Officer', 'BURN');
        $this->assertNotSame('duplicate', $old->fresh()->status);   // the scorer could not pair them

        $this->retire()->expectsOutputToContain('Marked 1 as copies')->assertSuccessful();

        $old = $old->fresh();
        $this->assertSame(['duplicate', $twin->id], [$old->status, (int) $old->duplicate_of_job_id]);
        $this->assertNotSame('duplicate', $twin->fresh()->status);
        $this->assertSame($old->raw_job_post_id, $old->rawPost->id);   // its own stored ad and link are kept
        $pair = DB::table('job_duplicate_candidates')->where('job_a_id', min($old->id, $twin->id))->where('job_b_id', max($old->id, $twin->id))->first();
        $this->assertSame('confirmed', $pair->outcome);
    }

    public function test_the_vacancy_is_counted_once_afterwards(): void
    {
        $this->old('Training Officer at BURN');
        $this->live('Training Officer', 'BURN');
        $this->assertSame(2, (new AdvertisedDemand)->counts()['collected']);

        $this->retire()->assertSuccessful();

        $this->assertSame(1, (new AdvertisedDemand)->counts()['collected']);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $old = $this->old('Training Officer at BURN');
        $this->live('Training Officer', 'BURN');
        $before = DB::table('jobs')->get()->toJson();

        $this->retire(['--dry-run' => true])->expectsOutputToContain('Would mark 1')->assertSuccessful();

        $this->assertSame($before, DB::table('jobs')->get()->toJson());
        $this->assertSame(0, DB::table('job_duplicate_candidates')->where('outcome', 'confirmed')->count());
        $this->assertNotSame('duplicate', $old->fresh()->status);
    }

    public function test_an_ad_with_a_different_employer_is_not_a_copy(): void
    {
        $old = $this->old('Training Officer at BURN');
        $this->live('Training Officer', 'World Vision');

        $this->retire()->expectsOutputToContain('no twin on another source')->assertSuccessful();

        $this->assertNotSame('duplicate', $old->fresh()->status);
    }

    public function test_an_ad_with_no_twin_is_left_and_listed(): void
    {
        $old = $this->old("Submit CVs at Vonne's Beauty Spa");

        $this->retire()->expectsOutputToContain('left as it is')->expectsOutputToContain('Marked 0 as copies; 1 left')->assertSuccessful();

        $this->assertNotSame('duplicate', $old->fresh()->status);
    }

    public function test_two_possible_twins_is_ambiguous_and_nothing_is_marked(): void
    {
        $old = $this->old('Training Officer at BURN');
        $this->live('Training Officer', 'BURN');
        $this->live('Training Officer', 'BURN', 'gov');

        $this->retire()->expectsOutputToContain('more than one possible twin')->assertSuccessful();

        $this->assertNotSame('duplicate', $old->fresh()->status);
    }

    public function test_a_twin_on_the_same_retired_source_does_not_count(): void
    {
        $this->old('Training Officer at BURN');
        self::$n++;
        $same = $this->ad($this->s['boardA'], ['employer' => 'BURN'], ['raw_title' => 'Training Officer', 'raw_content' => '<p>'.$this->words('same').'</p>']);

        $this->retire()->expectsOutputToContain('no twin on another source')->assertSuccessful();

        $this->assertNotSame('duplicate', $same->fresh()->status);
    }

    public function test_a_twin_that_is_itself_a_copy_or_rejected_is_not_used(): void
    {
        $old = $this->old('Training Officer at BURN');
        $this->live('Training Officer', 'BURN')->update(['status' => 'rejected']);

        $this->retire()->assertSuccessful();

        $this->assertNotSame('duplicate', $old->fresh()->status);
    }

    public function test_running_it_twice_changes_nothing_more(): void
    {
        $this->old('Training Officer at BURN');
        $this->live('Training Officer', 'BURN');
        $this->retire()->assertSuccessful();
        $after = DB::table('jobs')->get()->toJson();

        $this->retire()->expectsOutputToContain('Marked 0 as copies')->assertSuccessful();

        $this->assertSame($after, DB::table('jobs')->get()->toJson());
    }

    public function test_the_employer_may_be_written_a_little_differently(): void
    {
        $old = $this->old('Finance Officer at Zambia Cargo and Logistics Limited');
        $this->live('Finance Officer', 'Zambia Cargo and Logistics Ltd');

        $this->retire()->assertSuccessful();

        $this->assertSame('duplicate', $old->fresh()->status);
    }

    public function test_a_person_can_undo_one_with_this_is_not_a_copy(): void
    {
        $old = $this->old('Training Officer at BURN');
        $this->live('Training Officer', 'BURN');
        $this->retire()->assertSuccessful();

        app(\App\Duplicates\DuplicateDetector::class)->separate($old->fresh());

        $this->assertNotSame('duplicate', $old->fresh()->status);
    }

    public function test_it_finds_the_source_by_name_and_refuses_an_unknown_or_unclear_one(): void
    {
        $this->artisan('jobs:retire-source', ['source' => 'no such source'])->expectsOutputToContain('No source matches')->assertFailed();
        $this->artisan('jobs:retire-source', ['source' => '9999'])->expectsOutputToContain('No source matches')->assertFailed();
        $this->artisan('jobs:retire-source', ['source' => ''])->assertFailed();   // matches every source, so it is unclear which

        $name = $this->s['boardA']->name;
        $this->artisan('jobs:retire-source', ['source' => $name, '--dry-run' => true])->assertSuccessful();
    }

    public function test_it_warns_when_the_source_is_still_switched_on(): void
    {
        $this->s['boardA']->update(['access_status' => 'approved', 'is_active' => true]);

        $this->retire(['--dry-run' => true])->expectsOutputToContain('still switched on')->assertSuccessful();
    }
}
