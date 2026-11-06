<?php

namespace App\Collectors;

use App\Collectors\Contracts\Collector;
use App\Models\RawJobPost;
use App\Models\Source;
use App\Models\SourceRun;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Rules every collector must follow (docs/job-intel/03-collection-rules.md).
 * They live here so an individual collector cannot skip them:
 *  - only runs for sources that are approved AND active
 *  - honest User-Agent with a contact address
 *  - response size cap
 *  - email addresses removed before storing
 *  - raw content not stored when the source has retain_raw = false
 *  - every attempt recorded as a source run; failures never throw out of collect()
 *
 * A concrete collector only implements fetch(): get items from the site.
 */
abstract class BaseCollector implements Collector
{
    /**
     * Fetch the site and return its ads. Throw CollectionFailed on expected failures.
     *
     * @return list<array{title:string,url:string,external_id:?string,content:string}>
     */
    abstract protected function fetch(Source $source): array;

    /** @var array<string, float> host => microtime of our last request, for throttling */
    private static array $lastRequestAt = [];

    public function collect(Source $source, string $trigger = 'scheduled'): SourceRun
    {
        $this->assertCollectable($source);

        $run = $this->execute($source, $trigger);

        // Success reschedules the source; failure backs off and eventually switches it off.
        app(SourceScheduler::class)->recordResult($source, $run);

        return $run;
    }

    /** Problems that did not stop the run (for example one page failed). A run with any is 'partial'. */
    protected array $warnings = [];

    /** True while check() is running: collectors should do the smallest possible look (one page, no detail pages). */
    protected bool $probing = false;

    private function execute(Source $source, string $trigger): SourceRun
    {
        $this->warnings = [];
        $this->probing = false;

        $run = SourceRun::create([
            'source_id' => $source->id,
            'started_at' => now(),
            'status' => 'running',
            'trigger' => $trigger,
        ]);

        try {
            $items = $this->fetch($source);
        } catch (CollectionFailed $e) {
            return $this->fail($run, $e->getMessage(), $e->httpErrors);
        } catch (Throwable $e) {
            return $this->fail($run, $e->getMessage());
        }

        [$new, $updated] = $this->ingest($source, $run, $items);

        $run->update([
            'finished_at' => now(),
            'status' => $this->warnings ? 'partial' : 'success',
            'items_found' => count($items),
            'items_new' => $new,
            'items_updated' => $updated,
            'error_message' => $this->warnings ? mb_substr(implode(' | ', $this->warnings), 0, 1000) : null,
        ]);

        $source->update(['last_collection_at' => now()]);

        return $run;
    }

    public function check(Source $source): AccessCheck
    {
        if ($source->access_status === 'restricted') {
            return new AccessCheck(false, null, 0, 0, 'Source is restricted; no requests are made.');
        }

        $this->assertConfigured($source);

        $start = hrtime(true);
        $this->probing = true;

        try {
            $items = $this->fetch($source);
        } catch (CollectionFailed $e) {
            return new AccessCheck(false, $e->httpStatus, $this->elapsed($start), 0, $e->getMessage());
        } catch (Throwable $e) {
            return new AccessCheck(false, null, $this->elapsed($start), 0, $e->getMessage());
        }

        return new AccessCheck(true, 200, $this->elapsed($start), count($items), 'Reachable and readable. Nothing was stored.');
    }

    public function configRules(): array
    {
        return [];
    }

    protected function assertCollectable(Source $source): void
    {
        if ($source->access_status !== 'approved' || ! $source->is_active) {
            throw new RuntimeException("Source '{$source->name}' is not approved and active; refusing to collect.");
        }

        $this->assertConfigured($source);
    }

    protected function assertConfigured(Source $source): void
    {
        if ($source->collection_method !== $this->method() || blank($source->job_listing_url)) {
            throw new RuntimeException("Source '{$source->name}' is not configured for {$this->method()} collection.");
        }

        if (blank(config('job_intel.collector_contact'))) {
            throw new RuntimeException('COLLECTOR_CONTACT is not set; the User-Agent must include a contact address.');
        }
    }

