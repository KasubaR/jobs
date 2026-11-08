<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Go Zambia Jobs was seeded without a collector. Read from one saved listing page and one ad page
 * (robots.txt allows /jobs; /rss/ is disallowed and is never used): the listing cards, the next-page
 * link and the schema.org JobPosting block on each ad page.
 *
 * Only fills in a source that has no collector yet, so a hand-edited source is never overwritten.
 * It does NOT approve or switch the source on: that stays your decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sources')
            ->where('website_url', 'https://gozambiajobs.com/')
            ->whereNull('collector')
            ->update([
                'collector' => 'html_listing',
                'collection_method' => 'html',
                'collection_frequency' => 'every_6_hours',
                'min_interval_seconds' => 2,
                'collector_config' => json_encode(['html' => [
                    'item' => 'div.job-listings-item',
                    'link' => 'a[href^="/jobs/"]',
                    'title' => 'h3',
                    'next' => 'a[rel="next"]',
                    'fetch_detail' => true,
                    'structured_data' => true,
                    'max_pages' => 2,
                    'max_items' => 60,
                    'max_detail_requests' => 20,
                ]]),
            ]);
    }

    public function down(): void
    {
        DB::table('sources')
            ->where('website_url', 'https://gozambiajobs.com/')
            ->where('collector', 'html_listing')
            ->update(['collector' => null, 'collector_config' => null, 'collection_frequency' => 'daily']);
    }
};
