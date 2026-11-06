<?php

namespace App\Collectors;

use App\Collectors\Contracts\Collector;
use App\Models\Source;
use RuntimeException;

/**
 * Maps a source's `collector` key to its class. Adding a website =
 * one new collector class + one line here.
 */
class CollectorRegistry
{
    /** @var array<string, class-string<Collector>> */
    public const COLLECTORS = [
        'jobweb_feed' => JobWebFeedCollector::class,
        'manual_submission' => ManualSubmissionCollector::class,
        'html_listing' => HtmlListingCollector::class,
    ];

    /** @return array<string, class-string<Collector>> */
    public function all(): array
    {
        return self::COLLECTORS;
    }

    public function has(?string $key): bool
    {
        return $key !== null && isset(self::COLLECTORS[$key]);
    }

    public function for(Source $source): Collector
    {
        if (! $this->has($source->collector)) {
            throw new RuntimeException("Source '{$source->name}' has no collector (collector = ".($source->collector ?? 'none').').');
        }

        return app(self::COLLECTORS[$source->collector]);
    }
}
