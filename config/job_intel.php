<?php

return [
    // The only account allowed into the panel. Leave unset to lock everyone out.
    'admin_email' => env('ADMIN_EMAIL'),

    // Included in the collector's User-Agent so site owners can reach us.
    // Collection refuses to run until this is set.
    'collector_contact' => env('COLLECTOR_CONTACT'),

    // Raw ad text is cleared after this many days (structured data is kept).
    'raw_retention_days' => (int) env('RAW_RETENTION_DAYS', 90),

    // After a failed run, wait this many minutes before trying again (nth failure in a row;
    // the last value is reused for later failures).
    'retry_backoff_minutes' => [60, 360, 1440],

    // A run still marked "running" after this many minutes is treated as dead and marked failed.
    'stuck_run_minutes' => 30,

    // A source is switched off after this many failed runs in a row. A person must turn it back on.
    'max_consecutive_failures' => (int) env('JOB_INTEL_MAX_FAILURES', 5),

    // How long a host's robots.txt is cached.
    'robots_cache_hours' => 24,

    // Wait between requests to the same host (sources.min_interval_seconds / Crawl-delay). Off only in tests.
    'throttle' => (bool) env('JOB_INTEL_THROTTLE', true),

    // Seconds to wait for a source before giving up.
    'http_timeout' => 20,

    // Refuse feeds bigger than this many bytes.
    'max_response_bytes' => 5 * 1024 * 1024,
];
