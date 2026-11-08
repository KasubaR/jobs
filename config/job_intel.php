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

    // Files of ads (Import file button, collect:import): most bytes and most ads in one file.
    'import_max_bytes' => 5 * 1024 * 1024,
    'import_max_rows' => 500,

    // Hand each stored or changed ad to the processing stage as a background job. Off by default: ads then wait
    // as "pending" without a job per ad (processing:dispatch queues them later, once you switch this on).
    'processing_enabled' => (bool) env('PROCESSING_ENABLED', false),

    // An ad with no closing date is not "open forever". It counts as ended this many days after it was posted
    // (or first seen, when no posted date is known), and is shown as an assumed end.
    'assumed_ad_age_days' => (int) env('ASSUMED_AD_AGE_DAYS', 45),

    // Only for a source that lists every open ad: an ad missing from this many successful runs in a row is closed.
    'closed_after_missing_runs' => 2,

    // A background collection job is stopped after this many seconds (needs the pcntl extension; without it
    // the stuck-run cleanup below still closes the run).
    'collect_job_timeout' => (int) env('JOB_INTEL_COLLECT_TIMEOUT', 900),

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

    // API keys for sources collected through an official API. A key lives ONLY here, in the server's .env, as a
    // setting named COLLECTOR_KEY_<SOMETHING>; a source stores just that name, never the key. Nothing else from
    // the environment can be picked up this way.
    'api_keys' => collect([...$_SERVER, ...$_ENV])
        ->filter(fn ($value, $name) => is_string($name) && str_starts_with($name, 'COLLECTOR_KEY_') && is_string($value) && $value !== '')
        ->all(),

    // Refuse feeds bigger than this many bytes.
    'max_response_bytes' => 5 * 1024 * 1024,
];
