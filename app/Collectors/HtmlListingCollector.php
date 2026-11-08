<?php

namespace App\Collectors;

use App\Collectors\Html\HtmlConfig;
use App\Collectors\Html\JobPostingParser;
use App\Collectors\Html\HtmlPage;
use App\Models\RawJobPost;
use App\Models\Source;
use DOMNode;
use RuntimeException;

/**
 * Collects ads from a site's public HTML pages, driven entirely by CSS selectors in
 * sources.collector_config['html'] (see HtmlConfig). Use it as-is for a plain listing site,
 * or extend it and override cards() / readDetail() for a site that needs its own logic.
 *
 * Safety limits, all enforced here and not configurable past the caps in HtmlConfig:
 *  - every request goes through BaseCollector::get(): robots.txt check and throttling
 *  - only links on the source's own host are followed
 *  - at most max_pages listing pages and max_detail_requests ad pages per run
 *  - ad pages are opened only for ads we have not stored yet
 *  - check() looks at the first listing page only
 */
class HtmlListingCollector extends BaseCollector
{
    public function method(): string
    {
        return 'html';
    }

    public function configRules(): array
    {
        return ['collector_config.html' => ['nullable', 'array']];
    }

    protected function assertConfigured(Source $source): void
    {
        parent::assertConfigured($source);

        if ($problems = HtmlConfig::validate($source->collector_config['html'] ?? [])) {
            throw new RuntimeException("Source '{$source->name}' has incomplete HTML settings: ".implode(' ', $problems));
        }
    }

    protected function fetch(Source $source): array
    {
        $config = HtmlConfig::resolve($source->collector_config);

        if (filled($config['sitemap'])) {
            return $this->fetchFromSitemap($source, $config);
        }

        $host = strtolower((string) parse_url($source->job_listing_url, PHP_URL_HOST));
        $maxPages = $this->probing ? 1 : (int) $config['max_pages'];

        $items = [];
        $url = $source->job_listing_url;
        $visited = [];

        for ($page = 1; $page <= $maxPages && $url !== null && ! isset($visited[$url]); $page++) {
            $visited[$url] = true;

            try {
                $html = $this->body($this->get($source, $url, 'text/html,application/xhtml+xml'));
            } catch (CollectionFailed $e) {
                if ($page === 1) {
                    throw $e;
                }
                $this->problem('listing', $url, "Page {$page}: {$e->getMessage()}");
                break;
            }

            $listing = new HtmlPage($html);
            $found = 0;

            foreach ($this->cards($listing, $config) as $card) {
                $item = $this->readCard($listing, $card, $config, $url, $host);
                if ($item === null) {
                    continue;
                }
                $found++;
                $items[$item['url']] ??= $item;

                if (count($items) >= (int) $config['max_items']) {
                    break 2;
                }
            }

            if ($page === 1 && $found === 0) {
                throw new CollectionFailed("No ads matched the \"item\" selector ({$config['item']}). The site's layout may have changed, so this collector needs updating.");
            }

            $url = $this->nextUrl($listing, $config, $url, $host);
        }

        if (! $this->probing && $config['fetch_detail']) {
            $items = $this->addDetails($source, $items, $config, $host);
        }

        return array_values($items);
    }

    /**
     * Sitemap mode: the site's own sitemap lists the ads' addresses, newest first. Only new ads' pages are opened
     * (one request each, up to max_detail_requests per run); ads already stored are just marked "seen again".
     * Addresses left over when the budget runs out are picked up by the next runs.
     */
    private function fetchFromSitemap(Source $source, array $config): array
    {
        $hosts = array_values(array_filter(array_unique([
            strtolower((string) parse_url($source->job_listing_url, PHP_URL_HOST)),
            strtolower((string) parse_url($source->website_url, PHP_URL_HOST)),
        ])));

        if (! in_array(strtolower((string) parse_url((string) $config['sitemap'], PHP_URL_HOST)), $hosts, true)) {
            throw new CollectionFailed('The sitemap must be on the source\'s own site ('.implode(' or ', $hosts).').');
        }

        $entries = $this->sitemapEntries($source, $config, (string) $config['sitemap'], $hosts);

        $wanted = [];
        foreach ($entries as $entry) {
            $url = preg_replace('~#.*$~', '', $entry['url']);

            if (! preg_match('~^https?://~i', $url) || strlen($url) > 500
                || ! in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), $hosts, true)
                || ! $this->pathMatches((string) parse_url($url, PHP_URL_PATH), $config['sitemap_match'])) {
                continue;
            }

