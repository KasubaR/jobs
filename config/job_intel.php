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

    // Duplicate detection. A wrong merge hides a real vacancy, so the automatic line is high and the middle goes to a person.
    'duplicates' => [
        'auto' => (int) env('DUPLICATE_AUTO_SCORE', 90),     // this score or more: marked duplicate automatically
        'possible' => (int) env('DUPLICATE_REVIEW_SCORE', 70), // from here to just under "auto": shown for a person to decide
        'window_days' => 60,                                  // only ads posted this close together are compared
        'closing_conflict_days' => 30,                        // closing dates further apart than this: a new opening, not a copy
        'identical_text_distance' => 3,                       // text fingerprints this close count as the same text
        // Points per part; they add up to 100. Text counts most because it is hardest to fake by accident.
        'weights' => ['employer' => 20, 'title' => 25, 'location' => 15, 'deadline' => 10, 'posted' => 5, 'url' => 5, 'text' => 20],
        // An employer name like these is an agency: the ad may not be theirs, so the name proves little and nothing is merged automatically.
        'agency_pattern' => '/\b(?:recruit\w*|staffing|manpower|headhunt\w*|talent|placement\w*|personnel|executive search|employment (?:agency|services)|(?:hr|human resources?) (?:consult\w*|solutions|services))\b/i',
    ],

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

    // Optional AI help for reading ads. Off unless AI_EXTRACTION_ENABLED=true. The key lives only in .env.
    // Prices change, so they are settings (US dollars per million tokens), not code; with none set, tokens are
    // recorded and the cost is left blank.
    'ai' => [
        'enabled' => (bool) env('AI_EXTRACTION_ENABLED', false),
        'provider' => env('AI_PROVIDER', 'gemini'),
        'timeout' => (int) env('AI_TIMEOUT_SECONDS', 30),
        'max_attempts' => (int) env('AI_MAX_ATTEMPTS', 3),
        'max_input_chars' => (int) env('AI_MAX_INPUT_CHARS', 12000),
        'daily_call_limit' => (int) env('AI_DAILY_CALL_LIMIT', 200),
        'daily_budget_usd' => (float) env('AI_DAILY_BUDGET_USD', 1.0),
        'price_input_per_million' => (float) env('AI_PRICE_INPUT_PER_MILLION', 0),
        'price_output_per_million' => (float) env('AI_PRICE_OUTPUT_PER_MILLION', 0),
        'gemini' => [
            'key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        ],
    ],
];
