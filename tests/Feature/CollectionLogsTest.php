<?php

namespace Tests\Feature;

use App\Collectors\BaseCollector;
use App\Collectors\JobWebFeedCollector;
use App\Collectors\ManualSubmissionCollector;
use App\Models\RawJobPost;
use App\Models\Source;
use App\Models\SourceRun;
use App\Models\SourceRunError;
use App\Services\SourceManager;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class CollectionLogsTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    protected function setUp(): void
    {
        parent::setUp();
        config(['job_intel.collector_contact' => 'owner@example.com']);
        $this->seed(SourceSeeder::class);
    }

    protected function tearDown(): void
    {
        RawJobPost::flushEventListeners();
        parent::tearDown();
    }

    private function jobweb(): Source
    {
        $source = Source::where('name', 'JobWeb Zambia')->first();
        $source->update(['access_status' => 'approved', 'is_active' => true]);

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

    public function test_a_second_run_counts_unchanged_ads_as_collected(): void
    {
        $this->feed();
        $source = $this->jobweb();

        $first = app(JobWebFeedCollector::class)->collect($source);
        $second = app(JobWebFeedCollector::class)->collect($source->fresh());

        $this->assertSame([2, 2, 0, 0, 0], [$first->items_found, $first->items_new, $first->items_unchanged, $first->items_failed, $first->collectedCount() - 2]);
        $this->assertSame([2, 0, 0, 2, 0], [$second->items_found, $second->items_new, $second->items_updated, $second->items_unchanged, $second->items_failed]);
        $this->assertSame(2, $second->collectedCount());
        $this->assertSame(0, SourceRunError::count());
    }

    public function test_one_ad_that_cannot_be_stored_does_not_lose_the_others(): void
    {
        $this->feed();
        RawJobPost::creating(function (RawJobPost $post) {
            if (str_contains($post->raw_title, 'Driver')) {
                throw new \RuntimeException('database said no');
            }
        });

        $run = app(JobWebFeedCollector::class)->collect($this->jobweb());

        $this->assertSame('partial', $run->status);
        $this->assertSame([2, 1, 1], [$run->items_found, $run->items_new, $run->items_failed]);
        $this->assertSame(1, RawJobPost::count());
        $this->assertStringContainsString('Could not store ad', $run->error_message);

        $error = $run->errors()->first();
        $this->assertSame('store', $error->stage);
        $this->assertSame('https://feed.test/jobs/driver/', $error->source_url);
        $this->assertSame('database said no', $error->message);
    }

    public function test_a_failed_run_saves_its_reason_as_an_error_row(): void
    {
        $this->feed(503);

        $run = app(JobWebFeedCollector::class)->collect($this->jobweb());

        $this->assertSame('failed', $run->status);
        $this->assertSame([0, 0, 0], [$run->items_found, $run->items_new, $run->items_failed]);
        $this->assertSame(1, $run->http_errors);

        $error = $run->errors()->first();
        $this->assertSame('fetch', $error->stage);
        $this->assertSame('Site returned HTTP 503', $error->message);
        $this->assertSame('Site returned HTTP 503', $run->error_message);
    }

    public function test_a_flood_of_problems_is_capped_and_summarised(): void
    {
        $collector = new class extends BaseCollector
        {
            public function method(): string
            {
                return 'rss';
            }

            protected function fetch(Source $source): array
            {
                for ($i = 1; $i <= 250; $i++) {
                    $this->problem('ad_page', "https://x.test/ad/{$i}", 'boom');
                }

                return [];
            }
        };

        $run = $collector->collect($this->jobweb());

        $this->assertSame('partial', $run->status);
        $this->assertSame(250, $run->items_failed);
        $this->assertSame(201, $run->errors()->count()); // 200 listed + one "N more" row
        $this->assertStringContainsString('50 more problems were not listed', $run->errors()->orderByDesc('id')->first()->message);
        $this->assertStringContainsString('and 247 more', $run->error_message);
        $this->assertLessThanOrEqual(1000, strlen($run->error_message));
    }

    public function test_listing_problems_are_logged_but_are_not_failed_ads(): void
    {
        $collector = new class extends BaseCollector
        {
            public function method(): string
            {
                return 'rss';
            }

            protected function fetch(Source $source): array
            {
                $this->problem('listing', 'https://x.test/jobs?page=2', 'Page 2: Site returned HTTP 500');

                return [];
            }
        };

        $run = $collector->collect($this->jobweb());

        $this->assertSame('partial', $run->status);
        $this->assertSame(0, $run->items_failed);
        $this->assertSame('listing', $run->errors()->first()->stage);
    }

    public function test_a_manual_ad_that_cannot_be_stored_fails_the_run_and_is_reported(): void
    {
        $tsc = Source::where('name', 'Teaching Service Commission')->first();
        app(SourceManager::class)->decideAccess($tsc, 'approved', 'ok');
        app(SourceManager::class)->activate($tsc->fresh());
        RawJobPost::creating(fn () => throw new \RuntimeException('disk full'));

        $run = app(ManualSubmissionCollector::class)->submit($tsc->fresh(), ['title' => 'Teacher at TSC', 'content' => 'Apply now']);

        $this->assertSame('failed', $run->status);
        $this->assertSame([1, 0, 1], [$run->items_found, $run->items_new, $run->items_failed]);
        $this->assertSame('disk full', $run->errors()->first()->message);
        $this->assertNull($tsc->fresh()->last_collection_at);

        $this->signIn();
        $this->post("/sources/{$tsc->id}/ads", ['title' => 'Another at TSC', 'content' => 'Apply now'])->assertSessionHas('error');
    }

    // ---- runs that never finished ----

    private function runningRun(Source $source, int $minutesAgo): SourceRun
    {
        return SourceRun::create(['source_id' => $source->id, 'started_at' => now()->subMinutes($minutesAgo), 'status' => 'running', 'trigger' => 'scheduled']);
    }

    public function test_cleanup_fails_runs_stuck_for_over_thirty_minutes_and_lets_the_source_back_off(): void
    {
        $source = $this->jobweb();
        $stuck = $this->runningRun($source, 31);
        $recent = $this->runningRun($source, 5);

        $this->artisan('runs:cleanup')->expectsOutputToContain('Marked 1 stuck run as failed')->assertSuccessful();

        $this->assertSame('failed', $stuck->fresh()->status);
        $this->assertNotNull($stuck->fresh()->finished_at);
        $this->assertStringContainsString('did not finish within 30 minutes', $stuck->fresh()->error_message);
        $this->assertSame('other', $stuck->errors()->first()->stage);
        $this->assertSame('running', $recent->fresh()->status);

        $fresh = $source->fresh();
        $this->assertSame(1, $fresh->consecutive_failures);
        $this->assertNotNull($fresh->next_collection_at);
    }

    public function test_cleanup_leaves_finished_runs_alone_and_says_so_when_nothing_is_stuck(): void
    {
        $source = $this->jobweb();
        SourceRun::create(['source_id' => $source->id, 'started_at' => now()->subHours(5), 'finished_at' => now()->subHours(5), 'status' => 'success']);

        $this->artisan('runs:cleanup')->expectsOutputToContain('No stuck runs')->assertSuccessful();
        $this->assertSame(0, $source->fresh()->consecutive_failures);
    }

    public function test_cleanup_is_scheduled(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('runs:cleanup')->assertSuccessful();
    }

    // ---- screens ----

    public function test_the_runs_list_shows_discovered_collected_unchanged_and_failed(): void
    {
        $this->signIn();
        $source = $this->jobweb();
        SourceRun::create([
            'source_id' => $source->id, 'started_at' => now(), 'finished_at' => now(), 'status' => 'partial',
            'items_found' => 10, 'items_new' => 4, 'items_updated' => 1, 'items_unchanged' => 3, 'items_failed' => 2,
            'error_message' => 'Ad page failed (https://x.test/a): HTTP 503',
        ]);

        $this->get('/runs')->assertOk()
            ->assertSee('Discovered')->assertSee('Collected')->assertSee('Unchanged')->assertSee('Failed')
            ->assertSee('Ad page failed');
    }

    public function test_a_run_page_lists_every_problem_and_escapes_them(): void
    {
        $this->signIn();
        $source = $this->jobweb();
        $run = SourceRun::create(['source_id' => $source->id, 'started_at' => now(), 'finished_at' => now(), 'status' => 'partial', 'items_found' => 2, 'items_failed' => 2]);
        SourceRunError::create(['source_run_id' => $run->id, 'stage' => 'ad_page', 'source_url' => 'https://x.test/ad/1', 'message' => '<script>alert(1)</script>']);
        SourceRunError::create(['source_run_id' => $run->id, 'stage' => 'store', 'source_url' => 'javascript:alert(2)', 'message' => 'database said no']);

        $page = $this->get("/runs/{$run->id}")->assertOk();

        $page->assertSee('Everything that went wrong')->assertSee('database said no')->assertSee('Ad page')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('<a href="https://x.test/ad/1"', false)
            ->assertDontSee('<a href="javascript:', false);
    }

    public function test_a_run_with_no_problems_says_so_and_a_missing_run_is_a_404(): void
    {
        $this->signIn();
        $run = SourceRun::create(['source_id' => $this->jobweb()->id, 'started_at' => now(), 'finished_at' => now(), 'status' => 'success']);

        $this->get("/runs/{$run->id}")->assertOk()->assertSee('No problems in this run');
        $this->get('/runs/999999')->assertNotFound();
    }

    public function test_run_pages_need_a_signed_in_admin(): void
    {
        $run = SourceRun::create(['source_id' => $this->jobweb()->id, 'started_at' => now(), 'status' => 'success']);

        $this->get("/runs/{$run->id}")->assertRedirect('/login');
    }

    public function test_the_source_page_run_tabs_use_the_same_columns(): void
    {
        $this->signIn();
        $source = $this->jobweb();
        SourceRun::create(['source_id' => $source->id, 'started_at' => now(), 'finished_at' => now(), 'status' => 'failed', 'error_message' => 'Site returned HTTP 503', 'items_failed' => 0]);
        SourceRun::create(['source_id' => $source->id, 'started_at' => now(), 'finished_at' => now(), 'status' => 'success']);

        $this->get("/sources/{$source->id}?tab=runs")->assertOk()->assertSee('Discovered')->assertSee('success')->assertSee('failed');
        $this->get("/sources/{$source->id}?tab=errors")->assertOk()->assertSee('Site returned HTTP 503')->assertDontSee('>success<', false);
    }

    public function test_new_count_columns_default_to_zero(): void
    {
        $run = SourceRun::create(['source_id' => $this->jobweb()->id, 'started_at' => now(), 'status' => 'running']);

        $this->assertSame([0, 0], [$run->fresh()->items_unchanged, $run->fresh()->items_failed]);
    }
}
