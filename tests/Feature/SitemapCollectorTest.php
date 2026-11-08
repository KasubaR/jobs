<?php

namespace Tests\Feature;

use App\Collectors\Html\HtmlConfig;
use App\Collectors\HtmlListingCollector;
use App\Models\RawJobPost;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/** Synthetic sitemaps (tests/Fixtures/sitemap) and ad pages built here. No real website is contacted. */
class SitemapCollectorTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    private const TITLES = [
        '/jobs/301-newest-ad' => 'Newest Ad',
        '/jobs/300-middle-ad' => 'Middle Ad',
        '/jobs/302-undated-ad' => 'Undated Ad',
        '/jobs/100-oldest-ad' => 'Oldest Ad',
        '/jobs/full-time' => 'Full time jobs',
        '/companies/acme-1' => 'Acme',
    ];

    private const HTML = [
        'sitemap' => 'https://board.test/sitemap-new.xml',
        'sitemap_match' => '/jobs/#*',
        'content' => 'div.ad-body',
        'fetch_detail' => true,
        'max_detail_requests' => 20,
    ];

    /** @var list<string> */
    private array $requested = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['job_intel.collector_contact' => 'owner@example.com']);
    }

    private function xml(string $name): string
    {
        return file_get_contents(base_path("tests/Fixtures/sitemap/{$name}.xml"));
    }

    private function adPage(string $path, bool $structured = false): string
    {
        $title = self::TITLES[$path];
        $ld = $structured ? '<script type="application/ld+json">{"@context":"https://schema.org","@type":"JobPosting","title":"'.$title.' (job data)","description":"<p>Job data text.</p>","datePosted":"2026-11-01","hiringOrganization":{"name":"Example Charity"}}</script>' : '';

        return "<!doctype html><html><head><meta charset=\"utf-8\"><title>{$title} | Board</title>{$ld}</head><body><h1>{$title}</h1><div class=\"ad-body\">Full text of {$title}. Write to hr@example.org.</div></body></html>";
    }

    /** @param array<string, string|int> $overrides path => response body, or a status code */
    private function site(array $overrides = [], bool $structured = false, string $robots = "User-agent: *\nAllow: /"): void
    {
        $this->requested = [];
        $this->app->forgetInstance(\Illuminate\Http\Client\Factory::class);
        Http::clearResolvedInstances();

        Http::fake(function (Request $request) use ($overrides, $structured, $robots) {
            $host = parse_url($request->url(), PHP_URL_HOST);
            $path = parse_url($request->url(), PHP_URL_PATH);
            $this->requested[] = $host === 'board.test' ? $path : $host.$path;

            if ($path === '/robots.txt') {
                return Http::response($robots, 200);
            }

            if (array_key_exists($path, $overrides)) {
                return is_int($overrides[$path]) ? Http::response('error', $overrides[$path]) : Http::response($overrides[$path], 200);
            }

            return match (true) {
                $path === '/sitemap.xml' => Http::response($this->xml('index'), 200),
                $path === '/sitemap-new.xml' => Http::response($this->xml('sitemap-new'), 200),
                $path === '/sitemap-old.xml' => Http::response($this->xml('sitemap-old'), 200),
                isset(self::TITLES[$path]) => Http::response($this->adPage($path, $structured), 200),
                default => Http::response('not found', 404),
            };
        });
    }

    private function source(array $html = [], array $attributes = []): Source
    {
        return Source::create(array_merge([
            'name' => 'Sitemap Board',
            'website_url' => 'https://board.test/',
            'job_listing_url' => 'https://board.test/jobs',
            'source_type' => 'job_board',
            'collection_method' => 'html',
            'collector' => 'html_listing',
            'collection_frequency' => 'every_6_hours',
            'access_status' => 'approved',
            'is_active' => true,
            'collector_config' => ['html' => array_merge(self::HTML, $html)],
        ], $attributes));
    }

    /** @return list<string> everything requested except robots.txt */
    private function fetched(): array
    {
        return array_values(array_filter($this->requested, fn ($p) => $p !== '/robots.txt' && ! str_ends_with($p, '/robots.txt')));
    }

    // ---- finding the ads ----

    public function test_reads_the_ads_the_sitemap_lists_newest_first_and_skips_pages_that_are_not_ads(): void
    {
        $this->site();
        $run = app(HtmlListingCollector::class)->collect($this->source());

        $this->assertSame('success', $run->status);
        $this->assertSame(3, $run->items_new);

        // sitemap first, then ad pages newest to oldest, the undated one last; no category, company or off-site page
        $this->assertSame(['/sitemap-new.xml', '/jobs/301-newest-ad', '/jobs/300-middle-ad', '/jobs/302-undated-ad'], $this->fetched());

        $newest = RawJobPost::where('source_url', 'https://board.test/jobs/301-newest-ad')->first();
        $this->assertSame('Newest Ad', $newest->raw_title); // from the ad page's own heading
        $this->assertSame('Full text of Newest Ad. Write to [email removed].', trim(strip_tags($newest->raw_content)));
        $this->assertSame(3, RawJobPost::count()); // the duplicate with #apply is one ad
    }

    public function test_the_pattern_decides_what_is_an_ad(): void
    {
        $this->site();
        app(HtmlListingCollector::class)->collect($this->source(['sitemap_match' => '']));

        // without a pattern every same-site address in the sitemap is read: the category and company pages too
        $this->assertContains('/jobs/full-time', $this->fetched());
        $this->assertContains('/companies/acme-1', $this->fetched());
        $this->assertNotContains('elsewhere.test/jobs/999-offsite', $this->fetched()); // another site, never
    }

    public function test_a_sitemap_index_is_followed_one_level_newest_file_first(): void
    {
        $this->site();
        app(HtmlListingCollector::class)->collect($this->source(['sitemap' => 'https://board.test/sitemap.xml', 'max_pages' => 1]));

        $fetched = $this->fetched();
        $this->assertSame(['/sitemap.xml', '/sitemap-new.xml'], array_slice($fetched, 0, 2));
        $this->assertNotContains('/sitemap-old.xml', $fetched);
        $this->assertNotContains('/jobs/100-oldest-ad', $fetched);
        $this->assertNotContains('elsewhere.test/sitemap.xml', $fetched); // another site's sitemap
        $this->assertNotContains('/sitemap-big.xml.gz', $fetched);        // compressed files are not read
    }

    public function test_more_sitemap_files_are_read_up_to_the_page_limit(): void
    {
        $this->site();
        app(HtmlListingCollector::class)->collect($this->source(['sitemap' => 'https://board.test/sitemap.xml', 'max_pages' => 2]));

        $this->assertContains('/sitemap-old.xml', $this->fetched());
        $this->assertNotContains('elsewhere.test/sitemap.xml', $this->fetched());
        $this->assertNotContains('/sitemap-big.xml.gz', $this->fetched());
        $this->assertSame(4, RawJobPost::count()); // 301, 300, 302 and 100
    }

    public function test_the_ad_limit_keeps_only_the_newest(): void
    {
        $this->site();
        $run = app(HtmlListingCollector::class)->collect($this->source(['max_items' => 2]));

        $this->assertSame(2, $run->items_found);
        $this->assertSame(['/sitemap-new.xml', '/jobs/301-newest-ad', '/jobs/300-middle-ad'], $this->fetched());
    }

    // ---- new ads only ----

    public function test_only_new_ads_are_opened_and_known_ads_are_left_alone(): void
    {
        $this->site();
        $source = $this->source();
        app(HtmlListingCollector::class)->collect($source);

        $this->site();
        $second = app(HtmlListingCollector::class)->collect($source->fresh());

        $this->assertSame(['/sitemap-new.xml'], $this->fetched()); // no ad page again
        $this->assertSame(0, $second->items_new);
        $this->assertSame(3, $second->items_unchanged);
        $this->assertSame(3, RawJobPost::count());
        $this->assertSame('Newest Ad', RawJobPost::where('source_url', 'https://board.test/jobs/301-newest-ad')->value('raw_title')); // not replaced by a placeholder
    }

    public function test_when_the_page_budget_runs_out_the_rest_wait_for_the_next_run_and_nothing_is_stored_unread(): void
    {
        $this->site();
        $source = $this->source(['max_detail_requests' => 1]);

        $first = app(HtmlListingCollector::class)->collect($source);
        $this->assertSame(1, $first->items_new);
        $this->assertSame(['Newest Ad'], RawJobPost::pluck('raw_title')->all()); // no placeholder titles

        $this->site();
        $second = app(HtmlListingCollector::class)->collect($source->fresh());
        $this->assertSame(1, $second->items_new);
        $this->assertSame(1, $second->items_unchanged);
        $this->assertSame(['/sitemap-new.xml', '/jobs/300-middle-ad'], $this->fetched());
        $this->assertEqualsCanonicalizing(['Newest Ad', 'Middle Ad'], RawJobPost::pluck('raw_title')->all());
    }

    // ---- what is read from each ad page ----

    public function test_the_title_selector_applies_to_the_ad_page(): void
    {
        $this->site();
        app(HtmlListingCollector::class)->collect($this->source(['title' => 'div.ad-body']));

        $this->assertStringStartsWith('Full text of Newest Ad', RawJobPost::where('source_url', 'https://board.test/jobs/301-newest-ad')->value('raw_title'));
    }

    public function test_the_ads_own_job_data_gives_the_title_and_the_facts(): void
    {
        $this->site(structured: true);
        app(HtmlListingCollector::class)->collect($this->source(['structured_data' => true, 'content' => '']));

        $post = RawJobPost::where('source_url', 'https://board.test/jobs/301-newest-ad')->first();
        $this->assertSame('Newest Ad (job data)', $post->raw_title);
        $this->assertStringContainsString('Job data text.', $post->raw_content);
        $this->assertSame('Example Charity', $post->structured_data['employer']);
        $this->assertSame('2026-11-01', $post->structured_data['date_posted']);
    }

    public function test_a_page_that_fails_is_a_partial_run_and_the_others_are_stored(): void
    {
        $this->site(['/jobs/300-middle-ad' => 500]);
        $run = app(HtmlListingCollector::class)->collect($this->source());

        $this->assertSame('partial', $run->status);
        $this->assertSame(2, $run->items_new);
        $this->assertSame(1, $run->items_failed);
        $this->assertNull(RawJobPost::where('source_url', 'https://board.test/jobs/300-middle-ad')->first());
    }

    public function test_a_page_with_no_title_is_not_stored(): void
    {
        $this->site(['/jobs/302-undated-ad' => '<html><body><p>nothing useful</p></body></html>']);
        $run = app(HtmlListingCollector::class)->collect($this->source());

        $this->assertSame('partial', $run->status);
        $this->assertNull(RawJobPost::where('source_url', 'https://board.test/jobs/302-undated-ad')->first());
        $this->assertTrue($run->errors()->where('message', 'like', 'No title could be read%')->exists());
    }

    // ---- when the sitemap is not what we expect ----

    public function test_an_unreadable_sitemap_fails_the_run(): void
    {
        $this->site(['/sitemap-new.xml' => '<html>maintenance</html>']);
        $run = app(HtmlListingCollector::class)->collect($this->source());

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('not readable', $run->error_message);
        $this->assertSame(0, RawJobPost::count());
    }

    public function test_a_missing_sitemap_fails_the_run(): void
    {
        $this->site(['/sitemap-new.xml' => 404]);
        $run = app(HtmlListingCollector::class)->collect($this->source());

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('HTTP 404', $run->error_message);
    }

    public function test_a_pattern_that_matches_nothing_fails_instead_of_looking_like_a_quiet_success(): void
    {
        $this->site();
        $run = app(HtmlListingCollector::class)->collect($this->source(['sitemap_match' => '/vacancies/*']));

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('No addresses in the sitemap matched the ad address pattern (/vacancies/*)', $run->error_message);
    }

    public function test_a_sitemap_on_another_site_is_refused_without_any_request(): void
    {
        $this->site();
        $run = app(HtmlListingCollector::class)->collect($this->source(['sitemap' => 'https://elsewhere.test/sitemap.xml']));

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('must be on the source\'s own site', $run->error_message);
        $this->assertSame([], $this->fetched());
    }

    public function test_robots_txt_that_forbids_the_sitemap_is_obeyed(): void
    {
        $this->site([], false, "User-agent: *\nDisallow: /sitemap");
        $run = app(HtmlListingCollector::class)->collect($this->source());

        $this->assertSame('failed', $run->status);
        $this->assertSame([], $this->fetched());
    }

    public function test_robots_txt_that_forbids_the_ads_stops_each_of_them(): void
    {
        $this->site([], false, "User-agent: *\nDisallow: /jobs/");
        $run = app(HtmlListingCollector::class)->collect($this->source());

        $this->assertSame(0, RawJobPost::count());
        $this->assertSame(['/sitemap-new.xml'], $this->fetched()); // no ad page was requested
        $this->assertSame('partial', $run->status);
    }

    public function test_test_access_reads_only_the_sitemap_and_stores_nothing(): void
    {
        $this->site();
        $check = app(HtmlListingCollector::class)->check($this->source());

        $this->assertTrue($check->ok);
        $this->assertSame(3, $check->itemCount);
        $this->assertSame(['/sitemap-new.xml'], $this->fetched());
        $this->assertSame(0, RawJobPost::count());
    }

    public function test_test_access_on_an_index_reads_the_index_and_one_file_only(): void
    {
        $this->site();
        app(HtmlListingCollector::class)->check($this->source(['sitemap' => 'https://board.test/sitemap.xml', 'max_pages' => 5]));

        $this->assertSame(['/sitemap.xml', '/sitemap-new.xml'], $this->fetched());
    }

    public function test_only_an_approved_active_source_is_collected(): void
    {
        $this->site();
        $this->expectException(\RuntimeException::class);

        app(HtmlListingCollector::class)->collect($this->source([], ['is_active' => false]));
    }

    // ---- settings ----

    public function test_sitemap_settings_are_validated(): void
    {
        $ok = ['sitemap' => 'https://board.test/sitemap.xml', 'sitemap_match' => '/jobs/#*', 'content' => 'div.ad', 'fetch_detail' => true];
        $this->assertSame([], HtmlConfig::validate($ok)); // no "item" selector needed

        $bad = [
            'not an address' => ['sitemap' => 'sitemap.xml'] + $ok,
            'ad pages not opened' => ['fetch_detail' => false] + $ok,
            'no source of ad text' => ['content' => ''] + $ok,
            'pattern without a slash' => ['sitemap_match' => 'jobs/*'] + $ok,
            'pattern with odd characters' => ['sitemap_match' => '/jobs/(.*)'] + $ok,
            'pattern without a sitemap' => ['sitemap' => ''] + $ok + ['item' => 'li'],
            'no item and no sitemap' => ['sitemap' => '', 'sitemap_match' => ''] + $ok,
        ];

        foreach ($bad as $label => $settings) {
            $this->assertNotEmpty(HtmlConfig::validate($settings), "Expected a problem for: {$label}");
        }

        $this->assertSame([], HtmlConfig::validate(['sitemap' => 'https://board.test/s.xml', 'structured_data' => true, 'fetch_detail' => true]));
    }

    public function test_the_form_saves_sitemap_settings_without_an_item_selector(): void
    {
        $this->signIn();

        $this->post('/sources', [
            'name' => 'Sitemap Board', 'source_type' => 'job_board', 'website_url' => 'https://board.test/', 'job_listing_url' => 'https://board.test/jobs',
            'collection_method' => 'html', 'collector' => 'html_listing', 'collection_frequency' => 'every_6_hours', 'min_interval_seconds' => 2, 'retain_raw' => '1',
            'html' => ['sitemap' => 'https://board.test/sitemap.xml', 'sitemap_match' => '/jobs/#*', 'content' => 'div.ad-body', 'fetch_detail' => '1', 'structured_data' => '0', 'max_pages' => '2', 'max_items' => '60', 'max_detail_requests' => '20'],
        ])->assertSessionHasNoErrors();

        $html = Source::where('name', 'Sitemap Board')->first()->collector_config['html'];
        $this->assertSame('https://board.test/sitemap.xml', $html['sitemap']);
        $this->assertSame('/jobs/#*', $html['sitemap_match']);

        $this->get('/sources/'.Source::first()->id.'/edit')->assertOk()->assertSee('Sitemap address')->assertSee('/jobs/#*');
    }
}
