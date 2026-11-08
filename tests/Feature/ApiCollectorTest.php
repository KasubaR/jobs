<?php

namespace Tests\Feature;

use App\Collectors\Api\ApiConfig;
use App\Collectors\Api\JsonPath;
use App\Collectors\ApiJsonCollector;
use App\Models\RawJobPost;
use App\Models\Source;
use App\Services\SourceManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/** Uses synthetic JSON in tests/Fixtures/api. No real API is contacted. */
class ApiCollectorTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    private const API = [
        'items_path' => 'data.jobs',
        'title' => 'title',
        'url' => 'link',
        'id' => 'id',
        'content' => 'description',
        'employer' => 'company',
        'location' => 'place',
        'date_posted' => 'posted',
        'valid_through' => 'closes',
        'employment_type' => 'type',
    ];

    private const KEY = 'sk-live-very-secret-value-123';

    /** @var list<Request> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['job_intel.collector_contact' => 'owner@example.com', 'job_intel.api_keys' => ['COLLECTOR_KEY_BOARD' => self::KEY]]);
    }

    private function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/Fixtures/api/{$name}.json"));
    }

    /**
     * @param  array<string, string|int>  $responses  "path" or "path?query" => fixture name, or an HTTP status
     */
    private function api(array $responses = [], string $robots = "User-agent: *\nAllow: /"): void
    {
        $this->sent = [];
        $responses += ['/api/jobs' => 'jobs-1', '/api/jobs?page=2' => 'jobs-2', '/api/jobs?page=3' => 'jobs-empty'];

        $this->app->forgetInstance(\Illuminate\Http\Client\Factory::class);
        Http::clearResolvedInstances();
        Http::fake(function (Request $request) use ($responses, $robots) {
            $this->sent[] = $request;
            $path = parse_url($request->url(), PHP_URL_PATH).(parse_url($request->url(), PHP_URL_QUERY) ? '?'.parse_url($request->url(), PHP_URL_QUERY) : '');

            if ($path === '/robots.txt') {
                return Http::response($robots, 200);
            }

            $response = $responses[$path] ?? null;

            return match (true) {
                is_int($response) => Http::response('error', $response),
                is_string($response) && str_starts_with($response, 'raw:') => Http::response(substr($response, 4), 200),
                is_string($response) => Http::response($this->fixture($response), 200),
                default => Http::response('not found', 404),
            };
        });
    }

    private function source(array $api = [], array $attributes = []): Source
    {
        return Source::create(array_merge([
            'name' => 'Example API Board',
            'website_url' => 'https://board.test/',
            'job_listing_url' => 'https://board.test/api/jobs',
            'source_type' => 'job_board',
            'collection_method' => 'api',
            'collector' => 'api_json',
            'collection_frequency' => 'daily',
            'access_status' => 'approved',
            'is_active' => true,
            'collector_config' => ['api' => array_merge(self::API, $api)],
        ], $attributes));
    }

    /** @return list<string> the API requests made (robots.txt left out) */
    private function pagesFetched(): array
    {
        return array_values(array_filter(array_map(
            fn (Request $r) => parse_url($r->url(), PHP_URL_PATH).(parse_url($r->url(), PHP_URL_QUERY) ? '?'.parse_url($r->url(), PHP_URL_QUERY) : ''),
            $this->sent,
        ), fn ($p) => $p !== '/robots.txt'));
    }

    private function apiRequests(): array
    {
        return array_values(array_filter($this->sent, fn (Request $r) => parse_url($r->url(), PHP_URL_PATH) !== '/robots.txt'));
    }

    // ---- reading ads ----

    public function test_reads_ads_with_their_facts_and_keeps_only_links_on_the_sites_own_host(): void
    {
        $this->api();
        $run = app(ApiJsonCollector::class)->collect($this->source());

        $this->assertSame('partial', $run->status); // two items had no usable title or link
        $this->assertSame(2, $run->items_found);
        $this->assertSame(2, $run->items_new);

        $budget = RawJobPost::where('raw_title', 'Budget Analyst')->first();
        $this->assertSame('https://board.test/jobs/101-budget-analyst', $budget->source_url);
        $this->assertSame('101', $budget->external_id);
        $this->assertStringNotContainsString('hr@example.org', $budget->raw_content); // emails are removed
        $this->assertSame([
            'title' => 'Budget Analyst', 'employer' => 'Example Charity', 'locations' => [['locality' => 'Lusaka']],
            'date_posted' => '2026-11-02', 'valid_through' => '2026-11-30', 'employment_type' => ['FULL_TIME'], 'identifier' => '101',
        ], $budget->structured_data);

        $driver = RawJobPost::where('raw_title', 'Driver')->first();
        $this->assertSame('https://board.test/jobs/102-driver', $driver->source_url); // a relative link is resolved
        $this->assertSame("Drive haul trucks.\nValid licence required.", $driver->raw_content); // a list of lines is joined

        $this->assertNull(RawJobPost::where('raw_title', 'Off-site ad')->first()); // another site
        $this->assertSame(2, RawJobPost::count());
    }

    public function test_the_skipped_items_are_listed_as_problems_of_the_run(): void
    {
        $this->api();
        $run = app(ApiJsonCollector::class)->collect($this->source());

        $this->assertSame(2, $run->errors()->where('stage', 'listing')->count());
        $this->assertStringContainsString('no usable title and link', $run->errors()->first()->message);
    }

    public function test_running_again_changes_nothing(): void
    {
        $this->api();
        $source = $this->source();
        app(ApiJsonCollector::class)->collect($source);

        $second = app(ApiJsonCollector::class)->collect($source->fresh());

        $this->assertSame(0, $second->items_new);
        $this->assertSame(2, $second->items_unchanged);
        $this->assertSame(2, RawJobPost::count());
    }

    public function test_a_response_that_is_itself_a_list_needs_no_list_path(): void
    {
        $this->api(['/api/jobs' => 'top-level-list']);
        $source = $this->source(
            ['items_path' => '', 'title' => 'name', 'url' => '', 'url_template' => 'https://board.test/jobs/{id}', 'id' => 'ref', 'content' => 'text', 'employer' => '', 'location' => '', 'date_posted' => '', 'valid_through' => '', 'employment_type' => ''],
        );

        $run = app(ApiJsonCollector::class)->collect($source);

        $this->assertSame('success', $run->status);
        $this->assertSame('https://board.test/jobs/A-1', RawJobPost::where('raw_title', 'Teacher')->value('source_url'));
        $this->assertSame('Teach maths.', RawJobPost::where('raw_title', 'Teacher')->value('raw_content'));
    }

    public function test_the_title_is_stored_when_there_is_no_text_field(): void
    {
        $this->api();
        app(ApiJsonCollector::class)->collect($this->source(['content' => '']));

        $this->assertSame('Driver', RawJobPost::where('raw_title', 'Driver')->value('raw_content'));
    }

    // ---- pages ----

    public function test_reads_more_pages_until_one_is_empty_and_never_more_than_the_limit(): void
    {
        $this->api();
        $run = app(ApiJsonCollector::class)->collect($this->source(['page_param' => 'page', 'max_pages' => 5]));

        $this->assertSame(['/api/jobs', '/api/jobs?page=2', '/api/jobs?page=3'], $this->pagesFetched());
        $this->assertSame(3, $run->items_found); // 2 from page 1, 1 from page 2, none from page 3
    }

    public function test_the_page_limit_is_respected(): void
    {
        $this->api();
        app(ApiJsonCollector::class)->collect($this->source(['page_param' => 'page', 'max_pages' => 2]));

        $this->assertSame(['/api/jobs', '/api/jobs?page=2'], $this->pagesFetched());
    }

    public function test_the_item_limit_stops_the_run(): void
    {
        $this->api();
        $run = app(ApiJsonCollector::class)->collect($this->source(['page_param' => 'page', 'max_pages' => 3, 'max_items' => 1]));

        $this->assertSame(1, $run->items_found);
        $this->assertSame(['/api/jobs'], $this->pagesFetched());
    }

    public function test_a_later_page_that_fails_keeps_the_earlier_ads_and_marks_the_run_partial(): void
    {
        $this->api(['/api/jobs?page=2' => 500]);
        $run = app(ApiJsonCollector::class)->collect($this->source(['page_param' => 'page', 'max_pages' => 3]));

        $this->assertSame('partial', $run->status);
        $this->assertSame(2, RawJobPost::count());
        $this->assertTrue($run->errors()->where('message', 'like', 'Page 2:%')->exists());
    }

    public function test_test_access_looks_at_the_first_page_only_and_stores_nothing(): void
    {
        $this->api();
        $check = app(ApiJsonCollector::class)->check($this->source(['page_param' => 'page', 'max_pages' => 5]));

        $this->assertTrue($check->ok);
        $this->assertSame(['/api/jobs'], $this->pagesFetched());
        $this->assertSame(0, RawJobPost::count());
    }

    // ---- when the API is not what we expect ----

    public function test_unreadable_json_fails_the_run(): void
    {
        $this->api(['/api/jobs' => 'raw:<html>maintenance</html>']);
        $run = app(ApiJsonCollector::class)->collect($this->source());

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('not readable JSON', $run->error_message);
    }

    public function test_a_missing_list_fails_with_a_clear_message(): void
    {
        $this->api();
        $run = app(ApiJsonCollector::class)->collect($this->source(['items_path' => 'data.vacancies']));

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('no list of ads at "data.vacancies"', $run->error_message);
    }

    public function test_wrong_field_names_fail_instead_of_looking_like_a_quiet_success(): void
    {
        $this->api();
        $run = app(ApiJsonCollector::class)->collect($this->source(['title' => 'headline']));

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('None of the 4 items had a usable title and link', $run->error_message);
        $this->assertSame(0, RawJobPost::count());
    }

    public function test_an_empty_list_is_a_run_with_no_ads_not_an_error(): void
    {
        $this->api(['/api/jobs' => 'jobs-empty']);
        $run = app(ApiJsonCollector::class)->collect($this->source());

        $this->assertSame('success', $run->status);
        $this->assertSame(0, $run->items_found);
    }

    public function test_robots_txt_that_forbids_the_api_is_obeyed(): void
    {
        $this->api([], "User-agent: *\nDisallow: /api");
        $run = app(ApiJsonCollector::class)->collect($this->source());

        $this->assertSame('failed', $run->status);
        $this->assertSame([], $this->pagesFetched());
    }

    public function test_redirects_are_never_followed_so_the_key_cannot_be_handed_to_another_host(): void
    {
        $this->sent = [];
        $this->app->forgetInstance(\Illuminate\Http\Client\Factory::class);
        Http::clearResolvedInstances();
        Http::fake(function (Request $request) {
            $this->sent[] = $request;

            return match (true) {
                str_ends_with($request->url(), '/robots.txt') => Http::response("User-agent: *\nAllow: /", 200),
                str_starts_with($request->url(), 'https://board.test/api/jobs') => Http::response('', 302, ['Location' => 'https://evil.test/collect']),
                default => Http::response($this->fixture('jobs-1'), 200),
            };
        });

        $run = app(ApiJsonCollector::class)->collect($this->source(['key_name' => 'COLLECTOR_KEY_BOARD', 'key_header' => 'X-API-Key']));

        $this->assertSame('failed', $run->status);
        $this->assertSame([], array_filter($this->sent, fn (Request $r) => str_contains($r->url(), 'evil.test')));
        $this->assertSame(0, RawJobPost::count());
    }

    public function test_only_an_approved_active_source_is_collected(): void
    {
        $this->api();
        $this->expectException(RuntimeException::class);

        app(ApiJsonCollector::class)->collect($this->source([], ['is_active' => false]));
    }

    // ---- the API key ----

    public function test_the_key_is_sent_in_a_header_and_nowhere_else(): void
    {
        $this->api();
        app(ApiJsonCollector::class)->collect($this->source(['key_name' => 'COLLECTOR_KEY_BOARD']));

        $request = $this->apiRequests()[0];
        $this->assertSame('Bearer '.self::KEY, $request->header('Authorization')[0]);
        $this->assertStringNotContainsString(self::KEY, $request->url());
        $this->assertStringStartsWith('ZamtrendsBot/', $request->header('User-Agent')[0]);
    }

    public function test_a_custom_header_sends_the_key_as_it_is(): void
    {
        $this->api();
        app(ApiJsonCollector::class)->collect($this->source(['key_name' => 'COLLECTOR_KEY_BOARD', 'key_header' => 'X-API-Key']));

        $this->assertSame(self::KEY, $this->apiRequests()[0]->header('X-API-Key')[0]);
        $this->assertSame([], $this->apiRequests()[0]->header('Authorization'));
    }

    public function test_the_key_is_not_sent_to_robots_txt_or_when_the_source_has_none(): void
    {
        $this->api();
        app(ApiJsonCollector::class)->collect($this->source(['key_name' => 'COLLECTOR_KEY_BOARD']));

        $robots = collect($this->sent)->first(fn (Request $r) => str_ends_with($r->url(), '/robots.txt'));
        $this->assertSame([], $robots->header('Authorization'));

        $this->api();
        app(ApiJsonCollector::class)->collect($this->source([], ['name' => 'No key', 'website_url' => 'https://nokey.test/', 'job_listing_url' => 'https://nokey.test/api/jobs']));
        $this->assertSame([], $this->apiRequests()[0]->header('Authorization'));
    }

    public function test_the_key_never_reaches_the_database_or_the_logs(): void
    {
        $this->api(['/api/jobs?page=2' => 401]);
        $source = $this->source(['key_name' => 'COLLECTOR_KEY_BOARD', 'page_param' => 'page', 'max_pages' => 2]);
        $run = app(ApiJsonCollector::class)->collect($source);

        $everything = json_encode([
            Source::all()->toArray(), RawJobPost::all()->toArray(), $run->fresh()->toArray(), $run->errors()->get()->toArray(),
        ]);

        $this->assertStringNotContainsString(self::KEY, $everything);
        $this->assertStringContainsString('COLLECTOR_KEY_BOARD', json_encode(Source::first()->collector_config)); // only the NAME is stored
    }

    public function test_a_refused_key_is_explained_without_printing_it(): void
    {
        $this->api(['/api/jobs' => 401]);
        $run = app(ApiJsonCollector::class)->collect($this->source(['key_name' => 'COLLECTOR_KEY_BOARD']));

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('check that the key in COLLECTOR_KEY_BOARD is correct', $run->error_message);
        $this->assertStringNotContainsString(self::KEY, $run->error_message);
    }

    public function test_a_source_that_needs_a_key_nobody_set_is_refused_before_any_request(): void
    {
        $this->api();
        config(['job_intel.api_keys' => []]);

        $run = null;
        try {
            app(ApiJsonCollector::class)->collect($this->source(['key_name' => 'COLLECTOR_KEY_BOARD']));
        } catch (RuntimeException $e) {
            $run = $e;
        }

        $this->assertNotNull($run);
        $this->assertStringContainsString('add COLLECTOR_KEY_BOARD=... to the server\'s .env file', $run->getMessage());
        $this->assertSame([], $this->sent);
    }

    public function test_a_key_is_never_sent_over_plain_http(): void
    {
        $this->api();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must start with https://');

        try {
            app(ApiJsonCollector::class)->collect($this->source(['key_name' => 'COLLECTOR_KEY_BOARD'], ['job_listing_url' => 'http://board.test/api/jobs']));
        } finally {
            $this->assertSame([], $this->sent);
        }
    }

    public function test_only_collector_key_settings_can_be_used_as_keys(): void
    {
        $_ENV['COLLECTOR_KEY_FROM_ENV'] = 'abc';
        $_ENV['DB_PASSWORD'] = 'database-secret';
        $_ENV['APP_KEY'] = 'base64:app-secret';

        try {
            $keys = (require config_path('job_intel.php'))['api_keys'];
        } finally {
            unset($_ENV['COLLECTOR_KEY_FROM_ENV'], $_ENV['DB_PASSWORD'], $_ENV['APP_KEY']);
        }

        $this->assertSame('abc', $keys['COLLECTOR_KEY_FROM_ENV']);
        $this->assertArrayNotHasKey('DB_PASSWORD', $keys);
        $this->assertArrayNotHasKey('APP_KEY', $keys);

        $this->assertNotEmpty(ApiConfig::validate(['title' => 't', 'url' => 'u', 'key_name' => 'APP_KEY']));
        $this->assertNotEmpty(ApiConfig::validate(['title' => 't', 'url' => 'u', 'key_name' => 'DB_PASSWORD']));
    }

    // ---- settings ----

    public function test_settings_are_validated(): void
    {
        $ok = ['title' => 'title', 'url' => 'link'];
        $this->assertSame([], ApiConfig::validate($ok));

        $bad = [
            'no title' => ['url' => 'link'],
            'no link' => ['title' => 'title'],
            'odd path' => ['title' => 'a b;c', 'url' => 'link'],
            'template without {id}' => ['title' => 't', 'url_template' => 'https://x.test/jobs/'],
            'template without an id field' => ['title' => 't', 'url_template' => 'https://x.test/jobs/{id}'],
            'template on another scheme' => ['title' => 't', 'id' => 'id', 'url_template' => 'ftp://x.test/{id}'],
            'too many pages' => $ok + ['max_pages' => 6, 'page_param' => 'page'],
            'pages without a parameter' => $ok + ['max_pages' => 2],
            'too many items' => $ok + ['max_items' => 101],
            'key header Host' => $ok + ['key_header' => 'Host'],
            'key header with a newline' => $ok + ['key_header' => "X-Key\r\nX-Evil: 1"],
        ];

        foreach ($bad as $label => $settings) {
            $this->assertNotEmpty(ApiConfig::validate($settings), "Expected a problem for: {$label}");
        }
    }

    public function test_json_paths_read_nested_values_and_lists(): void
    {
        $data = ['a' => ['b' => [['c' => 'x'], ['c' => 'y']]], 'n' => 5, 'empty' => '  ', 'obj' => ['k' => 'v']];

        $this->assertSame('y', JsonPath::get($data, 'a.b.1.c'));
        $this->assertNull(JsonPath::get($data, 'a.zzz'));
        $this->assertSame($data, JsonPath::get($data, ''));
        $this->assertSame('5', JsonPath::text(5));
        $this->assertNull(JsonPath::text('  '));
        $this->assertNull(JsonPath::text(['k' => 'v'])); // an object is not text
        $this->assertSame("x\ny", JsonPath::text(['x', 'y']));
    }

    // ---- the admin screens ----

    private function form(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'API Board', 'source_type' => 'job_board', 'website_url' => 'https://board.test/',
            'job_listing_url' => 'https://board.test/api/jobs', 'collection_method' => 'api', 'collector' => 'api_json',
            'collection_frequency' => 'every_6_hours', 'min_interval_seconds' => 2, 'retain_raw' => '1', 'notes' => '',
            'api' => ['items_path' => 'data.jobs', 'title' => 'title', 'url' => 'link', 'max_pages' => '1', 'max_items' => '50', 'key_header' => 'Authorization'],
        ];
    }

    public function test_the_form_saves_api_settings_and_only_the_key_name(): void
    {
        $this->signIn();
        $form = $this->form();
        $form['api']['key_name'] = 'COLLECTOR_KEY_BOARD';

        $this->post('/sources', $form)->assertSessionHasNoErrors();

        $source = Source::where('name', 'API Board')->first();
        $this->assertSame('api_json', $source->collector);
        $this->assertSame('data.jobs', $source->collector_config['api']['items_path']);
        $this->assertSame('COLLECTOR_KEY_BOARD', $source->collector_config['api']['key_name']);
        $this->assertStringNotContainsString(self::KEY, json_encode($source->toArray()));
    }

    public function test_the_form_refuses_unsafe_or_incomplete_api_settings(): void
    {
        $this->signIn();

        $badKeyName = $this->form();
        $badKeyName['api']['key_name'] = 'APP_KEY';
        $this->post('/sources', $badKeyName)->assertSessionHasErrors('api');

        $noTitle = $this->form();
        $noTitle['api']['title'] = '';
        $this->post('/sources', $noTitle)->assertSessionHasErrors('api');

        $this->post('/sources', $this->form(['collection_method' => 'html']))->assertSessionHasErrors('collection_method');

        $http = $this->form(['job_listing_url' => 'http://board.test/api/jobs']);
        $http['api']['key_name'] = 'COLLECTOR_KEY_BOARD';
        $this->post('/sources', $http)->assertSessionHasErrors('job_listing_url');

        $this->post('/sources', $this->form(['job_listing_url' => '']))->assertSessionHasErrors('job_listing_url');

        $this->assertSame(0, Source::count());
    }

    public function test_the_add_and_edit_screens_show_the_api_settings(): void
    {
        $this->signIn();
        $source = $this->source(['key_name' => 'COLLECTOR_KEY_BOARD']);

        $this->get('/sources/create')->assertOk()->assertSee('api_json')->assertSee('Public API');
        $this->get("/sources/{$source->id}/edit")->assertOk()->assertSee('data.jobs')->assertSee('COLLECTOR_KEY_BOARD')->assertDontSee(self::KEY);
    }

    public function test_activation_explains_a_missing_key_and_incomplete_settings(): void
    {
        $manager = app(SourceManager::class);

        config(['job_intel.api_keys' => []]);
        $source = $this->source(['key_name' => 'COLLECTOR_KEY_BOARD'], ['is_active' => false]);
        $this->assertStringContainsString('add COLLECTOR_KEY_BOARD=... to the .env file', implode(' ', $manager->activationProblems($source)));

        $incomplete = $this->source(['title' => ''], ['name' => 'Incomplete', 'website_url' => 'https://other.test/', 'job_listing_url' => 'https://other.test/api', 'is_active' => false]);
        $this->assertStringContainsString('API settings:', implode(' ', $manager->activationProblems($incomplete)));
    }

    public function test_the_collector_is_listed_and_matches_the_api_method(): void
    {
        $this->assertSame('api', app(ApiJsonCollector::class)->method());
        $this->assertArrayHasKey('api_json', \App\Collectors\CollectorRegistry::COLLECTORS);
    }
}
