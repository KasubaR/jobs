<?php

namespace App\Collectors;

use App\Models\Source;
use App\Models\SourceRun;
use DomainException;
use RuntimeException;

/**
 * For sources that are not collected automatically: government PDFs, newspaper notices,
 * partnership sources, or any site where you read the ad yourself. You paste the ad in;
 * it goes through exactly the same storage rules as an automatic collection.
 * It never makes a network request.
 */
class ManualSubmissionCollector extends BaseCollector
{
    private const MAX_CONTENT = 100_000;

    public function method(): string
    {
        return 'manual';
    }

    /** Manual sources have nothing to fetch; ads arrive through submit(). */
    protected function fetch(Source $source): array
    {
        throw new RuntimeException("'{$source->name}' is a manual source: add ads with the 'Add ad' action, it is not collected automatically.");
    }

    public function collect(Source $source, string $trigger = 'scheduled'): SourceRun
    {
        $this->fetch($source);
    }

    public function check(Source $source): AccessCheck
    {
        return new AccessCheck(true, null, 0, 0, 'Manual source: ads are pasted in by hand, so there is nothing to connect to.');
    }

    protected function assertConfigured(Source $source): void
    {
        if ($source->collection_method !== $this->method()) {
            throw new RuntimeException("Source '{$source->name}' is not configured for manual submission.");
        }
    }

    /**
     * Store one ad you pasted in.
     *
     * @param  array{title?:string,url?:?string,content?:string}  $ad
     *
     * @throws DomainException when the ad is unusable
     */
    public function submit(Source $source, array $ad): SourceRun
    {
        $this->assertCollectable($source);

        $title = trim((string) ($ad['title'] ?? ''));
        $content = trim((string) ($ad['content'] ?? ''));
        $url = trim((string) ($ad['url'] ?? ''));

        if ($title === '' || $content === '') {
            throw new DomainException('Both a title and the ad text are required.');
        }

        if (mb_strlen($content) > self::MAX_CONTENT) {
            throw new DomainException('The ad text is longer than '.number_format(self::MAX_CONTENT).' characters.');
        }

        if ($url !== '' && (! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('~^https?://~i', $url) || strlen($url) > 500)) {
            throw new DomainException('The link must be a full http(s) address, or left empty.');
        }

        // Ads without a web address (PDF, newspaper) get a stable reference so repeats are recognised.
        $url = $url !== '' ? $url : 'manual:'.substr(hash('sha256', $source->id.'|'.$title), 0, 40);

        $run = SourceRun::create([
            'source_id' => $source->id,
            'started_at' => now(),
            'status' => 'running',
            'trigger' => 'manual',
        ]);

        [$new, $updated] = $this->ingest($source, $run, [[
            'title' => $title,
            'url' => $url,
            'external_id' => null,
            'content' => $content,
        ]]);

        $run->update([
            'finished_at' => now(),
            'status' => 'success',
            'items_found' => 1,
            'items_new' => $new,
            'items_updated' => $updated,
        ]);

        $source->update(['last_collection_at' => now()]);

        return $run;
    }
}
