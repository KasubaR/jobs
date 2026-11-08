<?php

namespace App\Collectors\Api;

/**
 * Settings of a JSON-API source, stored in sources.collector_config['api'].
 * Only field names (dot paths), small numbers and the NAME of an environment variable: no code,
 * and never the API key itself.
 */
class ApiConfig
{
    public const DEFAULTS = [
        'items_path' => null,       // where the list of ads is in the response, e.g. data.jobs (empty = the response is the list)
        'title' => null,            // path inside one ad (required)
        'url' => null,              // path inside one ad: the link to the ad ...
        'url_template' => null,     // ... or a link built from the ad's id, e.g. https://example.com/jobs/{id}
        'id' => null,               // path inside one ad: its id (optional, needed for url_template)
        'content' => null,          // path inside one ad: the ad text (empty = the title is stored)
        'employer' => null,         // facts, all optional
        'location' => null,
        'date_posted' => null,
        'valid_through' => null,
        'employment_type' => null,
        'page_param' => null,       // query parameter that selects the page, e.g. page (needed for more than one page)
        'max_pages' => 1,           // pages per run
        'max_items' => 50,          // ads per run
        'key_name' => null,         // NAME of the .env setting holding the API key, e.g. COLLECTOR_KEY_EXAMPLE
        'key_header' => 'Authorization', // header the key is sent in ("Authorization" sends "Bearer <key>")
    ];

    public const PATH_KEYS = ['items_path', 'title', 'url', 'id', 'content', 'employer', 'location', 'date_posted', 'valid_through', 'employment_type'];

    public const MAX_PAGES = 5;

    public const MAX_ITEMS = 100;

    /** Only settings with this prefix can ever be used as a key: a source can never name APP_KEY or a database password. */
    public const KEY_PREFIX = 'COLLECTOR_KEY_';

    /** Headers a key may never be sent in (they would break or hijack the request). */
    private const FORBIDDEN_HEADERS = ['host', 'user-agent', 'cookie', 'content-length', 'content-type', 'accept', 'transfer-encoding', 'connection', 'referer', 'origin'];

    /** @return array<string, mixed> the stored settings merged over the defaults */
    public static function resolve(?array $collectorConfig): array
    {
        $stored = array_filter($collectorConfig['api'] ?? [], fn ($v) => $v !== null && $v !== '');

        return array_merge(self::DEFAULTS, $stored);
    }

    /** @return list<string> human-readable problems (empty = fine) */
    public static function validate(array $api): array
    {
        $config = array_merge(self::DEFAULTS, array_filter($api, fn ($v) => $v !== null && $v !== ''));
        $problems = [];

        if (blank($config['title'])) {
            $problems[] = 'The "title" field is required: it says where an ad\'s title is.';
        }

        if (blank($config['url']) && blank($config['url_template'])) {
            $problems[] = 'Say how to find each ad\'s link: a "link" field, or a "link template" built from the ad\'s id.';
        }

        foreach (self::PATH_KEYS as $key) {
            if (! blank($config[$key]) && ! preg_match('/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*$/', (string) $config[$key])) {
                $problems[] = "The \"{$key}\" field name must be letters, digits, _ or -, joined by dots (for example data.title).";
            }
            if (! blank($config[$key]) && strlen((string) $config[$key]) > 100) {
                $problems[] = "The \"{$key}\" field name is longer than 100 characters.";
            }
        }

        if (! blank($config['url_template'])) {
            $template = (string) $config['url_template'];
            if (! preg_match('~^https?://[^\s{}/]+[^\s{}]*\{id\}[^\s{}]*$~i', $template) || strlen($template) > 300) {
                $problems[] = 'The link template must be a full web address containing {id}, for example https://example.com/jobs/{id}.';
            }
            if (blank($config['id'])) {
                $problems[] = 'A link template needs the "id" field.';
            }
        }

        $limits = ['max_pages' => [1, self::MAX_PAGES], 'max_items' => [1, self::MAX_ITEMS]];
        foreach ($limits as $key => [$min, $max]) {
            $value = $config[$key];
            if (! is_numeric($value) || (int) $value != $value || $value < $min || $value > $max) {
                $problems[] = "{$key} must be a whole number from {$min} to {$max}.";
            }
        }

        if (! blank($config['page_param']) && ! preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,29}$/', (string) $config['page_param'])) {
            $problems[] = 'The page parameter must be a plain name such as page.';
        }

        if ((int) $config['max_pages'] > 1 && blank($config['page_param'])) {
            $problems[] = 'More than one page needs a "page parameter".';
        }

        if (! blank($config['key_name']) && ! preg_match('/^'.self::KEY_PREFIX.'[A-Z0-9_]{1,40}$/', (string) $config['key_name'])) {
            $problems[] = 'The key name must start with '.self::KEY_PREFIX.' and use capital letters, digits and _ (the key itself goes in the .env file, not here).';
        }

        $header = (string) $config['key_header'];
        if (! preg_match('/^[A-Za-z][A-Za-z0-9-]{0,39}$/', $header) || in_array(strtolower($header), self::FORBIDDEN_HEADERS, true)) {
            $problems[] = 'The key header must be a plain header name such as Authorization or X-API-Key.';
        }

        return $problems;
    }
}
