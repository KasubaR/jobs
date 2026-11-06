<?php

namespace Tests\Feature;

use App\Collectors\JobWebFeedCollector;
use App\Collectors\RobotsGuard;
use App\Models\RawJobPost;
use App\Models\Source;
use App\Models\SourceAccessLog;
use App\Models\User;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RobotsGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['job_intel.collector_contact' => 'owner@example.com']);
    }

    private function robots(string $body, int $status = 200): void
    {
        Http::fake(['*/robots.txt' => Http::response($body, $status), '*' => Http::response('feed', 200)]);
    }

    public function test_go_zambia_jobs_style_rules_block_rss_but_allow_jobs_and_expose_crawl_delay(): void
    {
        $this->robots("User-agent: *\nDisallow: /rss/\nCrawl-delay: 1\nSitemap: https://x.test/sitemap.xml");
        $guard = app(RobotsGuard::class);

        $this->assertFalse($guard->allows('https://x.test/rss/jobs'));
        $this->assertTrue($guard->allows('https://x.test/jobs'));
        $this->assertSame(1.0, $guard->crawlDelay('https://x.test/jobs'));
    }

    public function test_robots_is_fetched_once_and_cached(): void
    {
        $this->robots("User-agent: *\nDisallow: /private");
        $guard = app(RobotsGuard::class);

        $guard->allows('https://x.test/a');
        $guard->allows('https://x.test/private');

        Http::assertSentCount(1);
    }

    public function test_our_own_group_overrides_the_wildcard_group(): void
    {
        $this->robots("User-agent: *\nDisallow: /\n\nUser-agent: ZamtrendsBot\nAllow: /\nDisallow: /secret");
        $guard = app(RobotsGuard::class);

        $this->assertTrue($guard->allows('https://x.test/jobs'));
        $this->assertFalse($guard->allows('https://x.test/secret/page'));
    }

    public function test_disallow_all_for_everyone_blocks_us(): void
    {
        $this->robots("User-agent: *\nDisallow: /"); // like unjobs.org
        $this->assertFalse(app(RobotsGuard::class)->allows('https://x.test/anything'));
    }

    public function test_longest_match_wins_and_allow_wins_a_tie(): void
    {
        $this->robots("User-agent: *\nDisallow: /jobs\nAllow: /jobs/public\nDisallow: /same\nAllow: /same");
        $guard = app(RobotsGuard::class);

        $this->assertFalse($guard->allows('https://x.test/jobs/secret'));
        $this->assertTrue($guard->allows('https://x.test/jobs/public/1'));
        $this->assertTrue($guard->allows('https://x.test/same'));
    }

    public function test_wildcards_and_end_anchors(): void
    {
        $this->robots("User-agent: *\nDisallow: /*.pdf\$\nDisallow: /search?*sort=");
        $guard = app(RobotsGuard::class);

        $this->assertFalse($guard->allows('https://x.test/files/a.pdf'));
        $this->assertTrue($guard->allows('https://x.test/files/a.pdf.html'));
        $this->assertFalse($guard->allows('https://x.test/search?q=1&sort=new'));
    }

    public function test_empty_disallow_allows_everything(): void
    {
        $this->robots("User-agent: *\nDisallow:");
        $this->assertTrue(app(RobotsGuard::class)->allows('https://x.test/anything'));
    }

    public function test_missing_robots_txt_allows_everything(): void
    {
        $this->robots('', 404);
        $this->assertTrue(app(RobotsGuard::class)->allows('https://x.test/anything'));
    }

    public function test_unreachable_robots_txt_blocks_everything(): void
    {
        $this->robots('', 503);
        $this->assertFalse(app(RobotsGuard::class)->allows('https://x.test/anything'));
    }

    public function test_collector_refuses_a_disallowed_feed_url_without_requesting_it(): void
    {
        $this->robots("User-agent: *\nDisallow: /feed");
        $this->seed(SourceSeeder::class);
        $source = Source::where('name', 'JobWeb Zambia')->first();
        $source->update(['access_status' => 'approved', 'is_active' => true]);

        $run = app(JobWebFeedCollector::class)->collect($source);

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('robots.txt disallows', $run->error_message);
        $this->assertSame(0, RawJobPost::count());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/feed'));
    }

    public function test_run_records_how_it_was_triggered(): void
    {
        $this->robots("User-agent: *\nAllow: /");
        $this->seed(SourceSeeder::class);
        $source = Source::where('name', 'JobWeb Zambia')->first();
        $source->update(['access_status' => 'approved', 'is_active' => true]);

        $this->assertSame('scheduled', app(JobWebFeedCollector::class)->collect($source)->trigger);
        $this->assertSame('manual', app(JobWebFeedCollector::class)->collect($source, 'manual')->trigger);
    }

    public function test_access_and_active_changes_are_logged_with_who_and_why(): void
    {
        $this->seed(SourceSeeder::class);
        $user = User::factory()->create();
        $this->actingAs($user);

        $source = Source::where('name', 'JobWeb Zambia')->first();
        $source->decisionNote = 'Owner approved a private test run.';
        $source->update(['access_status' => 'approved', 'is_active' => true]);

        $logs = SourceAccessLog::where('source_id', $source->id)->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame(['access_status', 'pending', 'approved'], [$logs[0]->field, $logs[0]->from_value, $logs[0]->to_value]);
        $this->assertSame(['is_active', '0', '1'], [$logs[1]->field, $logs[1]->from_value, $logs[1]->to_value]);
        $this->assertSame($user->id, $logs[0]->user_id);
        $this->assertSame('Owner approved a private test run.', $logs[0]->note);
    }

    public function test_unrelated_edits_are_not_logged(): void
    {
        $this->seed(SourceSeeder::class);
        Source::where('name', 'JobWeb Zambia')->first()->update(['notes' => 'edited']);

        $this->assertSame(0, SourceAccessLog::count());
    }
}
