<?php

namespace App\Collectors;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Enforces robots.txt in code (RFC 9309), so a disallowed URL is refused rather than
 * relying on anyone remembering. For example Go Zambia Jobs disallows /rss/.
 *
 *  - 2xx          -> parse and obey the rules
 *  - 4xx          -> no robots.txt exists, everything allowed
 *  - 5xx / error  -> unreachable, everything disallowed (retried after 15 minutes)
 *
 * Rules for our own token (ZamtrendsBot) win over the "*" group. The longest matching
 * pattern wins; on a tie, Allow wins.
 */
class RobotsGuard
{
    private const UNREACHABLE_RETRY_MINUTES = 15;

    public function allows(string $url): bool
    {
        $policy = $this->policy($url);

        return match ($policy['mode']) {
            'allow_all' => true,
            'disallow_all' => false,
            default => $this->matches($policy['rules'], $this->pathOf($url)),
        };
    }

    /** Crawl-delay in seconds requested by the host, or null. */
    public function crawlDelay(string $url): ?float
    {
        return $this->policy($url)['delay'] ?? null;
    }

    /** @return array{mode:string, rules:list<array{0:bool,1:string}>, delay:?float} */
    private function policy(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
        $port = parse_url($url, PHP_URL_PORT);
        // robots.txt belongs to one origin: scheme, host and port (a site on :8080 has its own file).
        $origin = "{$scheme}://{$host}".($port ? ":{$port}" : '');
        $key = 'robots:'.strtolower($origin);

        if ($cached = Cache::get($key)) {
            return $cached;
        }

        try {
            $response = Http::withUserAgent(UserAgent::make())
                ->timeout(config('job_intel.http_timeout'))
                ->get("{$origin}/robots.txt");
        } catch (Throwable) {
            return $this->remember($key, ['mode' => 'disallow_all', 'rules' => [], 'delay' => null], now()->addMinutes(self::UNREACHABLE_RETRY_MINUTES));
        }

        $ttl = now()->addHours(config('job_intel.robots_cache_hours'));

        if ($response->serverError()) {
            return $this->remember($key, ['mode' => 'disallow_all', 'rules' => [], 'delay' => null], now()->addMinutes(self::UNREACHABLE_RETRY_MINUTES));
        }

        if (! $response->successful()) {
            return $this->remember($key, ['mode' => 'allow_all', 'rules' => [], 'delay' => null], $ttl);
        }

        return $this->remember($key, $this->parse($response->body()), $ttl);
    }

    private function remember(string $key, array $policy, $ttl): array
    {
        Cache::put($key, $policy, $ttl);

        return $policy;
    }

    /** @return array{mode:string, rules:list<array{0:bool,1:string}>, delay:?float} */
    public function parse(string $body): array
    {
        $groups = [];
        $current = null;
        $lastWasAgent = false;

        foreach (preg_split('/\R/', $body) as $line) {
            $line = trim(preg_replace('/#.*/', '', $line));
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                if (! $lastWasAgent) {
                    $groups[] = ['agents' => [], 'rules' => [], 'delay' => null];
                    $current = array_key_last($groups);
                }
                $groups[$current]['agents'][] = strtolower($value);
                $lastWasAgent = true;

                continue;
            }

            $lastWasAgent = false;

            if ($current === null) {
                continue;
            }

            if ($field === 'allow' || $field === 'disallow') {
                if ($value !== '') { // an empty Disallow allows everything
                    $groups[$current]['rules'][] = [$field === 'allow', $value];
                }
            } elseif ($field === 'crawl-delay' && is_numeric($value)) {
                $groups[$current]['delay'] = (float) $value;
            }
        }

        $token = strtolower(UserAgent::TOKEN);
        $matching = array_filter($groups, fn ($g) => in_array($token, $g['agents'], true));
        if (! $matching) {
            $matching = array_filter($groups, fn ($g) => in_array('*', $g['agents'], true));
        }

        $rules = [];
        $delay = null;
        foreach ($matching as $g) {
            $rules = array_merge($rules, $g['rules']);
            $delay = $g['delay'] ?? $delay;
        }

        return ['mode' => $rules ? 'rules' : 'allow_all', 'rules' => $rules, 'delay' => $delay];
    }

    /** @param list<array{0:bool,1:string}> $rules */
    private function matches(array $rules, string $path): bool
    {
        $best = null;

        foreach ($rules as [$allow, $pattern]) {
            if (! $this->patternMatches($pattern, $path)) {
                continue;
            }

            $length = strlen($pattern);
            if ($best === null || $length > $best[1] || ($length === $best[1] && $allow)) {
                $best = [$allow, $length];
            }
        }

        return $best === null ? true : $best[0];
    }

    private function patternMatches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        $pattern = $anchored ? substr($pattern, 0, -1) : $pattern;
        $regex = '#^'.str_replace('\*', '.*', preg_quote($pattern, '#')).($anchored ? '$' : '').'#';

        return (bool) preg_match($regex, $path);
    }

    private function pathOf(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $query = parse_url($url, PHP_URL_QUERY);

        return $query !== null ? "{$path}?{$query}" : $path;
    }
}
