<?php

namespace App\Collectors;

use App\Collectors\Api\ApiConfig;
use App\Collectors\Api\JsonPath;
use App\Models\Source;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

/**
 * Collects ads from a site's official JSON API, driven entirely by sources.collector_config['api']
 * (see ApiConfig): where the list is, and which field holds the title, link and text.
 *
 * Safety rules, on top of everything BaseCollector enforces (approved + active, robots.txt, throttling,
 * size cap, honest User-Agent, email removal, retention):
 *  - the API key is read from the server's .env at run time (a setting named COLLECTOR_KEY_...). It is never
 *    stored in the database, shown in the app, logged, or put in a URL: it travels in a request header
 *  - a key is only sent over https, and redirects are never followed (so it cannot be handed to another host)
 *  - at most max_pages requests per run (and one for "Test access")
 *  - an ad's link must be on the source's own site (the API's host or the website's host)
 */
class ApiJsonCollector extends BaseCollector
{
    public function method(): string
    {
        return 'api';
    }

    public function configRules(): array
    {
        return ['collector_config.api' => ['nullable', 'array']];
    }

    protected function http(): PendingRequest
    {
        return parent::http()->withoutRedirecting();
    }

    protected function assertConfigured(Source $source): void
    {
        parent::assertConfigured($source);

        if ($problems = ApiConfig::validate($source->collector_config['api'] ?? [])) {
            throw new RuntimeException("Source '{$source->name}' has incomplete API settings: ".implode(' ', $problems));
        }

        $config = ApiConfig::resolve($source->collector_config);

        if (filled($config['key_name'])) {
            if (strtolower((string) parse_url($source->job_listing_url, PHP_URL_SCHEME)) !== 'https') {
                throw new RuntimeException("Source '{$source->name}' uses an API key, so its API address must start with https://.");
            }

            $this->key($config); // throws when the .env setting is missing
        }
    }

    /** @return string the API key from .env, or '' when this source uses none */
    private function key(array $config): string
    {
        if (blank($config['key_name'])) {
            return '';
        }

        $key = config('job_intel.api_keys')[$config['key_name']] ?? null;

        if (! is_string($key) || $key === '') {
            throw new RuntimeException("The API key is not set: add {$config['key_name']}=... to the server's .env file.");
        }

        return $key;
    }

    /** @return array<string, string> */
    private function authHeaders(array $config): array
    {
        $key = $this->key($config);

        if ($key === '') {
            return [];
        }

        $header = (string) $config['key_header'];

        return [$header => strcasecmp($header, 'Authorization') === 0 ? "Bearer {$key}" : $key];
    }

    protected function fetch(Source $source): array
    {
        $config = ApiConfig::resolve($source->collector_config);
        $headers = $this->authHeaders($config);
        $hosts = array_filter(array_unique([
            strtolower((string) parse_url($source->job_listing_url, PHP_URL_HOST)),
            strtolower((string) parse_url($source->website_url, PHP_URL_HOST)),
        ]));
        $maxPages = $this->probing ? 1 : (int) $config['max_pages'];

        $items = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $url = $page === 1 ? $source->job_listing_url : $this->pageUrl($source->job_listing_url, (string) $config['page_param'], $page);

            try {
                $data = $this->decode($this->body($this->get($source, $url, 'application/json', $headers)));
            } catch (CollectionFailed $e) {
                if ($page === 1) {
                    throw $this->withHint($e, $config);
                }
                $this->problem('listing', $url, "Page {$page}: {$e->getMessage()}");
                break;
            }

            $list = JsonPath::get($data, $config['items_path']);

            if (! is_array($list) || ! array_is_list($list)) {
                if ($page === 1) {
                    throw new CollectionFailed('The response has no list of ads at "'.($config['items_path'] ?: '(top level)').'". The API may have changed, so the settings need updating.');
                }
                $this->problem('listing', $url, "Page {$page}: no list of ads at \"{$config['items_path']}\".");
                break;
            }

            if ($list === []) {
                break; // past the last page (or no ads right now)
            }

            $usable = 0;
            foreach ($list as $index => $row) {
                $item = is_array($row) ? $this->readItem($row, $config, $source, $hosts) : null;

                if ($item === null) {
                    $this->problem('listing', $url, 'Item '.($index + 1).' on page '.$page.' has no usable title and link; it was skipped.');

                    continue;
                }

                $usable++;
                $items[$item['url']] ??= $item;

                if (count($items) >= (int) $config['max_items']) {
                    break 2;
                }
            }

            if ($page === 1 && $usable === 0) {
                throw new CollectionFailed('None of the '.count($list).' items had a usable title and link. The field names in the settings may be wrong, or the API may have changed.');
            }
        }