            $wanted[$url] ??= ['url' => $url, 'lastmod' => $entry['lastmod']];
        }

        if ($wanted === []) {
            throw new CollectionFailed('No addresses in the sitemap matched'.(filled($config['sitemap_match']) ? " the ad address pattern ({$config['sitemap_match']})" : '').'. The site\'s sitemap may have changed, so these settings need updating.');
        }

        $items = [];
        foreach (array_slice($this->newestFirst(array_values($wanted)), 0, (int) $config['max_items']) as $entry) {
            // The title and text come from the ad's own page; until then this is only a placeholder.
            $items[$entry['url']] = ['title' => $this->placeholderTitle($entry['url']), 'url' => $entry['url'], 'external_id' => null, 'content' => '', 'needs_title' => true];
        }

        return array_values($this->probing ? $items : $this->addDetails($source, $items, $config, ''));
    }

    /**
     * Ad addresses listed by the sitemap. A sitemap that is an index of other sitemaps is followed one level,
     * newest first, for at most max_pages files (one when only testing access).
     *
     * @return list<array{url:string,lastmod:?int}>
     */
    private function sitemapEntries(Source $source, array $config, string $url, array $hosts): array
    {
        $root = $this->readSitemap($this->body($this->get($source, $url, 'application/xml, text/xml;q=0.9')));

        if ($root['type'] === 'urlset') {
            return $root['entries'];
        }

        $children = array_filter($root['entries'], fn ($e) => in_array(strtolower((string) parse_url($e['url'], PHP_URL_HOST)), $hosts, true)
            && ! str_ends_with(strtolower((string) parse_url($e['url'], PHP_URL_PATH)), '.gz')); // compressed sitemaps are not read
        $children = array_slice($this->newestFirst(array_values($children)), 0, $this->probing ? 1 : (int) $config['max_pages']);

        $entries = [];
        $last = null;

        foreach ($children as $child) {
            try {
                $sub = $this->readSitemap($this->body($this->get($source, $child['url'], 'application/xml, text/xml;q=0.9')));
            } catch (CollectionFailed $e) {
                $last = $e;
                $this->problem('listing', $child['url'], $e->getMessage());

                continue;
            }

            if ($sub['type'] === 'urlset') {
                array_push($entries, ...$sub['entries']);
            }
        }

        if ($entries === [] && $last) {
            throw $last; // every sitemap file failed
        }

        return $entries;
    }

    /** @return array{type:'urlset'|'index',entries:list<array{url:string,lastmod:?int}>} */
    private function readSitemap(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, options: LIBXML_NONET | LIBXML_NOCDATA);
        libxml_use_internal_errors($previous);

        if ($doc === false || ! in_array($doc->getName(), ['urlset', 'sitemapindex'], true)) {
            throw new CollectionFailed('The sitemap was not readable.');
        }

        $index = $doc->getName() === 'sitemapindex';
        $entries = [];

        foreach ($index ? $doc->sitemap : $doc->url as $node) {
            $loc = trim((string) $node->loc);
            $time = trim((string) $node->lastmod) !== '' ? strtotime((string) $node->lastmod) : false;

            if ($loc !== '') {
                $entries[] = ['url' => $loc, 'lastmod' => $time === false ? null : $time];
            }
        }

        return ['type' => $index ? 'index' : 'urlset', 'entries' => $entries];
    }

    /**
     * Dated entries first, newest to oldest; undated ones after them in the order given.
     *
     * @param  list<array{url:string,lastmod:?int}>  $entries
     * @return list<array{url:string,lastmod:?int}>
     */
    private function newestFirst(array $entries): array
    {
        usort($entries, fn ($a, $b) => match (true) {
            $a['lastmod'] !== null && $b['lastmod'] !== null => $b['lastmod'] <=> $a['lastmod'],
            $a['lastmod'] !== null => -1,
            $b['lastmod'] !== null => 1,
            default => 0,
        });

        return $entries;
    }

    /** Pattern from the settings: matches from the start of the path; * is any characters, # is one digit. No regular expressions. */
    private function pathMatches(string $path, ?string $pattern): bool
    {
        if (blank($pattern)) {
            return true;
        }

        $regex = '';
        foreach (str_split((string) $pattern) as $char) {
            $regex .= match ($char) {
                '*' => '.*',
                '#' => '[0-9]',
                default => preg_quote($char, '~'),
            };
        }

        return (bool) preg_match('~^'.$regex.'~', $path);
    }

    private function placeholderTitle(string $url): string
    {
        $slug = basename((string) parse_url($url, PHP_URL_PATH));
        $slug = trim(preg_replace('/^\d+-?/', '', str_replace(['-', '_'], ' ', $slug)));

        return mb_substr($slug !== '' ? ucfirst($slug) : $url, 0, 500);
    }

    /** The ad's title from its own page: the "title" selector if set, else its job data, else the first heading, else the page title. */
    private function readTitle(HtmlPage $page, array $config, ?string $structuredTitle): ?string
    {
        $candidates = [];

        if (filled($config['title']) && ($node = $page->first($config['title']))) {
            $candidates[] = $page->text($node);
        }
        $candidates[] = $structuredTitle;
        foreach (['h1', 'title'] as $selector) {
            if ($node = $page->first($selector)) {
                $candidates[] = $page->text($node);
            }
        }

        foreach ($candidates as $title) {
            $title = trim((string) $title);
            if ($title !== '') {
                return mb_substr($title, 0, 500);
            }
        }

        return null;
    }

    /** @return list<DOMNode> one node per ad on a listing page */
    protected function cards(HtmlPage $listing, array $config): array
    {
        return $listing->select($config['item']);
    }

    /** @return array{title:string,url:string,external_id:?string,content:string}|null */
    private function readCard(HtmlPage $listing, DOMNode $card, array $config, string $pageUrl, string $host): ?array
    {
        $link = $listing->first($config['link'], $card);
        $href = $link?->attributes?->getNamedItem('href')?->nodeValue;
        $url = $href ? $this->absolute($href, $pageUrl, $host) : null;

        if ($url === null) {
            return null; // no usable link, or it leaves the site
        }

        $titleNode = $config['title'] ? $listing->first($config['title'], $card) : $link;
        $title = $listing->text($titleNode);

        if ($title === '') {
            return null;
        }

        return [
            'title' => $title,
            'url' => $url,
            'external_id' => null,
            'content' => $listing->html($card),
        ];
    }

    private function nextUrl(HtmlPage $listing, array $config, string $pageUrl, string $host): ?string
    {
        if (blank($config['next'])) {
            return null;
        }

        $href = $listing->first($config['next'])?->attributes?->getNamedItem('href')?->nodeValue;

        return $href ? $this->absolute($href, $pageUrl, $host) : null;
    }

    /**
     * Replace card text with the ad's own page for ads we do not have yet.
     *
     * Ads we already have are marked "seen only": the full text was read when they were first found,
     * and this run only has the shorter listing card, which must not replace it. (An ad whose own page
     * could not be read at the time is not retried automatically.)
     *
     * @param  array<string, array{title:string,url:string,external_id:?string,content:string}>  $items
     */
    private function addDetails(Source $source, array $items, array $config, string $host): array
    {
        $known = RawJobPost::where('source_id', $source->id)->whereIn('source_url', array_keys($items))->pluck('raw_title', 'source_url')->all();
        $budget = (int) $config['max_detail_requests'];
        $readStructured = (bool) $config['structured_data'];

        foreach ($items as $url => $item) {
            if (array_key_exists($url, $known)) {
                $items[$url]['seen_only'] = true;

                if (! empty($item['needs_title'])) {
                    $items[$url]['title'] = $known[$url] ?: $item['title']; // sitemap mode: the title was read when the ad was first stored
                    unset($items[$url]['needs_title']);
                }

                continue;
            }
            if ($budget <= 0) {
                continue;
            }

            $budget--;

            try {
                $html = $this->body($this->get($source, $url, 'text/html,application/xhtml+xml'));
                $page = new HtmlPage($html);
            } catch (CollectionFailed $e) {
                $this->problem('ad_page', $url, $e->getMessage());

                continue;
            }

            $content = $this->readDetail($page, $config);

            // Job data the page publishes about itself (schema.org JobPosting): facts are kept apart from the text.
            $posting = $readStructured ? app(JobPostingParser::class)->parse($html) : null;
            if ($posting) {
                $items[$url]['structured'] = $posting['facts'] ?: null;
                $content ??= $posting['description']; // no text selector (or no match): use the page's own description
            }

            if ($content !== null) {
                $items[$url]['content'] = $content;
            }

            if (! empty($item['needs_title'])) {
                $title = $this->readTitle($page, $config, $posting['facts']['title'] ?? null);

                if ($title === null) {
                    $this->problem('ad_page', $url, 'No title could be read from this page, so it was not stored.');
                    unset($items[$url]);

                    continue;
                }

                $items[$url]['title'] = $title;
                $items[$url]['content'] = $items[$url]['content'] !== '' ? $items[$url]['content'] : $title;
                unset($items[$url]['needs_title']);
            }
        }

        // Sitemap mode: an ad whose own page was not read (budget used up, or the page failed) is not stored yet;
        // a later run picks it up. Nothing is ever stored under a placeholder title.
        return array_filter($items, fn ($item) => empty($item['needs_title']));
    }

    /** The ad's full text from its own page, or null to keep the listing card. */
    protected function readDetail(HtmlPage $page, array $config): ?string
    {
        if (blank($config['content'])) {
            return null;
        }

        $node = $page->first($config['content']);

        return $node ? $page->html($node) : null;
    }

    /** An absolute http(s) URL on the source's own host, or null. */
    private function absolute(string $href, string $pageUrl, string $host): ?string
    {
        $href = trim($href);

        if ($href === '' || str_starts_with($href, '#') || preg_match('~^(javascript|mailto|tel):~i', $href)) {
            return null;
        }

        if (preg_match('~^https?://~i', $href)) {
            $absolute = $href;
        } elseif (str_starts_with($href, '//')) {
            $absolute = parse_url($pageUrl, PHP_URL_SCHEME).':'.$href;
        } else {
            $base = parse_url($pageUrl);
            $origin = $base['scheme'].'://'.$base['host'].(isset($base['port']) ? ':'.$base['port'] : '');

            if (str_starts_with($href, '/')) {
                $absolute = $origin.$href;
            } else {
                $dir = rtrim(substr($base['path'] ?? '/', 0, (int) strrpos($base['path'] ?? '/', '/') + 1), '/');
                $absolute = $origin.$dir.'/'.$href;
            }
        }

        $absolute = preg_replace('~#.*$~', '', $absolute);

        if (strtolower((string) parse_url($absolute, PHP_URL_HOST)) !== $host || strlen($absolute) > 500) {
            return null;
        }

        return $absolute;
    }
}
