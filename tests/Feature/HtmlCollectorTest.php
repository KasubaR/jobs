<?php

namespace Tests\Feature;

use App\Collectors\Html\HtmlConfig;
use App\Collectors\HtmlListingCollector;
use App\Models\RawJobPost;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** Uses synthetic pages in tests/Fixtures/html. No real website is contacted. */
class HtmlCollectorTest extends TestCase
{
    use RefreshDatabase, \Tests\Concerns\SignsIn;

    private const HTML = [
        'item' => 'li.job-card',
        'link' => 'a.job-link',
        'next' => 'a.next',
        'content' => 'div.ad-body',
    ];

    private array $requested = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['job_intel.collector_contact' => 'owner@example.com']);
    }

    private function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/Fixtures/html/{$name}.html"));
    }

    /** @param array<string, string|int> $pages path => fixture name, or status code */
    private function site(array $pages = [], string $robots = "User-agent: *\nAllow: /"): void
    {
        $this->requested = [];
        $pages += ['/jobs' => 'listing-1', '/jobs?page=2' => 'listing-2', '/jobs/101-budget-analyst' => 'detail-101'];

        $this->app->forgetInstance(\Illuminate\Http\Client\Factory::class);
        Http::clearResolvedInstances();
        Http::fake(function (Request $request) use ($pages, $robots) {
            $path = parse_url($request->url(), PHP_URL_PATH).(parse_url($request->url(), PHP_URL_QUERY) ? '?'.parse_url($request->url(), PHP_URL_QUERY) : '');
            $this->requested[] = $path;

            if ($path === '/robots.txt') {
                return Http::response($robots, 200);
            }

            $page = $pages[$path] ?? null;

            return match (true) {
                is_int($page) => Http::response('error', $page),
                is_string($page) => Http::response($this->fixture($page), 200),
                default => Http::response('not found', 404),
            };
        });
    }

    private function source(array $html = [], array $attributes = []): Source
    {
        return Source::create(array_merge([
            'name' => 'Example Board',
            'website_url' => 'https://board.test/',
            'job_listing_url' => 'https://board.test/jobs',
            'source_type' => 'job_board',
            'collection_method' => 'html',
            'collector' => 'html_listing',
            'collection_frequency' => 'daily',
            'access_status' => 'approved',
            'is_active' => true,
            'collector_config' => ['html' => array_merge(self::HTML, $html)],
        ], $attributes));
    }

    private function pagesFetched(): array
    {
        return array_values(array_filter($this->requested, fn ($p) => $p !== '/robots.txt'));
    }

    public function test_reads_ads_from_a_listing_page_and_resolves_links(): void
    {
        $this->site();
        $run = app(HtmlListingCollector::class)->collect($this->source());

        $this->assertSame('success', $run->status);
        $urls = RawJobPost::orderBy('id')->pluck('source_url')->all();
        $this->assertSame(['https://board.test/jobs/101-budget-analyst', 'https://board.test/jobs/102-driver'], $urls);

        $first = RawJobPost::first();
        $this->assertSame('Budget Analyst at Example Charity', $first->raw_title);
        $this->assertStringContainsString('Degree in Finance', $first->raw_content);
        $this->assertStringNotContainsString('hr@example.org', $first->raw_content);
    }

    public function test_links_to_other_sites_scripts_and_missing_links_are_ignored(): void
    {
        $this->site();
        app(HtmlListingCollector::class)->collect($this->source());

        $this->assertSame(0, RawJobPost::where('source_url', 'like', '%elsewhere%')->count());
        $this->assertSame(0, RawJobPost::where('raw_title', 'Script link')->count());
        $this->assertSame(0, RawJobPost::where('raw_title', 'Card without a link')->count());
        $this->assertNotContains('/jobs/999', $this->pagesFetched());
    }

    public function test_follows_next_page_up_to_max_pages_without_looping(): void
    {
        $this->site();
        $run = app(HtmlListingCollector::class)->collect($this->source(['max_pages' => 3]));

        // page 2's "next" points back to page 1: it must stop, not loop
        $this->assertSame(['/jobs', '/jobs?page=2'], $this->pagesFetched());
        $this->assertSame(3, RawJobPost::count()); // 101, 102, 103 (101 repeated on page 2 is stored once)
        $this->assertSame('success', $run->status);
    }

    public function test_one_page_is_the_default(): void
    {
        $this->site();
        app(HtmlListingCollector::class)->collect($this->source());

        $this->assertSame(['/jobs'], $this->pagesFetched());
    }

    public function test_max_items_caps_the_run(): void
    {
        $this->site();
        app(HtmlListingCollector::class)->collect($this->source(['max_pages' => 2, 'max_items' => 2]));

        $this->assertSame(2, RawJobPost::count());
        $this->assertSame(['/jobs'], $this->pagesFetched());
    }

    public function test_detail_pages_are_opened_only_for_new_ads_and_replace_the_card_text(): void
    {
        $this->site(['/jobs/102-driver' => 'detail-101']);
        $source = $this->source(['fetch_detail' => true]);

        app(HtmlListingCollector::class)->collect($source);

        $this->assertContains('/jobs/101-budget-analyst', $this->pagesFetched());
        $this->assertStringContainsString('Full ad: prepare budgets', RawJobPost::where('source_url', 'like', '%101%')->value('raw_content'));

        // second run: both ads are known now, so no ad page is opened again
        $this->site(['/jobs/102-driver' => 'detail-101']);
        app(HtmlListingCollector::class)->collect($source->fresh());
        $this->assertSame(['/jobs'], $this->pagesFetched());
    }

    public function test_max_detail_requests_limits_ad_page_requests(): void
    {
        $this->site(['/jobs/102-driver' => 'detail-101']);
        app(HtmlListingCollector::class)->collect($this->source(['fetch_detail' => true, 'max_detail_requests' => 1]));

        $this->assertCount(2, $this->pagesFetched()); // listing + one ad page
    }

    public function test_a_failing_ad_page_makes_the_run_partial_and_keeps_the_card_text(): void
    {
        $this->site(['/jobs/101-budget-analyst' => 503]);
        $run = app(HtmlListingCollector::class)->collect($this->source(['fetch_detail' => true]));

        $this->assertSame('partial', $run->status);
        $this->assertStringContainsString('Ad page failed', $run->error_message);
        $this->assertStringContainsString('Degree in Finance', RawJobPost::where('source_url', 'like', '%101%')->value('raw_content'));
        $this->assertSame(2, RawJobPost::count());
    }

    public function test_a_failing_second_listing_page_keeps_page_one_and_is_partial(): void
    {
        $this->site(['/jobs?page=2' => 500]);
        $run = app(HtmlListingCollector::class)->collect($this->source(['max_pages' => 2]));

        $this->assertSame('partial', $run->status);
        $this->assertSame(2, RawJobPost::count());
    }

    public function test_no_matching_cards_fails_with_a_layout_changed_message(): void
    {
        $this->site();
        $run = app(HtmlListingCollector::class)->collect($this->source(['item' => 'div.renamed-card']));

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('layout may have changed', $run->error_message);
        $this->assertSame(0, RawJobPost::count());
    }

    public function test_a_first_page_error_fails_the_run(): void
    {
        $this->site(['/jobs' => 503]);
        $run = app(HtmlListingCollector::class)->collect($this->source());

        $this->assertSame('failed', $run->status);
        $this->assertSame(1, $run->http_errors);
    }

    public function test_robots_txt_disallowing_the_listing_stops_everything(): void
    {
        $this->site(robots: "User-agent: *\nDisallow: /jobs");
        $run = app(HtmlListingCollector::class)->collect($this->source());

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('robots.txt disallows', $run->error_message);
        $this->assertSame([], $this->pagesFetched());
    }

    public function test_robots_txt_disallowing_ad_pages_blocks_only_those(): void
    {
        $this->site(robots: "User-agent: *\nDisallow: /jobs/");
        $run = app(HtmlListingCollector::class)->collect($this->source(['fetch_detail' => true]));

        $this->assertSame('partial', $run->status);
        $this->assertSame(['/jobs'], $this->pagesFetched());
        $this->assertSame(2, RawJobPost::count()); // card text only
    }

    public function test_check_looks_at_the_first_listing_page_only(): void
    {
        $this->site();
        $source = $this->source(['max_pages' => 3, 'fetch_detail' => true]);

        $result = app(HtmlListingCollector::class)->check($source);

        $this->assertTrue($result->ok);
        $this->assertSame(2, $result->itemCount);
        $this->assertSame(['/jobs'], $this->pagesFetched());
        $this->assertSame(0, RawJobPost::count());
    }

    public function test_incomplete_settings_refuse_to_run(): void
    {
        $this->site();

        $this->expectException(RuntimeException::class);
        try {
            app(HtmlListingCollector::class)->collect($this->source(['item' => null]));
        } finally {
            $this->assertSame([], $this->requested);
        }
    }

    public function test_settings_validation(): void
    {
        $this->assertSame([], HtmlConfig::validate(self::HTML + ['max_pages' => 2]));

        foreach ([
            ['item' => ''],
            ['item' => 'li..bad[['],
            ['max_pages' => 6],
            ['max_pages' => 0],
            ['max_items' => 101],
            ['max_detail_requests' => 51],
            ['max_pages' => 2, 'next' => ''],
        ] as $bad) {
            $this->assertNotEmpty(HtmlConfig::validate($bad + self::HTML), json_encode($bad));
        }
    }

    // ---- the form ----

    private function formData(array $html): array
    {
        return [
            'name' => 'Example Board', 'source_type' => 'job_board', 'website_url' => 'https://board.test/',
            'job_listing_url' => 'https://board.test/jobs', 'collection_method' => 'html', 'collector' => 'html_listing',
            'collection_frequency' => 'daily', 'min_interval_seconds' => 1, 'retain_raw' => '1', 'html' => $html,
        ];
    }

    public function test_html_settings_are_saved_from_the_form(): void
    {
        $this->signIn();
        $source = $this->source([], ['is_active' => false, 'access_status' => 'pending', 'collector_config' => null]);

        $this->put("/sources/{$source->id}", $this->formData(self::HTML + ['max_pages' => 2, 'fetch_detail' => '1']))
            ->assertRedirect("/sources/{$source->id}");

        $saved = $source->fresh()->collector_config['html'];
        $this->assertSame('li.job-card', $saved['item']);
        $this->assertSame(2, $saved['max_pages']);
        $this->assertTrue($saved['fetch_detail']);
    }

    public function test_the_saved_settings_appear_again_in_the_form(): void
    {
        $this->signIn();
        $source = $this->source();

        $this->get("/sources/{$source->id}/edit")->assertOk()->assertSee('li.job-card')->assertSee('a.next');
    }

    public function test_a_bad_selector_or_limit_in_the_form_blocks_saving(): void
    {
        $this->signIn();
        $source = $this->source([], ['is_active' => false, 'access_status' => 'pending', 'collector_config' => null]);

        $this->put("/sources/{$source->id}", $this->formData(['item' => 'li..bad[[']))->assertSessionHasErrors('html');
        $this->put("/sources/{$source->id}", $this->formData(self::HTML + ['max_pages' => 6]))->assertSessionHasErrors('html');
        $this->put("/sources/{$source->id}", $this->formData([]))->assertSessionHasErrors('html');

        $this->assertNull($source->fresh()->collector_config);
    }

    public function test_html_settings_are_ignored_for_other_collectors(): void
    {
        $this->signIn();
        $source = $this->source([], ['is_active' => false, 'access_status' => 'pending', 'collector_config' => null]);

        $this->put("/sources/{$source->id}", ['collector' => '', 'collection_method' => 'manual'] + $this->formData(['item' => 'li..bad[[']))
            ->assertRedirect();

        $this->assertNull($source->fresh()->collector_config);
    }
}
