<?php

namespace Tests\Feature;

use App\Collectors\SourceScheduler;
use App\Models\Source;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class FrequencyTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    protected function setUp(): void
    {
        parent::setUp();
        config(['job_intel.collector_contact' => 'owner@example.com']);
        Carbon::setTestNow('2026-11-07 12:00:00');
        $this->seed(SourceSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function jobweb(): Source
    {
        return Source::where('name', 'JobWeb Zambia')->first();
    }

    private function form(Source $source, array $overrides = []): array
    {
        return $overrides + [
            'name' => $source->name, 'source_type' => $source->source_type, 'website_url' => $source->website_url,
            'job_listing_url' => $source->job_listing_url, 'collection_method' => $source->collection_method,
            'collector' => $source->collector, 'collection_frequency' => $source->collection_frequency,
            'min_interval_seconds' => $source->min_interval_seconds ?? 1, 'retain_raw' => '1',
        ];
    }

    public function test_every_frequency_has_its_own_interval(): void
    {
        $scheduler = app(SourceScheduler::class);
        $from = Carbon::parse('2026-11-07 12:00:00');

        $expected = [
            'every_6_hours' => '2026-11-07 18:00:00',
            'every_12_hours' => '2026-11-08 00:00:00',
            'daily' => '2026-11-08 12:00:00',
            'every_2_days' => '2026-11-09 12:00:00',
            'every_3_days' => '2026-11-10 12:00:00',
            'weekly' => '2026-11-14 12:00:00',
            'monthly' => '2026-12-07 12:00:00',
        ];

        $this->assertSame(array_keys($expected), array_keys(Source::FREQUENCIES), 'the list and the test must cover the same options, in order');

        foreach ($expected as $frequency => $next) {
            $this->assertSame($next, $scheduler->nextAfterSuccess($frequency, $from)->toDateTimeString(), $frequency);
        }
    }

    public function test_the_scheduler_does_not_change_the_date_it_was_given(): void
    {
        $from = Carbon::parse('2026-11-07 12:00:00');
        app(SourceScheduler::class)->nextAfterSuccess('every_3_days', $from);

        $this->assertSame('2026-11-07 12:00:00', $from->toDateTimeString());
    }

    public function test_the_suggestions_follow_your_guide_and_only_use_real_options(): void
    {
        $this->assertSame('every_6_hours', Source::SUGGESTED_FREQUENCY['job_board']);
        $this->assertSame('daily', Source::SUGGESTED_FREQUENCY['company']);
        $this->assertSame('daily', Source::SUGGESTED_FREQUENCY['government']);
        $this->assertContains(Source::SUGGESTED_FREQUENCY['media'], ['every_2_days', 'every_3_days']);

        foreach (Source::SUGGESTED_FREQUENCY as $type => $frequency) {
            $this->assertArrayHasKey($type, Source::SOURCE_TYPES, $type);
            $this->assertArrayHasKey($frequency, Source::FREQUENCIES, $frequency);
        }
        foreach (array_keys(Source::SOURCE_TYPES) as $type) {
            $this->assertArrayHasKey($type, Source::SUGGESTED_FREQUENCY, "no suggestion for {$type}");
        }
    }

    public function test_seeded_sources_use_valid_frequencies_and_jobweb_is_every_6_hours(): void
    {
        foreach (Source::all() as $source) {
            $this->assertArrayHasKey($source->collection_frequency, Source::FREQUENCIES, $source->name);
        }
        $this->assertSame('every_6_hours', $this->jobweb()->collection_frequency);
    }

    public function test_the_form_accepts_every_option_and_rejects_others(): void
    {
        $this->signIn();
        $source = $this->jobweb();

        foreach (array_keys(Source::FREQUENCIES) as $frequency) {
            $this->put("/sources/{$source->id}", $this->form($source, ['collection_frequency' => $frequency]))->assertRedirect("/sources/{$source->id}");
            $this->assertSame($frequency, $source->fresh()->collection_frequency);
        }

        foreach (['hourly', 'every_1_hour', '', 'EVERY_6_HOURS'] as $bad) {
            $this->put("/sources/{$source->id}", $this->form($source, ['collection_frequency' => $bad]))->assertSessionHasErrors('collection_frequency');
        }
    }

    public function test_the_add_page_offers_all_options_and_the_suggestions_but_the_edit_page_does_not(): void
    {
        $this->signIn();

        $add = $this->get('/sources/create')->assertOk();
        foreach (Source::FREQUENCIES as $label) {
            $add->assertSee($label);
        }
        $add->assertSee('data-suggestions', false)->assertSee('every_6_hours', false)->assertSee('only a suggestion');

        $this->get("/sources/{$this->jobweb()->id}/edit")->assertOk()->assertDontSee('data-suggestions', false);
    }

    public function test_a_source_runs_when_its_six_hours_are_up(): void
    {
        $this->app->forgetInstance(Factory::class);
        Http::clearResolvedInstances();
        Http::fake(fn (Request $r) => str_ends_with($r->url(), '/robots.txt')
            ? Http::response('', 404)
            : Http::response(file_get_contents(base_path('tests/Fixtures/jobweb-feed.xml')), 200));

        $source = $this->jobweb();
        $source->update(['access_status' => 'approved', 'is_active' => true]);
        $this->artisan('collect:run')->expectsOutputToContain('JobWeb Zambia: success')->assertSuccessful();

        $this->assertSame('2026-11-07 18:00:00', $source->fresh()->next_collection_at->toDateTimeString());

        Carbon::setTestNow('2026-11-07 17:59:00');
        $this->artisan('collect:run')->expectsOutputToContain('No approved, active sources are due')->assertSuccessful();

        Carbon::setTestNow('2026-11-07 18:01:00');
        $this->artisan('collect:run')->expectsOutputToContain('JobWeb Zambia: success')->assertSuccessful();
    }

    public function test_changing_the_frequency_counts_from_the_last_collection(): void
    {
        $this->signIn();
        $source = $this->jobweb();
        $source->forceFill([
            'collection_frequency' => 'daily',
            'last_collection_at' => Carbon::parse('2026-11-07 02:00:00'),
            'next_collection_at' => Carbon::parse('2026-11-08 02:00:00'),
        ])->save();

        $this->put("/sources/{$source->id}", $this->form($source->fresh(), ['collection_frequency' => 'every_6_hours']));

        // last collected 10 hours ago + 6 hours = already due
        $this->assertSame('2026-11-07 08:00:00', $source->fresh()->next_collection_at->toDateTimeString());

        $this->put("/sources/{$source->id}", $this->form($source->fresh(), ['collection_frequency' => 'every_3_days']));
        $this->assertSame('2026-11-10 02:00:00', $source->fresh()->next_collection_at->toDateTimeString());
    }

    public function test_a_source_that_is_backing_off_keeps_its_retry_time_when_the_frequency_changes(): void
    {
        $this->signIn();
        $source = $this->jobweb();
        $source->forceFill([
            'last_collection_at' => Carbon::parse('2026-11-06 02:00:00'),
            'consecutive_failures' => 2,
            'next_collection_at' => Carbon::parse('2026-11-07 18:00:00'),
        ])->save();

        $this->put("/sources/{$source->id}", $this->form($source->fresh(), ['collection_frequency' => 'weekly']));

        $this->assertSame('2026-11-07 18:00:00', $source->fresh()->next_collection_at->toDateTimeString());
        $this->assertSame('weekly', $source->fresh()->collection_frequency);
    }

    public function test_a_source_never_collected_stays_due_at_the_next_check_when_the_frequency_changes(): void
    {
        $this->signIn();
        $source = $this->jobweb();
        $this->assertNull($source->last_collection_at);

        $this->put("/sources/{$source->id}", $this->form($source, ['collection_frequency' => 'monthly']));

        $this->assertNull($source->fresh()->next_collection_at);
    }

    public function test_editing_something_else_does_not_move_the_schedule(): void
    {
        $this->signIn();
        $source = $this->jobweb();
        $source->forceFill(['last_collection_at' => Carbon::parse('2026-11-07 02:00:00'), 'next_collection_at' => Carbon::parse('2026-11-07 20:00:00')])->save();

        $this->put("/sources/{$source->id}", $this->form($source->fresh(), ['name' => 'JobWeb Zambia (renamed)']));

        $this->assertSame('2026-11-07 20:00:00', $source->fresh()->next_collection_at->toDateTimeString());
    }

    public function test_the_list_and_source_page_show_the_new_labels(): void
    {
        $this->signIn();

        $this->get('/sources')->assertOk()->assertSee('Every 6 hours');
        $this->get("/sources/{$this->jobweb()->id}")->assertOk()->assertSee('Every 6 hours');
    }
}
