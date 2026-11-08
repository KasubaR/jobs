<?php

namespace Tests\Feature;

use App\Collectors\JobWebFeedCollector;
use App\Events\RawJobPostStored;
use App\Jobs\ProcessRawJobPost;
use App\Models\RawJobPost;
use App\Models\Source;
use App\Processing\PendingProcessor;
use App\Processing\RawPostProcessor;
use App\Services\SourceManager;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class ProcessingHandoffTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    protected function setUp(): void
    {
        parent::setUp();
        config(['job_intel.collector_contact' => 'owner@example.com']);
        $this->seed(SourceSeeder::class);
    }

    private function jobweb(): Source
    {
        $source = Source::where('name', 'JobWeb Zambia')->first();
        $source->update(['access_status' => 'approved', 'is_active' => true]);

        return $source->fresh();
    }

    private function feed(?string $xml = null): void
    {
        $this->app->forgetInstance(Factory::class);
        Http::clearResolvedInstances();
        Http::fake(fn (Request $r) => str_ends_with($r->url(), '/robots.txt')
            ? Http::response('', 404)
            : Http::response($xml ?? file_get_contents(base_path('tests/Fixtures/jobweb-feed.xml')), 200));
    }

    private function ad(Source $source, array $attributes = []): RawJobPost
    {
        static $n = 0;
        $n++;

        return RawJobPost::create($attributes + [
            'source_id' => $source->id, 'source_url' => "https://x.test/{$n}", 'raw_title' => "Ad {$n}", 'raw_content' => 'text',
            'content_hash' => hash('sha256', "ad{$n}"), 'fetched_at' => now(), 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    private function processor(bool|\Throwable $result): void
    {
        $this->app->bind(RawPostProcessor::class, fn () => new class($result) implements RawPostProcessor
        {
            public static array $seen = [];

            public function __construct(private bool|\Throwable $result) {}

            public function process(RawJobPost $post): bool
            {
                self::$seen[] = $post->id;

                if ($this->result instanceof \Throwable) {
                    throw $this->result;
                }

                return $this->result;
            }
        });
    }

    // ---- status and event ----

    public function test_a_new_ad_is_stored_as_pending_and_raises_one_event(): void
    {
        Event::fake([RawJobPostStored::class]);
        $this->feed();

        app(JobWebFeedCollector::class)->collect($this->jobweb());

        $this->assertSame(2, RawJobPost::where('processing_status', 'pending')->count());
        Event::assertDispatchedTimes(RawJobPostStored::class, 2);
        Event::assertDispatched(RawJobPostStored::class, fn ($e) => $e->reason === 'new');
    }

    public function test_an_unchanged_ad_raises_nothing_and_keeps_its_status(): void
    {
        $this->feed();
        $source = $this->jobweb();
        app(JobWebFeedCollector::class)->collect($source);
        RawJobPost::query()->update(['processing_status' => 'processed', 'processed_at' => now()]);

        Event::fake([RawJobPostStored::class]);
        app(JobWebFeedCollector::class)->collect($source->fresh());

        Event::assertNotDispatched(RawJobPostStored::class);
        $this->assertSame(2, RawJobPost::where('processing_status', 'processed')->count());
    }

    public function test_a_changed_ad_is_a_new_pending_version_and_raises_an_updated_event(): void
    {
        $xml = file_get_contents(base_path('tests/Fixtures/jobweb-feed.xml'));
        $this->feed($xml);
        $source = $this->jobweb();
        app(JobWebFeedCollector::class)->collect($source);
        RawJobPost::query()->update(['processing_status' => 'processed', 'processed_at' => now()]);

        Event::fake([RawJobPostStored::class]);
        $this->feed(str_replace('Drive haul trucks', 'Operate haul trucks', $xml));
        app(JobWebFeedCollector::class)->collect($source->fresh());

        Event::assertDispatchedTimes(RawJobPostStored::class, 1);
        Event::assertDispatched(RawJobPostStored::class, fn ($e) => $e->reason === 'updated');
        $this->assertSame(1, RawJobPost::where('processing_status', 'pending')->count(), 'only the new version waits');
        $this->assertSame(2, RawJobPost::where('processing_status', 'processed')->count());
    }

    public function test_a_manual_ad_is_handed_on_too(): void
    {
        Event::fake([RawJobPostStored::class]);
        $tsc = Source::where('name', 'Teaching Service Commission')->first();
        app(SourceManager::class)->decideAccess($tsc, 'approved', 'ok');
        app(SourceManager::class)->activate($tsc->fresh());

        app(SourceManager::class)->submitAd($tsc->fresh(), ['title' => 'Teacher at TSC', 'content' => 'Apply now']);

        Event::assertDispatchedTimes(RawJobPostStored::class, 1);
        $this->assertSame('pending', RawJobPost::first()->processing_status);
    }

    public function test_an_ad_that_could_not_be_stored_raises_no_event(): void
    {
        Event::fake([RawJobPostStored::class]);
        $this->feed();
        RawJobPost::creating(fn () => throw new \RuntimeException('database said no'));

        app(JobWebFeedCollector::class)->collect($this->jobweb());

        Event::assertNotDispatched(RawJobPostStored::class);
        RawJobPost::flushEventListeners();
    }

    // ---- the switch ----

    public function test_while_processing_is_off_no_job_is_queued_and_ads_wait(): void
    {
        Queue::fake();
        config(['job_intel.processing_enabled' => false]);
        $this->feed();

        app(JobWebFeedCollector::class)->collect($this->jobweb());

        Queue::assertNotPushed(ProcessRawJobPost::class);
        $this->assertSame(2, RawJobPost::pending()->count());
    }

    public function test_when_processing_is_on_each_new_ad_gets_one_job(): void
    {
        Queue::fake();
        config(['job_intel.processing_enabled' => true]);
        $this->feed();

        app(JobWebFeedCollector::class)->collect($this->jobweb());

        Queue::assertPushed(ProcessRawJobPost::class, 2);
        Queue::assertPushed(ProcessRawJobPost::class, fn ($job) => RawJobPost::find($job->rawJobPostId) !== null);
    }

    public function test_it_is_off_by_default(): void
    {
        $this->assertFalse(config('job_intel.processing_enabled'));
        $this->assertInstanceOf(\App\Processing\ExtractionProcessor::class, app(RawPostProcessor::class));
    }

    // ---- the job ----

    public function test_the_placeholder_leaves_the_ad_pending_and_loses_nothing(): void
    {
        $this->app->bind(RawPostProcessor::class, PendingProcessor::class);
        $post = $this->ad($this->jobweb());

        ProcessRawJobPost::dispatchSync($post->id);

        $fresh = $post->fresh();
        $this->assertSame('pending', $fresh->processing_status);
        $this->assertSame('text', $fresh->raw_content);
    }

    public function test_a_finished_ad_is_marked_processed(): void
    {
        $this->processor(true);
        $post = $this->ad($this->jobweb());

        ProcessRawJobPost::dispatchSync($post->id);

        $fresh = $post->fresh();
        $this->assertSame('processed', $fresh->processing_status);
        $this->assertNotNull($fresh->processed_at);
        $this->assertNull($fresh->processing_error);
    }

    public function test_an_ad_the_processor_does_not_finish_stays_pending(): void
    {
        $this->processor(false);
        $post = $this->ad($this->jobweb());

        ProcessRawJobPost::dispatchSync($post->id);

        $this->assertSame('pending', $post->fresh()->processing_status);
    }

    public function test_a_processor_that_throws_marks_the_ad_failed_with_the_reason(): void
    {
        $this->processor(new \RuntimeException('cannot read this ad'));
        $post = $this->ad($this->jobweb());

        ProcessRawJobPost::dispatchSync($post->id);

        $fresh = $post->fresh();
        $this->assertSame('failed', $fresh->processing_status);
        $this->assertSame('cannot read this ad', $fresh->processing_error);
        $this->assertSame('text', $fresh->raw_content, 'the raw ad is never touched by a failure');
    }

    public function test_the_job_skips_ads_that_are_not_pending_and_ads_that_are_gone(): void
    {
        $this->processor(true);
        $source = $this->jobweb();
        $done = $this->ad($source, ['processing_status' => 'processed']);
        $failed = $this->ad($source, ['processing_status' => 'failed', 'processing_error' => 'earlier']);

        ProcessRawJobPost::dispatchSync($done->id);
        ProcessRawJobPost::dispatchSync($failed->id);
        ProcessRawJobPost::dispatchSync(999999);

        $this->assertSame('earlier', $failed->fresh()->processing_error);
        $this->assertSame('failed', $failed->fresh()->processing_status);
    }

    public function test_a_job_that_crashes_marks_the_ad_failed(): void
    {
        $post = $this->ad($this->jobweb());

        (new ProcessRawJobPost($post->id))->failed(new \RuntimeException('has timed out'));

        $this->assertSame('failed', $post->fresh()->processing_status);
        $this->assertSame('has timed out', $post->fresh()->processing_error);
    }

    public function test_the_job_is_unique_per_ad_and_runs_once(): void
    {
        $job = new ProcessRawJobPost(5);

        $this->assertSame('process-raw-post-5', $job->uniqueId());
        $this->assertSame(1, $job->tries);
    }

    public function test_the_whole_path_from_collection_to_processed_works(): void
    {
        config(['job_intel.processing_enabled' => true]); // queue is "sync" in tests, so jobs run at once
        $this->processor(true);
        $this->feed();

        app(JobWebFeedCollector::class)->collect($this->jobweb());

        $this->assertSame(2, RawJobPost::where('processing_status', 'processed')->count());
        $this->assertSame(0, RawJobPost::pending()->count());
    }

    // ---- processing:dispatch ----

    public function test_dispatch_command_queues_only_pending_ads_up_to_the_limit(): void
    {
        Queue::fake();
        config(['job_intel.processing_enabled' => true]);
        $source = $this->jobweb();
        for ($i = 0; $i < 3; $i++) {
            $this->ad($source);
        }
        $this->ad($source, ['processing_status' => 'processed']);

        $this->artisan('processing:dispatch', ['--limit' => 2])->expectsOutputToContain('Queued 2 pending ads')->assertSuccessful();
        Queue::assertPushed(ProcessRawJobPost::class, 2);
    }

    public function test_dispatch_command_does_nothing_while_processing_is_off(): void
    {
        Queue::fake();
        $this->ad($this->jobweb());

        $this->artisan('processing:dispatch')->expectsOutputToContain('Processing is switched off')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    // ---- screens ----

    public function test_the_dashboard_and_source_page_show_what_is_waiting(): void
    {
        $this->signIn();
        $source = $this->jobweb();
        $this->ad($source);
        $this->ad($source);
        $this->ad($source, ['processing_status' => 'processed']);

        $this->get('/')->assertOk()->assertSee('Waiting to be processed');
        $this->get("/sources/{$source->id}")->assertOk()->assertSee('3')->assertSee('2 waiting to be processed');
    }

    public function test_ads_nearing_the_retention_limit_are_flagged_on_the_dashboard(): void
    {
        $this->signIn();
        $source = $this->jobweb();

        $this->get('/')->assertOk()->assertDontSee('within 30 days of the 90-day limit');

        $this->ad($source, ['first_seen_at' => Carbon::now()->subDays(61)]);
        $this->get('/')->assertOk()->assertSee('within 30 days of the 90-day limit')->assertSee('keeps only its title, link and dates');
    }

    public function test_processed_ads_and_ads_without_raw_text_are_not_flagged(): void
    {
        $this->signIn();
        $source = $this->jobweb();
        $this->ad($source, ['first_seen_at' => Carbon::now()->subDays(80), 'processing_status' => 'processed']);
        $this->ad($source, ['first_seen_at' => Carbon::now()->subDays(80), 'raw_content' => null]);

        $this->get('/')->assertOk()->assertDontSee('within 30 days');
    }

    public function test_failed_processing_is_shown_on_the_dashboard(): void
    {
        $this->signIn();
        $this->ad($this->jobweb(), ['processing_status' => 'failed']);

        $this->get('/')->assertOk()->assertSee('1 ad failed during processing');
    }
}
