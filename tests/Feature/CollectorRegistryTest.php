<?php

namespace Tests\Feature;

use App\Collectors\BaseCollector;
use App\Collectors\CollectorRegistry;
use App\Collectors\Contracts\Collector;
use App\Models\Source;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class CollectorRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['job_intel.collector_contact' => 'owner@example.com']);
    }

    public function test_every_registered_collector_follows_the_contract(): void
    {
        foreach (CollectorRegistry::COLLECTORS as $key => $class) {
            $collector = app($class);

            $this->assertInstanceOf(Collector::class, $collector, $key);
            $this->assertInstanceOf(BaseCollector::class, $collector, "$key must extend BaseCollector so the shared rules apply");
            $this->assertContains($collector->method(), ['rss', 'api', 'html', 'manual', 'python'], $key);
            $this->assertIsArray($collector->configRules(), $key);
        }
    }

    public function test_seeded_jobweb_source_resolves_to_its_collector(): void
    {
        $this->seed(SourceSeeder::class);
        $source = Source::where('name', 'JobWeb Zambia')->first();

        $this->assertSame('jobweb_feed', $source->collector);
        $this->assertSame('rss', app(CollectorRegistry::class)->for($source)->method());
    }

    public function test_source_without_a_collector_is_refused(): void
    {
        $this->seed(SourceSeeder::class);
        $source = Source::where('name', 'Job Search Zambia')->first();

        $this->expectException(RuntimeException::class);
        app(CollectorRegistry::class)->for($source);
    }

    public function test_check_reports_reachable_feed_and_stores_nothing(): void
    {
        Http::fake(['*/robots.txt' => Http::response('', 404), '*' => Http::response(file_get_contents(base_path('tests/Fixtures/jobweb-feed.xml')), 200)]);
        $this->seed(SourceSeeder::class);
        $source = Source::where('name', 'JobWeb Zambia')->first();

        $result = app(CollectorRegistry::class)->for($source)->check($source);

        $this->assertTrue($result->ok);
        $this->assertSame(2, $result->itemCount);
        Http::assertSentCount(2); // robots.txt + the feed
        $this->assertSame(0, \App\Models\RawJobPost::count());
        $this->assertSame(0, \App\Models\SourceRun::count());
    }

    public function test_check_reports_failures_instead_of_throwing(): void
    {
        Http::fake(['*/robots.txt' => Http::response('', 404), '*' => Http::response('nope', 503)]);
        $this->seed(SourceSeeder::class);
        $source = Source::where('name', 'JobWeb Zambia')->first();

        $result = app(CollectorRegistry::class)->for($source)->check($source);

        $this->assertFalse($result->ok);
        $this->assertSame(503, $result->httpStatus);
    }

    public function test_check_makes_no_request_for_a_restricted_source(): void
    {
        Http::fake();
        $this->seed(SourceSeeder::class);
        $source = Source::where('name', 'JobWeb Zambia')->first();
        $source->update(['access_status' => 'restricted']);

        $result = app(CollectorRegistry::class)->for($source)->check($source);

        $this->assertFalse($result->ok);
        Http::assertNothingSent();
    }

    public function test_collect_command_uses_the_registry_and_reports_a_missing_collector_without_stopping(): void
    {
        Http::fake(['*' => Http::response(file_get_contents(base_path('tests/Fixtures/jobweb-feed.xml')), 200)]);
        $this->seed(SourceSeeder::class);
        Source::where('name', 'JobWeb Zambia')->first()->update(['access_status' => 'approved', 'is_active' => true]);
        Source::where('name', 'Job Search Zambia')->first()->update(['access_status' => 'approved', 'is_active' => true]);

        $this->artisan('collect:run')
            ->expectsOutputToContain('JobWeb Zambia: success')
            ->expectsOutputToContain('has no collector')
            ->assertFailed();
    }
}
