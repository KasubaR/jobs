<?php

namespace Tests\Feature;

use App\Collectors\JobWebFeedCollector;
use App\Models\Source;
use App\Models\SourceAccessLog;
use App\Models\SourceRun;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SchedulingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['job_intel.collector_contact' => 'owner@example.com']);
        Carbon::setTestNow('2026-11-06 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function jobweb(array $attributes = []): Source
    {
        $this->seed(SourceSeeder::class);
        $source = Source::where('name', 'JobWeb Zambia')->first();
        $source->update(array_merge(['access_status' => 'approved', 'is_active' => true], $attributes));

        return $source->fresh();
    }

    /** Http::fake() stubs stack, so start from a clean client each time. */
    private function resetHttp(): void
    {
        $this->app->forgetInstance(\Illuminate\Http\Client\Factory::class);
        Http::clearResolvedInstances();
    }

    private function feedOk(): void
    {
        $this->resetHttp();
        Http::fake(['*/robots.txt' => Http::response('', 404), '*' => Http::response(file_get_contents(base_path('tests/Fixtures/jobweb-feed.xml')), 200)]);
    }

    private function feedDown(): void
    {
        $this->resetHttp();
        Http::fake(['*/robots.txt' => Http::response('', 404), '*' => Http::response('down', 503)]);
    }

    public function test_next_run_time_follows_the_frequency(): void
    {
        $scheduler = app(\App\Collectors\SourceScheduler::class);

        foreach (['daily' => '2026-11-07 12:00:00', 'weekly' => '2026-11-13 12:00:00', 'monthly' => '2026-12-06 12:00:00'] as $frequency => $expected) {
            $this->assertSame($expected, $scheduler->nextAfterSuccess($frequency)->toDateTimeString(), $frequency);
        }
    }

    public function test_a_successful_run_schedules_the_next_one_by_the_sources_frequency(): void
    {
        $this->feedOk();
        $source = $this->jobweb(['collection_frequency' => 'weekly']);

        app(JobWebFeedCollector::class)->collect($source);

        $this->assertSame('2026-11-13 12:00:00', $source->fresh()->next_collection_at->toDateTimeString());
    }

    public function test_scheduler_skips_sources_that_are_not_due_and_runs_those_that_are(): void
    {
        $this->feedOk();
        $source = $this->jobweb();
        $source->forceFill(['next_collection_at' => now()->addHours(3)])->save();

        $this->artisan('collect:run')->expectsOutputToContain('No approved, active sources are due')->assertSuccessful();
        Http::assertNothingSent();

        Carbon::setTestNow(now()->addHours(4));
        $this->artisan('collect:run')->expectsOutputToContain('JobWeb Zambia: success')->assertSuccessful();
        $this->assertSame('scheduled', SourceRun::latest('id')->first()->trigger);
    }

    public function test_a_source_that_has_never_run_is_due(): void
    {
        $this->feedOk();
        $this->jobweb();

        $this->artisan('collect:run')->expectsOutputToContain('JobWeb Zambia: success')->assertSuccessful();
    }

    public function test_naming_a_source_runs_it_now_even_when_not_due_and_marks_it_manual(): void
    {
        $this->feedOk();
        $this->jobweb()->forceFill(['next_collection_at' => now()->addDays(5)])->save();

        $this->artisan('collect:run', ['source' => 'JobWeb Zambia'])->expectsOutputToContain('success')->assertSuccessful();
        $this->assertSame('manual', SourceRun::latest('id')->first()->trigger);
    }

    public function test_failures_back_off_1h_then_6h_then_24h(): void
    {
        $this->feedDown();
        $source = $this->jobweb();
        $collector = app(JobWebFeedCollector::class);

        foreach ([1 => 60, 2 => 360, 3 => 1440, 4 => 1440] as $failures => $minutes) {
            $collector->collect($source);
            $source = $source->fresh();

            $this->assertSame($failures, $source->consecutive_failures);
            $this->assertEquals(now()->addMinutes($minutes), $source->next_collection_at, "failure {$failures}");
            $this->assertTrue($source->is_active);
        }
    }

    public function test_fifth_failure_in_a_row_switches_the_source_off_and_logs_why(): void
    {
        $this->feedDown();
        $source = $this->jobweb();

        for ($i = 0; $i < 5; $i++) {
            app(JobWebFeedCollector::class)->collect($source);
            $source = $source->fresh();
        }

        $this->assertFalse($source->is_active);
        $this->assertSame(5, $source->consecutive_failures);

        $log = SourceAccessLog::where('field', 'is_active')->where('to_value', '0')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Switched off automatically after 5 failed runs', $log->note);

        // And the scheduler leaves it alone from then on.
        Carbon::setTestNow(now()->addDays(10));
        $this->artisan('collect:run')->expectsOutputToContain('No approved, active sources are due')->assertSuccessful();
    }

    public function test_a_success_resets_the_failure_streak(): void
    {
        $this->feedDown();
        $source = $this->jobweb();
        app(JobWebFeedCollector::class)->collect($source);
        app(JobWebFeedCollector::class)->collect($source->fresh());
        $this->assertSame(2, $source->fresh()->consecutive_failures);

        $this->feedOk();
        app(JobWebFeedCollector::class)->collect($source->fresh());

        $this->assertSame(0, $source->fresh()->consecutive_failures);
    }

    public function test_turning_a_source_back_on_clears_its_streak_and_makes_it_due(): void
    {
        $source = $this->jobweb();
        $source->forceFill(['consecutive_failures' => 5, 'next_collection_at' => now()->addDay(), 'is_active' => false])->save();

        $source = $source->fresh();
        $source->update(['is_active' => true]);

        $this->assertSame(0, $source->fresh()->consecutive_failures);
        $this->assertNull($source->fresh()->next_collection_at);
    }

    public function test_collection_is_checked_every_fifteen_minutes(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('collect:run')->assertSuccessful();

        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'collect:run'));
        $this->assertSame('*/15 * * * *', $event->expression);
    }
}
