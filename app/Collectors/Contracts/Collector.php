<?php

namespace App\Collectors\Contracts;

use App\Collectors\AccessCheck;
use App\Models\Source;
use App\Models\SourceRun;

/**
 * One implementation per website (or per site technology). When a site changes
 * its layout, only that site's collector changes.
 */
interface Collector
{
    /** Fetch new ads, store them as raw posts, record and return the run. */
    public function collect(Source $source, string $trigger = 'scheduled'): SourceRun;

    /** Safe "is this reachable and readable?" test. Stores nothing. */
    public function check(Source $source): AccessCheck;

    /** rss | api | html | manual */
    public function method(): string;

    /**
     * Laravel validation rules for this collector's settings (sources.collector_config).
     *
     * @return array<string, mixed>
     */
    public function configRules(): array;
}
