<?php

namespace App\Collectors;

use App\Collectors\Html\HtmlConfig;
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
     * @param  array<string, array{title:string,url:string,external_id:?string,content:string}>  $items
     */
    private function addDetails(Source $source, array $items, array $config, string $host): array
    {
        $known = RawJobPost::where('source_id', $source->id)->whereIn('source_url', array_keys($items))->pluck('source_url')->all();
        $budget = (int) $config['max_detail_requests'];

        foreach ($items as $url => $item) {
            if ($budget <= 0) {
                break;
            }
            if (in_array($url, $known, true)) {
                continue;
            }

            $budget--;

            try {
                $page = new HtmlPage($this->body($this->get($source, $url, 'text/html,application/xhtml+xml')));
            } catch (CollectionFailed $e) {
                $this->problem('ad_page', $url, $e->getMessage());

                continue;
            }

            $content = $this->readDetail($page, $config);
            if ($content !== null) {
                $items[$url]['content'] = $content;
            }
        }

        return $items;
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
