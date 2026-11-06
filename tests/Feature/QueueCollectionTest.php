<?php

namespace Tests\Feature;

use App\Jobs\CollectSource;
use App\Models\Source;
use App\Models\SourceRun;
use App\Services\SourceManager;
use Database\Seeders\SourceSeeder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class QueueCollectionTest extends TestCase
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

    private function jobweb(array $attributes = []): Source
    {
        $source = Source::where('name', 'JobWeb Zambia')->first();
        $source->update(['access_status' => 'approved', 'is_active' => true]);
        $source->forceFill($attributes)->save();

        return $source->fresh();
    }

    private function feed(int $status = 200): void
    {
        $this->app->forgetInstance(Factory::class);
        Http::clearResolvedInstances();
        Http::fake(fn (Request $r) => str_ends_with($r->url(), '/robots.txt')
            ? Http::response('', 404)
            : Http::response($status === 200 ? file_get_contents(base_path('tests/Fixtures/jobweb-feed.xml')) : 'down', $status));
    }

    // ---- the scheduler queues, it does not collect ----

    public function test_dispatch_queues_one_job_per_due_source_and_collects_nothing_itself(): void
    {
        Queue::fake();
        $this->feed();
        $jobweb = $this->jobweb();

        $this->artisan('collect:dispatch')->expectsOutputToContain('Queued JobWeb Zambia')->assertSuccessful();

        Queue::assertPushed(CollectSource::class, 1);
        Queue::assertPushed(CollectSource::class, fn ($job) => $job->sourceId === $jobweb->id && $job->trigger === 'scheduled');
        $this->assertSame(0, SourceRun::count());
        Http::assertNothingSent();
    }

    public function test_dispatch_skips_sources_that_are_not_due_not_approved_not_active_or_manual(): void
    {
        Queue::fake();
        $this->jobweb(['next_collection_at' => now()->addHours(2)]); // not due
        $tsc = Source::where('name', 'Teaching Service Commission')->first();
        app(SourceManager::class)->decideAccess($tsc, 'approved', 'ok');
        app(SourceManager::class)->activate($tsc->fresh()); // manual

        $this->artisan('collect:dispatch')->expectsOutputToContain('No approved, active sources are due')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_dispatch_queues_a_source_once_its_time_has_come(): void
    {
        Queue::fake();
        $this->jobweb(['next_collection_at' => now()->subMinute()]);

        $this->artisan('collect:dispatch')->assertSuccessful();

        Queue::assertPushed(CollectSource::class, 1);
    }

    public function test_the_job_is_unique_per_source_runs_once_and_has_a_timeout(): void
    {
        $job = new CollectSource(7);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('collect-source-7', $job->uniqueId());
        $this->assertSame(1, $job->tries);
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame(900, $job->timeout);
        $this->assertSame(1200, $job->uniqueFor);
        $this->assertNotSame($job->uniqueId(), (new CollectSource(8))->uniqueId());
    }

    public function test_a_source_already_waiting_is_not_queued_twice(): void
    {
        // The real queue and lock, not a fake: the second dispatch must be dropped.
        config(['queue.default' => 'database']);
        $source = $this->jobweb();

        CollectSource::dispatch($source->id, 'scheduled');
        CollectSource::dispatch($source->id, 'scheduled');
        CollectSource::dispatch($source->id, 'manual');

        $this->assertSame(1, \DB::table('queue_jobs')->count());
    }

    public function test_the_scheduler_also_starts_the_queue_worker_every_minute(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());

        $dispatch = $events->first(fn ($e) => str_contains($e->command, 'collect:dispatch'));
        $worker = $events->first(fn ($e) => str_contains($e->command, 'queue:work'));

        $this->assertNotNull($dispatch);
        $this->assertSame('*/15 * * * *', $dispatch->expression);
        $this->assertNotNull($worker);
        $this->assertSame('* * * * *', $worker->expression);
        $this->assertStringContainsString('--stop-when-empty', $worker->command);
        $this->assertStringContainsString('--max-time=50', $worker->command);
        $this->assertStringContainsString('--tries=1', $worker->command);
        $this->assertTrue($worker->withoutOverlapping);
    }

    // ---- what the job does when it runs ----

    public function test_the_job_collects_through_the_normal_rules_and_reschedules(): void
    {
        $this->feed();
        $source = $this->jobweb();

        CollectSource::dispatchSync($source->id, 'scheduled');

        $run = SourceRun::first();
        $this->assertSame(['success', 'scheduled', 2], [$run->status, $run->trigger, $run->items_new]);
        $this->assertSame('2026-11-07 18:00:00', $source->fresh()->next_collection_at->toDateTimeString());
    }

    public function test_a_job_that_waited_while_the_source_was_switched_off_does_nothing(): void
    {
        $this->feed();
        $source = $this->jobweb();
        app(SourceManager::class)->deactivate($source);

        CollectSource::dispatchSync($source->id, 'scheduled');

        $this->assertSame(0, SourceRun::count());
        Http::assertNothingSent();
    }

    public function test_a_job_that_waited_while_approval_was_withdrawn_does_nothing(): void
    {
        $this->feed();
        $source = $this->jobweb();
        app(SourceManager::class)->decideAccess($source, 'restricted', 'They asked us to stop.');

        CollectSource::dispatchSync($source->id, 'manual');

        $this->assertSame(0, SourceRun::count());
        Http::assertNothingSent();
    }

    public function test_a_scheduled_job_skips_when_another_run_already_made_it_not_due(): void
    {
        $this->feed();
        $source = $this->jobweb(['next_collection_at' => now()->addHours(5)]);

        CollectSource::dispatchSync($source->id, 'scheduled');
        $this->assertSame(0, SourceRun::count());

        CollectSource::dispatchSync($source->id, 'manual'); // a person's request is never skipped for that reason
        $this->assertSame(1, SourceRun::count());
    }

    public function test_a_deleted_source_is_ignored(): void
    {
        CollectSource::dispatchSync(999999, 'scheduled');

        $this->assertSame(0, SourceRun::count());
    }

    public function test_a_source_that_cannot_start_shows_as_a_failed_run_and_backs_off(): void
    {
        $this->feed();
        $source = $this->jobweb(['collector' => null]); // approved and active, but nothing to collect with

        CollectSource::dispatchSync($source->id, 'scheduled');

        $run = SourceRun::first();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('no collector', $run->error_message);
        $this->assertSame('other', $run->errors()->first()->stage);
        $this->assertSame(1, $source->fresh()->consecutive_failures);
        $this->assertSame('2026-11-07 13:00:00', $source->fresh()->next_collection_at->toDateTimeString());
        Http::assertNothingSent();
    }

    public function test_repeated_start_failures_switch_the_source_off_like_any_failure(): void
    {
        $source = $this->jobweb(['collector' => null]);

        for ($i = 0; $i < 5; $i++) {
            CollectSource::dispatchSync($source->id, 'manual');
        }

        $this->assertFalse($source->fresh()->is_active);
    }

    public function test_a_crashed_job_closes_its_open_run_as_failed_and_the_source_backs_off(): void
    {
        $source = $this->jobweb();
        $open = SourceRun::create(['source_id' => $source->id, 'started_at' => now(), 'status' => 'running', 'trigger' => 'scheduled']);
        $other = SourceRun::create(['source_id' => $this->jobweb()->id + 1, 'started_at' => now(), 'status' => 'running', 'trigger' => 'scheduled']);

        (new CollectSource($source->id))->failed(new \RuntimeException('has timed out'));

        $this->assertSame('failed', $open->fresh()->status);
        $this->assertStringContainsString('background job stopped', $open->fresh()->error_message);
        $this->assertStringContainsString('has timed out', $open->fresh()->error_message);
        $this->assertSame(1, $source->fresh()->consecutive_failures);
        $this->assertSame('running', $other->fresh()->status, 'only this source runs are closed');
    }

    // ---- the Run now button ----

    public function test_run_now_queues_the_job_and_tells_the_person(): void
    {
        $this->signIn();
        config(['queue.default' => 'database']);
        $this->feed();
        $source = $this->jobweb();

        $this->post("/sources/{$source->id}/run")
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'is queued') && str_contains($m, 'Collection runs'));

        $this->assertSame(0, SourceRun::count(), 'nothing runs in the web request');
        $this->assertSame(1, \DB::table('queue_jobs')->count());
        Http::assertNothingSent();

        $payload = json_decode(\DB::table('queue_jobs')->value('payload'), true);
        $this->assertStringContainsString('CollectSource', $payload['displayName']);
    }

    public function test_run_now_with_the_sync_queue_runs_straight_away_and_reports_the_result(): void
    {
        $this->signIn();
        $this->feed();
        $source = $this->jobweb();

        $this->post("/sources/{$source->id}/run")
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'success') && str_contains($m, 'Discovered 2'));

        $this->assertSame('manual', SourceRun::first()->trigger);
    }

    public function test_run_now_is_refused_for_sources_that_cannot_run(): void
    {
        $this->signIn();
        Queue::fake();
        $pending = Source::where('name', 'JobWeb Zambia')->first();

        $this->post("/sources/{$pending->id}/run")->assertSessionHas('error');
        Queue::assertNothingPushed();
    }

    public function test_the_unique_lock_is_released_when_the_job_finishes(): void
    {
        $this->feed();
        $source = $this->jobweb();

        CollectSource::dispatchSync($source->id, 'manual');
        CollectSource::dispatchSync($source->id, 'manual');

        $this->assertSame(2, SourceRun::count());
        $this->assertFalse(Cache::has('laravel_unique_job:'.CollectSource::class.'collect-source-'.$source->id));
    }
}