    protected function http(): PendingRequest
    {
        return Http::withUserAgent(UserAgent::make())
            ->timeout(config('job_intel.http_timeout'));
    }

    /**
     * The only way a collector should request a page: refuses URLs that robots.txt
     * disallows, and waits so we never exceed the source's minimum interval
     * (or the host's Crawl-delay, if larger).
     */
    protected function get(Source $source, string $url, string $accept = '*/*'): \Illuminate\Http\Client\Response
    {
        $robots = app(RobotsGuard::class);

        if (! $robots->allows($url)) {
            throw new CollectionFailed("robots.txt disallows {$url}; it was not fetched.");
        }

        $this->throttle($url, max((float) $source->min_interval_seconds, (float) ($robots->crawlDelay($url) ?? 0)));

        return $this->http()->accept($accept)->get($url);
    }

    private function throttle(string $url, float $minSeconds): void
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (config('job_intel.throttle') && isset(self::$lastRequestAt[$host])) {
            $wait = $minSeconds - (microtime(true) - self::$lastRequestAt[$host]);
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }
        }

        self::$lastRequestAt[$host] = microtime(true);
    }

    /** Body of a successful response, within the size cap; otherwise CollectionFailed. */
    protected function body(\Illuminate\Http\Client\Response $response): string
    {
        if (! $response->successful()) {
            throw new CollectionFailed("Site returned HTTP {$response->status()}", httpErrors: 1, httpStatus: $response->status());
        }

        $body = $response->body();

        if (strlen($body) > config('job_intel.max_response_bytes')) {
            throw new CollectionFailed('Response larger than the allowed size; not parsed.');
        }

        return $body;
    }

    /**
     * Store ads through the one shared path: email removal, retain_raw, hashing, versioning.
     *
     * @param  list<array{title:string,url:string,external_id:?string,content:string}>  $items
     * @return array{0:int,1:int} [new, updated]
     */
    protected function ingest(Source $source, SourceRun $run, array $items): array
    {
        $new = $updated = 0;

        foreach ($items as $item) {
            $result = $this->store($source, $run, $item);
            $new += $result === 'new' ? 1 : 0;
            $updated += $result === 'updated' ? 1 : 0;
        }

        return [$new, $updated];
    }

    /** @return 'new'|'updated'|'unchanged' */
    private function store(Source $source, SourceRun $run, array $item): string
    {
        $content = $this->stripEmails($item['content']);
        $hash = hash('sha256', $item['title']."\n".trim(preg_replace('/\s+/', ' ', $content)));

        $same = RawJobPost::where('source_id', $source->id)
            ->where('source_url', $item['url'])
            ->where('content_hash', $hash)
            ->first();

        if ($same) {
            $same->update(['last_seen_at' => now(), 'is_removed' => false]);

            return 'unchanged';
        }

        $hadEarlierVersion = RawJobPost::where('source_id', $source->id)
            ->where('source_url', $item['url'])
            ->exists();

        RawJobPost::create([
            'source_id' => $source->id,
            'source_run_id' => $run->id,
            'source_url' => $item['url'],
            'external_id' => $item['external_id'],
            'raw_title' => $item['title'],
            'raw_content' => ($source->retain_raw ?? true) ? $content : null,
            'raw_purged_at' => ($source->retain_raw ?? true) ? null : now(),
            'content_hash' => $hash,
            'http_status' => 200,
            'fetched_at' => now(),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        return $hadEarlierVersion ? 'updated' : 'new';
    }

    private function stripEmails(string $text): string
    {
        return preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email removed]', $text);
    }

    private function fail(SourceRun $run, string $message, int $httpErrors = 0): SourceRun
    {
        $run->update([
            'finished_at' => now(),
            'status' => 'failed',
            'http_errors' => $httpErrors,
            'error_message' => mb_substr($message, 0, 1000),
        ]);

        return $run;
    }

    private function elapsed(int $startNs): int
    {
        return (int) round((hrtime(true) - $startNs) / 1_000_000);
    }
}
