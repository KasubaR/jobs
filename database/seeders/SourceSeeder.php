<?php

namespace Database\Seeders;

use App\Models\Source;
use Illuminate\Database\Seeder;

/**
 * Loads the Phase 1 source register. Re-runnable: matches on website_url and never
 * overwrites a decision you have since made in the app (access_status / is_active).
 */
class SourceSeeder extends Seeder
{
    public function run(): void
    {
        $rows = json_decode(file_get_contents(__DIR__.'/sources.json'), true, flags: JSON_THROW_ON_ERROR);

        foreach ($rows as $row) {
            $source = Source::firstOrNew(['website_url' => $row['website_url']]);

            if ($source->exists) {
                continue; // keep later edits
            }

            $restricted = $row['access_status'] === 'restricted';

            $source->fill([
                'name' => $row['source_name'],
                'job_listing_url' => $row['job_listing_url'],
                'source_type' => $row['source_type'],
                'collection_method' => $row['collection_method'],
                'collector' => $row['collector'] ?? null,
                'collector_config' => $row['collector_config'] ?? null,
                'min_interval_seconds' => $row['min_interval_seconds'] ?? 1,
                'collection_frequency' => $row['collection_frequency'],
                'access_status' => $row['access_status'],
                'access_decided_at' => $restricted ? now() : null,
                'access_decision_note' => $restricted ? 'Restricted during Phase 1 research (see notes).' : null,
                'is_active' => false, // nothing starts collecting until you approve it
                'retain_raw' => ! $restricted,
                'notes' => $row['notes'],
            ])->save();
        }
    }
}
