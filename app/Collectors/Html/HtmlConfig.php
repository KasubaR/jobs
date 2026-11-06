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
        'max_pages' => 1,          // listing pages per run
        'max_items' => 50,         // ads per run
        'max_detail_requests' => 20, // ad pages opened per run
    ];

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

        if (blank($config['item'])) {
            $problems[] = 'The "item" selector is required: it says what one ad looks like on a listing page.';
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

        if ($config['max_pages'] > 1 && blank($config['next'])) {
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
