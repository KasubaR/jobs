<?php

namespace App\Collectors\Html;

use Symfony\Component\CssSelector\CssSelectorConverter;
use Throwable;

/**
 * Settings of a public-HTML source, stored in sources.collector_config['html'].
 * Only CSS selectors and small numbers: no code, no regular expressions.
 */
class HtmlConfig
{
    public const DEFAULTS = [
        'item' => null,            // CSS selector: one match per ad on a listing page (required)
        'link' => 'a',             // CSS selector inside an item: the link to the ad
        'title' => null,           // CSS selector inside an item; the link text is used when empty
        'content' => null,         // CSS selector on the ad's own page; the listing card is used when empty
        'next' => null,            // CSS selector: the "next page" link on a listing page
        'fetch_detail' => false,   // open each NEW ad's own page to read its full text
        'structured_data' => false, // on each ad page, also read the job data it publishes about itself (schema.org JobPosting)
        'max_pages' => 1,          // listing pages per run
        'max_items' => 50,         // ads per run
        'max_detail_requests' => 20, // ad pages opened per run
        'sitemap' => null,         // address of the site's sitemap.xml: find ads there instead of on listing pages (see below)
        'sitemap_match' => null,   // which addresses in the sitemap are ads, e.g. /jobs/#* (* = anything, # = one digit)
    ];

    // Sitemap mode: the ads' addresses come from the sitemap, newest first (by its lastmod dates), and every new ad's
    // own page is opened to read its title and text. "item", "link" and "next" are not used. "title" (optional) is then
    // a selector on the ad page. "max_pages" is how many sitemap files are read when the sitemap is an index of several.

    public const MAX_PAGES = 5;

    public const MAX_ITEMS = 100;

    public const MAX_DETAIL_REQUESTS = 50;

    /** @return array<string, mixed> the stored settings merged over the defaults */
    public static function resolve(?array $collectorConfig): array
    {
        $stored = array_filter($collectorConfig['html'] ?? [], fn ($v) => $v !== null && $v !== '');

        return array_merge(self::DEFAULTS, $stored);
    }

    /** @return list<string> human-readable problems (empty = fine) */
    public static function validate(array $html): array
    {
        $config = array_merge(self::DEFAULTS, array_filter($html, fn ($v) => $v !== null && $v !== ''));
        $problems = [];

        $sitemap = filled($config['sitemap']);

        if (! $sitemap && blank($config['item'])) {
            $problems[] = 'The "item" selector is required: it says what one ad looks like on a listing page.';
        }

        if ($sitemap) {
            if (! preg_match('~^https?://[^\s]+$~i', (string) $config['sitemap']) || strlen((string) $config['sitemap']) > 300) {
                $problems[] = 'The sitemap must be a full web address, for example https://example.com/sitemap.xml.';
            }
            if (! $config['fetch_detail']) {
                $problems[] = 'A sitemap only lists addresses, so "Open each new ad\'s own page" must be on.';
            }
            if (blank($config['content']) && ! $config['structured_data']) {
                $problems[] = 'With a sitemap the ad text comes from the ad page: set the "Full ad text" selector, or turn on reading its job data.';
            }
        }

        if (filled($config['sitemap_match']) && (! preg_match('~^/[A-Za-z0-9/_.*#%-]*$~', (string) $config['sitemap_match']) || strlen((string) $config['sitemap_match']) > 150)) {
            $problems[] = 'The ad address pattern must start with / and use only letters, digits, / _ - . and the wildcards * (anything) and # (one digit).';
        }

        if (filled($config['sitemap_match']) && ! $sitemap) {
            $problems[] = 'An ad address pattern only works together with a sitemap address.';
        }

        foreach (['item', 'link', 'title', 'content', 'next'] as $key) {
            if (! blank($config[$key]) && ($problem = self::selectorProblem((string) $config[$key]))) {
                $problems[] = "The \"{$key}\" selector: {$problem}";
            }
        }

        $limits = [
            'max_pages' => [1, self::MAX_PAGES],
            'max_items' => [1, self::MAX_ITEMS],
            'max_detail_requests' => [0, self::MAX_DETAIL_REQUESTS],
        ];
        foreach ($limits as $key => [$min, $max]) {
            $value = $config[$key];
            if (! is_numeric($value) || (int) $value != $value || $value < $min || $value > $max) {
                $problems[] = "{$key} must be a whole number from {$min} to {$max}.";
            }
        }

        if ($config['structured_data'] && ! $config['fetch_detail']) {
            $problems[] = 'Structured data is read from each ad\'s own page, so "Open each new ad\'s own page" must be on.';
        }

        if (! $sitemap && $config['max_pages'] > 1 && blank($config['next'])) {
            $problems[] = 'More than one page needs a "next" selector.';
        }

        return $problems;
    }

    public static function selectorProblem(string $selector): ?string
    {
        if (strlen($selector) > 200) {
            return 'is longer than 200 characters.';
        }

        try {
            (new CssSelectorConverter)->toXPath($selector);
        } catch (Throwable) {
            return 'is not a valid CSS selector.';
        }

        return null;
    }
}
