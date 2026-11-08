<?php

namespace Tests\Feature;

use App\Collectors\Html\HtmlConfig;
use App\Models\Source;
use Database\Seeders\SourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoZambiaJobsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://gozambiajobs.com/';

    private function migration(): object
    {
        return require database_path('migrations/2026_11_08_000002_configure_go_zambia_jobs.php');
    }

    private function seededTheOldWay(): Source
    {
        $this->seed(SourceSeeder::class);

        // What the live database looks like: seeded before the collector existed.
        Source::where('website_url', self::URL)->update([
            'collector' => null, 'collector_config' => null, 'collection_frequency' => 'daily', 'min_interval_seconds' => 1,
        ]);

        return Source::where('website_url', self::URL)->first();
    }

    public function test_a_fresh_seed_already_has_the_collector_and_valid_settings(): void
    {
        $this->seed(SourceSeeder::class);
        $source = Source::where('website_url', self::URL)->first();

        $this->assertSame('html_listing', $source->collector);
        $this->assertSame([], HtmlConfig::validate($source->collector_config['html']));
        $this->assertSame('every_6_hours', $source->collection_frequency);
        $this->assertSame('pending', $source->access_status);
        $this->assertFalse($source->is_active);
    }

    public function test_the_migration_configures_an_already_seeded_source_without_approving_it(): void
    {
        $this->seededTheOldWay();

        $this->migration()->up();

        $source = Source::where('website_url', self::URL)->first();
        $this->assertSame('html_listing', $source->collector);
        $this->assertSame('div.job-listings-item', $source->collector_config['html']['item']);
        $this->assertSame([], HtmlConfig::validate($source->collector_config['html']));
        $this->assertSame('every_6_hours', $source->collection_frequency);
        $this->assertSame(2, $source->min_interval_seconds);
        $this->assertSame('pending', $source->access_status);
        $this->assertFalse($source->is_active);
    }

    public function test_the_migration_leaves_a_source_you_already_configured_alone(): void
    {
        $this->seededTheOldWay();
        Source::where('website_url', self::URL)->update([
            'collector' => 'python_worker', 'collection_frequency' => 'weekly', 'collector_config' => json_encode(['python' => ['script' => 'mine']]),
        ]);

        $this->migration()->up();

        $source = Source::where('website_url', self::URL)->first();
        $this->assertSame('python_worker', $source->collector);
        $this->assertSame('weekly', $source->collection_frequency);
        $this->assertSame('mine', $source->collector_config['python']['script']);
    }
}