        return array_values($items);
    }

    private function decode(string $body): array
    {
        $data = json_decode(ltrim($body, "\xEF\xBB\xBF"), true, 32);

        if (! is_array($data)) {
            throw new CollectionFailed('The response was not readable JSON.');
        }

        return $data;
    }

    /** A 401/403 from an API almost always means the key; say so without ever printing it. */
    private function withHint(CollectionFailed $e, array $config): CollectionFailed
    {
        if (! in_array($e->httpStatus, [401, 403], true)) {
            return $e;
        }

        $hint = filled($config['key_name'])
            ? ' The API refused the request: check that the key in '.$config['key_name'].' is correct and still valid.'
            : ' The API refused the request: it may need a key (set one in the source and in the .env file).';

        return new CollectionFailed($e->getMessage().'.'.$hint, $e->httpErrors, $e->httpStatus);
    }

    private function pageUrl(string $url, string $param, int $page): string
    {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        $query[$param] = $page;

        return ($parts['scheme'] ?? 'https').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '/').'?'.http_build_query($query);
    }

    /**
     * @param  list<string>  $hosts
     * @return array{title:string,url:string,external_id:?string,content:string,structured:?array}|null
     */
    private function readItem(array $row, array $config, Source $source, array $hosts): ?array
    {
        $title = JsonPath::text(JsonPath::get($row, $config['title']));
        $id = filled($config['id']) ? JsonPath::text(JsonPath::get($row, $config['id'])) : null;

        $link = filled($config['url']) ? JsonPath::text(JsonPath::get($row, $config['url'])) : null;
        if ($link === null && filled($config['url_template']) && $id !== null) {
            $link = str_replace('{id}', rawurlencode($id), (string) $config['url_template']);
        }

        $url = $link === null ? null : $this->absolute($link, $source->job_listing_url, $hosts);

        if ($title === null || $url === null) {
            return null;
        }

        $content = filled($config['content']) ? JsonPath::text(JsonPath::get($row, $config['content'])) : null;

        return [
            'title' => mb_substr($title, 0, 500),
            'url' => $url,
            'external_id' => $id !== null ? mb_substr($id, 0, 190) : null,
            'content' => $content ?? $title,
            'structured' => $this->facts($row, $config, $id),
        ];
    }

    /** Facts the API gives about the ad (kept apart from the ad text). Same keys as schema.org data from ad pages. */
    private function facts(array $row, array $config, ?string $id): ?array
    {
        $text = fn (string $key) => filled($config[$key]) ? JsonPath::text(JsonPath::get($row, $config[$key])) : null;
        $date = fn (?string $v) => $v === null ? null : (preg_match('/^\d{4}-\d{2}-\d{2}/', $v) ? substr($v, 0, 10) : mb_substr($v, 0, 100));

        $facts = array_filter([
            'title' => $text('title'),
            'employer' => ($v = $text('employer')) ? mb_substr($v, 0, 200) : null,
            'locations' => ($v = $text('location')) ? [['locality' => mb_substr($v, 0, 200)]] : null,
            'date_posted' => $date($text('date_posted')),
            'valid_through' => $date($text('valid_through')),
            'employment_type' => ($v = $text('employment_type')) ? [mb_substr($v, 0, 60)] : null,
            'identifier' => $id,
        ], fn ($v) => $v !== null);

        return $facts ?: null;
    }

    /** An absolute http(s) URL on the source's own site, or null. */
    private function absolute(string $href, string $listingUrl, array $hosts): ?string
    {
        $href = trim($href);

        if (preg_match('~^https?://~i', $href)) {
            $absolute = $href;
        } elseif (str_starts_with($href, '/') && ! str_starts_with($href, '//')) {
            $base = parse_url($listingUrl);
            $absolute = $base['scheme'].'://'.$base['host'].(isset($base['port']) ? ':'.$base['port'] : '').$href;
        } else {
            return null;
        }

        $absolute = preg_replace('~#.*$~', '', $absolute);

        if (! in_array(strtolower((string) parse_url($absolute, PHP_URL_HOST)), $hosts, true) || strlen($absolute) > 500) {
            return null;
        }

        return $absolute;
    }
}
