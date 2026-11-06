<?php

return [
    // The only account allowed into the panel. Leave unset to lock everyone out.
    'admin_email' => env('ADMIN_EMAIL'),

    // Included in the collector's User-Agent so site owners can reach us.
    // Collection refuses to run until this is set.
    'collector_contact' => env('COLLECTOR_CONTACT'),

    // Raw ad text is cleared after this many days (structured data is kept).
    'raw_retention_days' => (int) env('RAW_RETENTION_DAYS', 90),

    // Seconds to wait for a source before giving up.
    'http_timeout' => 20,

    // Refuse feeds bigger than this many bytes.
    'max_response_bytes' => 5 * 1024 * 1024,
];
