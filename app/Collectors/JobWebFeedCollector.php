<?php

namespace App\Collectors;

use App\Models\RawJobPost;
use App\Models\Source;
use App\Models\SourceRun;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Collects job ads from a WordPress job-listing RSS feed (JobWeb Zambia).
 *
 * Collection rules (docs/job-intel/03-collection-rules.md):
 *  - one request per run, to the feed URL only; no crawling, no CAPTCHA/login handling
 *  - honest User-Agent with a contact address
 *  - only runs for sources that are approved AND active
 *  - email addresses are removed before storing (no personal data)
 *  - raw content is not stored at all when the source has retain_raw = false
 */
class JobWebFeedCollector
{
    public function collect(Source $source): SourceRun
    {
        $this->assertCollectable($source);

        $run = SourceRun::create([
            'source_id' => $source->id,
            'started_at' => now(),
            'status' => 'running',
        ]);

        try {
            $response = Http::withUserAgent($this->userAgent())
                ->timeout(config('job_intel.http_timeout'))
                ->accept('application/rss+xml, application/xml;q=0.9')
                ->get($source->job_listing_url);

            if (! $response->successful()) {
                return $this->fail($run, "Feed returned HTTP {$response->status()}", httpErrors: 1);
            }

            $body = $response->body();
            if (strlen($body) > config('job_intel.max_response_bytes')) {
                return $this->fail($run, 'Feed larger than the allowed size; not parsed.');
            }

            $items = $this->parse($body);
        } catch (Throwable $e) {
            return $this->fail($run, $e->getMessage());
        }

        $new = $updated = 0;

        foreach ($items as $item) {
            $result = $this->store($source, $run, $item);
            $new += $result === 'new' ? 1 : 0;
            $updated += $result === 'updated' ? 1 : 0;
        }

        $run->update([
            'finished_at' => now(),
            'status' => 'success',
            'items_found' => count($items),
            'items_new' => $new,
            'items_updated' => $updated,
        ]);

        $source->update(['last_collection_at' => now()]);

        return $run;
    }

    private function assertCollectable(Source $source): void
    {
        if ($source->access_status !== 'approved' || ! $source->is_active) {
            throw new RuntimeException("Source '{$source->name}' is not approved and active; refusing to collect.");
        }

        if ($source->collection_method !== 'rss' || blank($source->job_listing_url)) {
            throw new RuntimeException("Source '{$source->name}' is not configured for RSS collection.");
        }

        if (blank(config('job_intel.collector_contact'))) {
            throw new RuntimeException('COLLECTOR_CONTACT is not set; the User-Agent must include a contact address.');
        }
    }

    private function userAgent(): string
    {
        return 'JobIntelBot/0.1 (private research; contact: '.config('job_intel.collector_contact').')';
    }

    /** @return list<array{title:string,url:string,external_id:?string,content:string}> */
    private function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $feed = simplexml_load_string($xml, options: LIBXML_NOCDATA | LIBXML_NONET);
        libxml_use_internal_errors($previous);

        if ($feed === false || ! isset($feed->channel->item)) {
            throw new RuntimeException('Response was not a readable RSS feed.');
        }

        $items = [];
        foreach ($feed->channel->item as $item) {
            $url = trim((string) $item->link);
            if ($url === '') {
                continue;
            }

            $content = (string) $item->children('http://purl.org/rss/1.0/modules/content/')->encoded;
            if (trim($content) === '') {
                $content = (string) $item->description;
            }

            $externalId = null;
            if (preg_match('/[?&#]p=(\d+)/', html_entity_decode((string) $item->guid), $m)) {
                $externalId = $m[1];
            }

            $items[] = [
                'title' => trim(html_entity_decode((string) $item->title)),
                'url' => $url,
                'external_id' => $externalId,
                'content' => $content,
            ];
        }

        return $items;
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
            'raw_content' => $source->retain_raw ? $content : null,
            'raw_purged_at' => $source->retain_raw ? null : now(),
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
}
