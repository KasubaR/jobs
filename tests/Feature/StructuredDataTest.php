<?php

namespace Tests\Feature;

use App\Collectors\Html\HtmlConfig;
use App\Collectors\Html\JobPostingParser;
use App\Collectors\HtmlListingCollector;
use App\Collectors\JobWebFeedCollector;
use App\Events\RawJobPostStored;
use App\Models\RawJobPost;
use App\Models\Source;
use App\Models\SourceRun;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/** Synthetic pages only (tests/Fixtures/html). No real website is contacted. */
class StructuredDataTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    private const HTML = ['item' => 'li.job-card', 'link' => 'a.job-link', 'fetch_detail' => true, 'structured_data' => true];

    protected function setUp(): void
    {
        parent::setUp();
        config(['job_intel.collector_contact' => 'owner@example.com']);
    }

    private function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/Fixtures/html/{$name}.html"));
    }

    private function ld(string $json): string
    {
        return '<html><head><script type="application/ld+json">'.$json.'</script></head><body></body></html>';
    }

    private function parse(string $json): ?array
    {
        return app(JobPostingParser::class)->parse($this->ld($json));
    }

    // ---- the parser ----

    public function test_reads_the_facts_of_a_job_posting_and_ignores_other_blocks(): void
    {
        $result = app(JobPostingParser::class)->parse($this->fixture('detail-structured'));

        $this->assertSame([
            'title' => 'Budget Analyst',
            'employer' => 'Example Charity',
            'locations' => [['locality' => 'Lusaka', 'region' => 'Lusaka Province', 'country' => 'ZM']],
            'date_posted' => '2026-11-01',
            'valid_through' => '2026-11-30',
            'employment_type' => ['FULL_TIME', 'CONTRACT'],
            'salary' => ['currency' => 'ZMW', 'min' => 12000, 'max' => 15000, 'unit' => 'MONTH'],
            'identifier' => 'EC-2026-14',
            'education' => 'bachelor degree',
            'experience' => '36 months',
        ], $result['facts']);
    }

    public function test_facts_never_contain_contact_details_or_a_street_address(): void
    {
        $facts = json_encode(app(JobPostingParser::class)->parse($this->fixture('detail-structured'))['facts']);

        foreach (['hr@example.org', 'jobs@example.org', 'Phiri', '+260', '260211', 'Private Road', 'sameAs', 'example.org'] as $secret) {
            $this->assertStringNotContainsString($secret, $facts, $secret);
        }
    }

    public function test_the_ad_text_is_kept_apart_from_the_facts(): void
    {
        $result = app(JobPostingParser::class)->parse($this->fixture('detail-structured'));

        $this->assertStringContainsString('Prepare budgets', $result['description']);
        $this->assertArrayNotHasKey('description', $result['facts']);
    }

    public function test_finds_a_posting_in_a_graph_a_list_and_with_several_types(): void
    {
        $graph = $this->parse('{"@context":"https://schema.org","@graph":[{"@type":"Organization","name":"Acme"},{"@type":["JobPosting","Thing"],"title":"Driver"}]}');
        $list = $this->parse('[{"@type":"WebPage"},{"@type":"JobPosting","title":"Nurse"}]');
        $nested = $this->parse('{"@type":"WebPage","mainEntity":{"@type":"JobPosting","title":"Hidden"}}');

        $this->assertSame('Driver', $graph['facts']['title']);
        $this->assertSame('Nurse', $list['facts']['title']);
        $this->assertNull($nested, 'only the graph and list shapes are searched, not arbitrary nesting');
    }

    public function test_nothing_to_read_is_just_null(): void
    {
        $parser = app(JobPostingParser::class);

        $this->assertNull($parser->parse('<html><body>no data</body></html>'));
        $this->assertNull($this->parse('{"@type":"Organization","name":"Acme"}'));
        $this->assertNull($this->parse('{ not json'));
        $this->assertNull($this->parse('"just a string"'));
        $this->assertNull($parser->parse(''));
    }

    public function test_a_broken_block_does_not_hide_a_good_one(): void
    {
        $html = '<script type="application/ld+json">{bad</script><script type="application/ld+json">{"@type":"JobPosting","title":"Cook"}</script>';

        $this->assertSame('Cook', app(JobPostingParser::class)->parse($html)['facts']['title']);
    }

    public function test_oversized_and_deeply_nested_data_is_skipped_safely(): void
    {
        $parser = app(JobPostingParser::class);

        $huge = '{"@type":"JobPosting","title":"Big","note":"'.str_repeat('a', 250_000).'"}';
        $this->assertNull($parser->parse($this->ld($huge)));

        $deep = str_repeat('[', 60).'{"@type":"JobPosting","title":"Deep"}'.str_repeat(']', 60);
        $this->assertNull($this->parse($deep));
    }

    public function test_salary_in_its_different_shapes(): void
    {
        $single = $this->parse('{"@type":"JobPosting","title":"A","baseSalary":{"currency":"USD","value":{"value":500,"unitText":"MONTH"}}}');
        $plain = $this->parse('{"@type":"JobPosting","title":"A","baseSalary":{"currency":"ZMW","value":9000}}');
        $none = $this->parse('{"@type":"JobPosting","title":"A","baseSalary":{"currency":"ZMW"}}');
        $junk = $this->parse('{"@type":"JobPosting","title":"A","baseSalary":"competitive"}');

        $this->assertSame(['currency' => 'USD', 'min' => 500, 'max' => 500, 'unit' => 'MONTH'], $single['facts']['salary']);
        $this->assertSame(['currency' => 'ZMW', 'min' => 9000, 'max' => 9000], $plain['facts']['salary']);
        $this->assertArrayNotHasKey('salary', $none['facts']);
        $this->assertArrayNotHasKey('salary', $junk['facts']);
    }

    public function test_dates_become_plain_dates_and_unreadable_ones_are_left_out(): void
    {
        $result = $this->parse('{"@type":"JobPosting","title":"A","datePosted":"2026-11-01T08:30:00Z","validThrough":"soon-ish"}');

        $this->assertSame('2026-11-01', $result['facts']['date_posted']);
        $this->assertArrayNotHasKey('valid_through', $result['facts']);
    }

    public function test_html_and_odd_types_are_cleaned_up(): void
    {
        $result = $this->parse('{"@type":"JobPosting","title":"<b>Analyst</b> &amp; Co  ","hiringOrganization":"Plain Name Ltd","employmentType":"part_time",
            "jobLocation":{"address":"Ndola, Zambia"},"jobLocationType":"TELECOMMUTE","skills":["Excel","SQL"],"industry":["Mining"]}');

        $facts = $result['facts'];
        $this->assertSame('Analyst & Co', $facts['title']);
        $this->assertSame('Plain Name Ltd', $facts['employer']);
        $this->assertSame(['PART_TIME'], $facts['employment_type']);
        $this->assertSame([['text' => 'Ndola, Zambia']], $facts['locations']);
        $this->assertTrue($facts['remote']);
        $this->assertSame('Excel, SQL', $facts['skills']);
        $this->assertSame(['Mining'], $facts['industry']);
    }

    public function test_long_values_and_long_lists_are_capped(): void
    {
        $places = array_map(fn ($i) => ['address' => ['addressLocality' => "Town {$i}"]], range(1, 40));
        $result = $this->parse(json_encode(['@type' => 'JobPosting', 'title' => str_repeat('T', 900), 'jobLocation' => $places]));

        $this->assertSame(300, mb_strlen($result['facts']['title']));
        $this->assertCount(10, $result['facts']['locations']);
    }

    // ---- the collector ----

    private function site(array $pages = []): void
    {
        $pages += ['/jobs' => 'listing-1', '/jobs/101-budget-analyst' => 'detail-structured', '/jobs/102-driver' => 'detail-101'];

        $this->app->forgetInstance(Factory::class);
        Http::clearResolvedInstances();
        Http::fake(function (Request $request) use ($pages) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if ($path === '/robots.txt') {
                return Http::response("User-agent: *\nAllow: /", 200);
            }

            $page = $pages[$path] ?? null;

            return $page ? Http::response($this->fixture($page), 200) : Http::response('not found', 404);
        });
    }

    private function board(array $html = [], array $attributes = []): Source
    {
        return Source::create(array_merge([
            'name' => 'Example Board', 'website_url' => 'https://board.test/', 'job_listing_url' => 'https://board.test/jobs',
            'source_type' => 'job_board', 'collection_method' => 'html', 'collector' => 'html_listing', 'collection_frequency' => 'daily',
            'access_status' => 'approved', 'is_active' => true, 'retain_raw' => true,
            'collector_config' => ['html' => array_merge(self::HTML, $html)],
        ], $attributes));
    }

    public function test_structured_facts_are_stored_and_the_description_is_used_as_the_text(): void
    {
        $this->site();
        $run = app(HtmlListingCollector::class)->collect($this->board());

        $this->assertSame('success', $run->status);
        $ad = RawJobPost::where('source_url', 'https://board.test/jobs/101-budget-analyst')->first();

        $this->assertSame('Example Charity', $ad->structured_data['employer']);
        $this->assertSame(12000, $ad->structured_data['salary']['min']);
        $this->assertStringContainsString('Prepare budgets', $ad->raw_content);
        $this->assertStringNotContainsString('hr@example.org', $ad->raw_content);
        $this->assertStringContainsString('[email removed]', $ad->raw_content);
    }

    public function test_a_text_selector_wins_over_the_description(): void
    {
        $this->site(['/jobs/101-budget-analyst' => 'detail-101']); // has div.ad-body but no JSON-LD
        $run = app(HtmlListingCollector::class)->collect($this->board(['content' => 'div.ad-body']));

        $ad = RawJobPost::where('source_url', 'like', '%101%')->first();
        $this->assertStringContainsString('Full ad: prepare budgets', $ad->raw_content);
        $this->assertNull($ad->structured_data);
        $this->assertSame('success', $run->status);
    }

    public function test_pages_without_structured_data_are_fine(): void
    {
        $this->site(['/jobs/101-budget-analyst' => 'detail-101']);

        $run = app(HtmlListingCollector::class)->collect($this->board(['content' => 'div.ad-body']));

        $this->assertSame(0, $run->errors()->count());
        $this->assertSame(2, RawJobPost::count());
        $this->assertSame(0, RawJobPost::whereNotNull('structured_data')->count());
    }

    public function test_nothing_is_read_when_the_option_is_off(): void
    {
        $this->site();

        app(HtmlListingCollector::class)->collect($this->board(['structured_data' => false, 'content' => 'div.ad-body']));

        $this->assertSame(0, RawJobPost::whereNotNull('structured_data')->count());
    }

    public function test_emails_in_structured_values_are_removed(): void
    {
        $this->site(['/jobs/101-budget-analyst' => 'detail-structured']);
        $json = '{"@type":"JobPosting","title":"Cook","hiringOrganization":{"name":"Cafe (apply at boss@cafe.test)"}}';
        $this->app->forgetInstance(Factory::class);
        Http::clearResolvedInstances();
        Http::fake(fn (Request $r) => str_ends_with($r->url(), '/robots.txt')
            ? Http::response('', 404)
            : (str_ends_with($r->url(), '/jobs') ? Http::response($this->fixture('listing-1')) : Http::response($this->ld($json))));

        app(HtmlListingCollector::class)->collect($this->board());

        $employer = RawJobPost::whereNotNull('structured_data')->first()->structured_data['employer'];
        $this->assertSame('Cafe (apply at [email removed])', $employer);
    }

    public function test_the_facts_survive_when_the_raw_text_is_cleared(): void
    {
        $this->site();
        app(HtmlListingCollector::class)->collect($this->board());
        RawJobPost::query()->update(['first_seen_at' => now()->subDays(91)]);

        $this->artisan('raw:purge')->assertSuccessful();

        $ad = RawJobPost::where('source_url', 'like', '%101%')->first();
        $this->assertNull($ad->raw_content);
        $this->assertSame('Budget Analyst', $ad->structured_data['title']);
    }

    // ---- a Phase 4 problem found on the way: later runs must not downgrade stored ads ----

    public function test_a_second_run_does_not_replace_the_full_text_or_store_new_versions(): void
    {
        $this->site();
        $board = $this->board();
        app(HtmlListingCollector::class)->collect($board);
        $before = RawJobPost::orderBy('id')->get()->map->only(['id', 'content_hash', 'raw_content'])->all();

        Event::fake([RawJobPostStored::class]);
        Carbon::setTestNow(now()->addDay());
        $run = app(HtmlListingCollector::class)->collect($board->fresh());
        Carbon::setTestNow();

        $this->assertSame(2, RawJobPost::count(), 'no new versions');
        $this->assertSame($before, RawJobPost::orderBy('id')->get()->map->only(['id', 'content_hash', 'raw_content'])->all());
        $this->assertSame([2, 0, 0, 2], [$run->items_found, $run->items_new, $run->items_updated, $run->items_unchanged]);
        Event::assertNotDispatched(RawJobPostStored::class);
        $this->assertStringContainsString('Prepare budgets', RawJobPost::where('source_url', 'like', '%101%')->value('raw_content'));
    }

    public function test_seen_again_ads_are_marked_as_still_listed(): void
    {
        $this->site();
        $board = $this->board();
        app(HtmlListingCollector::class)->collect($board);
        RawJobPost::query()->update(['last_seen_at' => now()->subDays(3), 'is_removed' => true]);

        app(HtmlListingCollector::class)->collect($board->fresh());

        $this->assertSame(0, RawJobPost::where('is_removed', true)->count());
        $this->assertSame(2, RawJobPost::where('last_seen_at', '>=', now()->subMinute())->count());
    }

    public function test_ads_stored_before_structured_data_existed_keep_their_fingerprint(): void
    {
        $xml = file_get_contents(base_path('tests/Fixtures/jobweb-feed.xml'));
        $this->seed(SourceSeeder::class);
        $this->app->forgetInstance(Factory::class);
        Http::clearResolvedInstances();
        Http::fake(fn (Request $r) => str_ends_with($r->url(), '/robots.txt') ? Http::response('', 404) : Http::response($xml));
        $source = Source::where('name', 'JobWeb Zambia')->first();
        $source->update(['access_status' => 'approved', 'is_active' => true]);

        app(JobWebFeedCollector::class)->collect($source->fresh());

        $ad = RawJobPost::where('external_id', '1002')->first();
        $this->assertSame(hash('sha256', $ad->raw_title."\n".trim(preg_replace('/\s+/', ' ', $ad->raw_content))), $ad->content_hash);
        $this->assertNull($ad->structured_data);
    }

    // ---- settings ----

    public function test_structured_data_needs_the_ad_pages_to_be_opened(): void
    {
        $base = ['item' => 'li.job-card'];

        $this->assertSame([], HtmlConfig::validate($base + ['fetch_detail' => true, 'structured_data' => true]));
        $this->assertSame([], HtmlConfig::validate($base + ['fetch_detail' => false, 'structured_data' => false]));
        $this->assertNotEmpty(HtmlConfig::validate($base + ['fetch_detail' => false, 'structured_data' => true]));
    }

    private function form(Source $source, array $html): array
    {
        return [
            'name' => $source->name, 'source_type' => 'job_board', 'website_url' => $source->website_url, 'job_listing_url' => $source->job_listing_url,
            'collection_method' => 'html', 'collector' => 'html_listing', 'collection_frequency' => 'daily', 'min_interval_seconds' => 1,
            'retain_raw' => '1', 'html' => ['item' => 'li.job-card'] + $html,
        ];
    }

    public function test_the_form_saves_the_option_and_refuses_it_without_ad_pages(): void
    {
        $this->signIn();
        $source = $this->board([], ['is_active' => false, 'access_status' => 'pending', 'collector_config' => null]);

        $this->put("/sources/{$source->id}", $this->form($source, ['fetch_detail' => '1', 'structured_data' => '1']))->assertRedirect("/sources/{$source->id}");
        $this->assertTrue($source->fresh()->collector_config['html']['structured_data']);

        $this->put("/sources/{$source->id}", $this->form($source, ['fetch_detail' => '0', 'structured_data' => '1']))->assertSessionHasErrors('html');

        $this->get("/sources/{$source->id}/edit")->assertOk()->assertSee('Also read the job data the ad page publishes');
    }

    public function test_the_source_page_counts_ads_with_structured_data(): void
    {
        $this->signIn();
        $this->site();
        $board = $this->board();
        app(HtmlListingCollector::class)->collect($board);

        $this->get("/sources/{$board->id}")->assertOk()->assertSee('1 with structured job data');
    }

    public function test_the_run_log_is_clean_when_everything_works(): void
    {
        $this->site();
        $run = app(HtmlListingCollector::class)->collect($this->board());

        $this->assertSame([0, 'success'], [$run->items_failed, $run->status]);
        $this->assertSame(1, SourceRun::count());
    }
}
