<?php

namespace Tests\Feature;

use App\Collectors\JobWebFeedCollector;
use App\Models\RawJobPost;
use App\Models\Source;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class JobWebCollectorTest extends TestCase
{
    use RefreshDatabase;

    private function approvedSource(): Source
    {
        $this->seed(SourceSeeder::class);
        $source = Source::where('name', 'JobWeb Zambia')->first();
        $source->update(['access_status' => 'approved', 'is_active' => true]);

        return $source->fresh();
    }

    private function fakeFeed(?string $xml = null): void
    {
        Http::fake([
            '*/robots.txt' => Http::response("User-agent: *
Allow: /", 200),
            '*' => Http::response($xml ?? file_get_contents(base_path('tests/Fixtures/jobweb-feed.xml')), 200),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['job_intel.collector_contact' => 'owner@example.com']);
    }

    public function test_collects_ads_and_records_the_run(): void
    {
        $this->fakeFeed();
        $source = $this->approvedSource();

        $run = app(JobWebFeedCollector::class)->collect($source);

        $this->assertSame('success', $run->status);
        $this->assertSame(2, $run->items_found);
        $this->assertSame(2, $run->items_new);
        $this->assertSame(2, RawJobPost::count());
        $this->assertNotNull($source->fresh()->last_collection_at);

        $post = RawJobPost::where('external_id', '1001')->first();
        $this->assertSame('Budget Analyst at Example Charity', $post->raw_title);
        $this->assertStringContainsString('Degree in Finance', $post->raw_content);
    }

    public function test_sends_an_honest_user_agent_with_contact_and_makes_only_robots_and_feed_requests(): void
    {
        $this->fakeFeed();
        app(JobWebFeedCollector::class)->collect($this->approvedSource());

        Http::assertSentCount(2); // robots.txt + the feed
        Http::assertSent(fn ($r) => str_contains($r->header('User-Agent')[0], 'JobIntelBot')
            && str_contains($r->header('User-Agent')[0], 'owner@example.com'));
    }

    public function test_removes_email_addresses_from_stored_content(): void
    {
        $this->fakeFeed();
        app(JobWebFeedCollector::class)->collect($this->approvedSource());

        $content = RawJobPost::where('external_id', '1001')->value('raw_content');
        $this->assertStringNotContainsString('hr@example.org', $content);
        $this->assertStringContainsString('[email removed]', $content);
    }

    public function test_second_run_creates_no_duplicates(): void
    {
        $this->fakeFeed();
        $source = $this->approvedSource();

        app(JobWebFeedCollector::class)->collect($source);
        $run = app(JobWebFeedCollector::class)->collect($source);

        $this->assertSame(0, $run->items_new);
        $this->assertSame(2, RawJobPost::count());
    }

    public function test_an_edited_ad_is_stored_as_a_new_version(): void
    {
        $source = $this->approvedSource();
        $xml = file_get_contents(base_path('tests/Fixtures/jobweb-feed.xml'));

        $feeds = [$xml, str_replace('Drive haul trucks', 'Operate haul trucks', $xml)];
        Http::fake(function ($request) use (&$feeds) {
            if (str_ends_with($request->url(), '/robots.txt')) {
                return Http::response("User-agent: *
Allow: /", 200);
            }

            return Http::response(array_shift($feeds) ?? '', 200);
        });

        app(JobWebFeedCollector::class)->collect($source);
        $run = app(JobWebFeedCollector::class)->collect($source);

        $this->assertSame(1, $run->items_updated);
        $this->assertSame(3, RawJobPost::count());
    }

    public function test_refuses_sources_that_are_not_approved_and_active(): void
    {
        $this->fakeFeed();
        $this->seed(SourceSeeder::class);
        $source = Source::where('name', 'JobWeb Zambia')->first(); // pending

        $this->expectException(RuntimeException::class);
        try {
            app(JobWebFeedCollector::class)->collect($source);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_refuses_to_run_without_a_contact_address(): void
    {
        config(['job_intel.collector_contact' => null]);
        $this->fakeFeed();
        $source = $this->approvedSource();

        $this->expectException(RuntimeException::class);
        try {
            app(JobWebFeedCollector::class)->collect($source);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_source_that_refuses_retention_stores_no_raw_content(): void
    {
        $this->fakeFeed();
        $source = $this->approvedSource();
        $source->update(['retain_raw' => false]);

        app(JobWebFeedCollector::class)->collect($source);

        $this->assertSame(0, RawJobPost::whereNotNull('raw_content')->count());
        $this->assertSame(2, RawJobPost::count());
    }

    public function test_http_error_is_recorded_as_a_failed_run_and_stores_nothing(): void
    {
        Http::fake(['*/robots.txt' => Http::response('', 404), '*' => Http::response('blocked', 403)]);
        $run = app(JobWebFeedCollector::class)->collect($this->approvedSource());

        $this->assertSame('failed', $run->status);
        $this->assertSame(1, $run->http_errors);
        $this->assertSame(0, RawJobPost::count());
    }

    public function test_garbage_response_is_a_failed_run(): void
    {
        $this->fakeFeed('<html>not a feed</html>');
        $run = app(JobWebFeedCollector::class)->collect($this->approvedSource());

        $this->assertSame('failed', $run->status);
        $this->assertSame(0, RawJobPost::count());
    }

    public function test_purge_clears_old_raw_text_but_keeps_the_record(): void
    {
        $this->fakeFeed();
        app(JobWebFeedCollector::class)->collect($this->approvedSource());
        RawJobPost::where('external_id', '1001')->update(['first_seen_at' => now()->subDays(91)]);

        $this->artisan('raw:purge')->assertSuccessful();

        $old = RawJobPost::where('external_id', '1001')->first();
        $this->assertNull($old->raw_content);
        $this->assertNotNull($old->raw_purged_at);
        $this->assertNotNull(RawJobPost::where('external_id', '1002')->value('raw_content'));
    }

    public function test_collect_command_skips_everything_when_nothing_is_approved(): void
    {
        $this->fakeFeed();
        $this->seed(SourceSeeder::class);

        $this->artisan('collect:run')->expectsOutputToContain('No approved, active sources')->assertSuccessful();
        Http::assertNothingSent();
    }
}
