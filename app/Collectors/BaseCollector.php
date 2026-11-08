<?php

namespace App\Collectors;

use App\Collectors\Contracts\Collector;
use App\Events\RawJobPostStored;
use App\Models\RawJobPost;
use App\Models\Source;
use App\Models\SourceRun;
use App\Models\SourceRunError;
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

    /**
     * Problems that did not stop the run (one page failed, one ad could not be stored).
     * Each is saved as a source_run_errors row; a run with any is 'partial'.
     *
     * @var list<array{stage:string,url:?string,message:string}>
     */
    protected array $problems = [];

    /** At most this many error rows are saved per run, so a broken site cannot flood the table. */
    private const MAX_ERROR_ROWS = 200;

    /** Stages whose problems mean "an ad we found could not be collected". */
    private const AD_STAGES = ['ad_page', 'store'];

    /** True while check() is running: collectors should do the smallest possible look (one page, no detail pages). */
    protected bool $probing = false;

    /** Note something that went wrong without stopping the run. */
    protected function problem(string $stage, ?string $url, string $message): void
    {
        $this->problems[] = ['stage' => $stage, 'url' => $url, 'message' => $message];
    }

    private function execute(Source $source, string $trigger): SourceRun
    {
        $this->problems = [];
        $this->probing = false;

        // Counts start at zero explicitly, so the returned run is complete even when it fails early.
        $run = SourceRun::create([
            'source_id' => $source->id,
            'started_at' => now(),
            'status' => 'running',
            'trigger' => $trigger,
            'items_found' => 0, 'items_new' => 0, 'items_updated' => 0, 'items_unchanged' => 0, 'items_failed' => 0, 'http_errors' => 0,
        ]);

        try {
            $items = $this->fetch($source);
        } catch (CollectionFailed $e) {
            return $this->fail($run, $e->getMessage(), $e->httpErrors);
        } catch (Throwable $e) {
            return $this->fail($run, $e->getMessage());
        }

        $counts = $this->ingest($source, $run, $items);

        $failedAds = count(array_filter($this->problems, fn ($p) => in_array($p['stage'], self::AD_STAGES, true)));

        $run->update([
            'finished_at' => now(),
            'status' => $this->problems ? 'partial' : 'success',
            'items_found' => count($items),
            'items_new' => $counts['new'],
            'items_updated' => $counts['updated'],
            'items_unchanged' => $counts['unchanged'],
            'items_failed' => $failedAds,
            'error_message' => $this->problems ? $this->summary() : null,
        ]);

        $this->saveProblems($run);
        $source->update(['last_collection_at' => now()]);

        return $run;
    }

    /** Short text for the run list; the full detail is in source_run_errors. */
    protected function summary(): string
    {
        $labels = ['listing' => 'Listing page failed', 'ad_page' => 'Ad page failed', 'store' => 'Could not store ad'];

        $lines = array_map(function (array $p) use ($labels) {
            $label = $labels[$p['stage']] ?? null;

            return $label ? "{$label}".($p['url'] ? " ({$p['url']})" : '').": {$p['message']}" : $p['message'];
        }, array_slice($this->problems, 0, 3));

        $more = count($this->problems) - 3;

        return mb_substr(implode(' | ', $lines).($more > 0 ? " | and {$more} more" : ''), 0, 1000);
    }

    protected function saveProblems(SourceRun $run): void
    {
        $rows = [];
        foreach (array_slice($this->problems, 0, self::MAX_ERROR_ROWS) as $p) {
            $rows[] = [
                'source_run_id' => $run->id,
                'source_url' => $p['url'] ? mb_substr($p['url'], 0, 500) : null,
                'stage' => $p['stage'],
                'message' => mb_substr($p['message'], 0, 2000),
                'created_at' => now(),
            ];
        }

        $extra = count($this->problems) - self::MAX_ERROR_ROWS;
        if ($extra > 0) {
            $rows[] = ['source_run_id' => $run->id, 'source_url' => null, 'stage' => 'other', 'message' => "{$extra} more problems were not listed.", 'created_at' => now()];
        }

        if ($rows) {
            SourceRunError::insert($rows);
        }
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
     * Optional keys per item: `structured` (facts published by the page itself, no ad text) and
     * `seen_only` (already stored in full; only note that it is still listed).
     *
     * @param  list<array{title:string,url:string,external_id:?string,content:string,structured?:?array,seen_only?:bool}>  $items
     * @return array{new:int,updated:int,unchanged:int,failed:int}
     */
    protected function ingest(Source $source, SourceRun $run, array $items): array
    {
        $counts = ['new' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0];

        foreach ($items as $item) {
            // One ad that cannot be stored must not lose the rest of the run.
            try {
                $counts[$this->store($source, $run, $item)]++;
            } catch (Throwable $e) {
                $counts['failed']++;
                $this->problem('store', $item['url'] ?? null, $e->getMessage());
            }
        }

        return $counts;
    }

    /** @return 'new'|'updated'|'unchanged' */
    private function store(Source $source, SourceRun $run, array $item): string
    {
        // "Seen again" only: the ad is already stored in full (for example its own page was read earlier)
        // and this run has only the short listing text. Touch it, but never replace the full text
        // with the shorter one or store a new version.
        if (! empty($item['seen_only'])) {
            $latest = RawJobPost::where('source_id', $source->id)->where('source_url', $item['url'])->latest('id')->first();

            if ($latest) {
                $latest->update(['last_seen_at' => now(), 'is_removed' => false]);

                return 'unchanged';
            }
        }

        $content = $this->stripEmails($item['content']);
        $structured = $this->cleanStructured($item['structured'] ?? null);

        // Structured data joins the fingerprint only when present, so ads stored before it existed keep their hash.
        $hash = hash('sha256', $item['title']."\n".trim(preg_replace('/\s+/', ' ', $content))
            .($structured ? "\n".json_encode($structured, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''));

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

        $post = RawJobPost::create([
            'processing_status' => 'pending', // waiting for the processing stage
            'source_id' => $source->id,
            'source_run_id' => $run->id,
            'source_url' => $item['url'],
            'external_id' => $item['external_id'],
            'raw_title' => $item['title'],
            'raw_content' => ($source->retain_raw ?? true) ? $content : null,
            'structured_data' => $structured, // facts only (no ad text), kept after the raw text is cleared
            'raw_purged_at' => ($source->retain_raw ?? true) ? null : now(),
            'content_hash' => $hash,
            'http_status' => 200,
            'fetched_at' => now(),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        // The hand-off to processing: one event for every new or changed ad, and never for an unchanged one.
        RawJobPostStored::dispatch($post->id, $hadEarlierVersion ? 'updated' : 'new');

        return $hadEarlierVersion ? 'updated' : 'new';
    }

    /** Emails removed from every text value, so structured data never carries personal contact details. */
    private function cleanStructured(?array $data): ?array
    {
        if (! $data) {
            return null;
        }

        array_walk_recursive($data, function (&$value) {
            if (is_string($value)) {
                $value = $this->stripEmails($value);
            }
        });

        return $data;
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

        // Problems noted before the run failed (for example page 1 worked, then a crash) are kept too.
        $this->problem('fetch', null, $message);
        $this->saveProblems($run);

        return $run;
    }

    private function elapsed(int $startNs): int
    {
        return (int) round((hrtime(true) - $startNs) / 1_000_000);
    }
}
