<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Job Search Zambia (WordPress) was seeded without a collector. robots.txt allows everything. Ads are found
 * in its job_listing sitemap (newest first, /job/* only); each new ad's own page is opened for its title,
 * text and the schema.org JobPosting block.
 *
 * Only fills in a source that has no collector yet, so a hand-edited source is never overwritten.
 * It does NOT approve or switch the source on: its terms forbid copying site material, so that stays
 * your decision (ideally after written permission).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sources')
            ->where('website_url', 'https://jobsearchzm.com/')
            ->whereNull('collector')
            ->update([
                'collector' => 'html_listing',
                'collection_method' => 'html',
                'collection_frequency' => 'every_6_hours',
                'min_interval_seconds' => 2,
                'collector_config' => json_encode(['html' => [
                    'sitemap' => 'https://jobsearchzm.com/job_listing-sitemap.xml',
                    'sitemap_match' => '/job/*',
                    'title' => 'h1.entry-title',
                    'content' => '.entry-content',
                    'fetch_detail' => true,
                    'structured_data' => true,
                    'max_pages' => 1,
                    'max_items' => 60,
                    'max_detail_requests' => 20,
                ]]),
            ]);
    }

    public function down(): void
    {
        DB::table('sources')
            ->where('website_url', 'https://jobsearchzm.com/')
            ->where('collector', 'html_listing')
            ->update(['collector' => null, 'collector_config' => null, 'collection_method' => 'pending_review', 'collection_frequency' => 'weekly']);
    }
};
