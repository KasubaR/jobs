<?php

namespace App\Collectors;

use App\Models\Source;
use RuntimeException;

/**
 * JobWeb Zambia: WordPress job-listing RSS feed.
 * One request per run, to the feed URL only. Everything else is in BaseCollector.
 */
class JobWebFeedCollector extends BaseCollector
{
    public function method(): string
    {
        return 'rss';
    }

    protected function fetch(Source $source): array
    {
        $response = $this->get($source, $source->job_listing_url, 'application/rss+xml, application/xml;q=0.9');

        return $this->parse($this->body($response));
    }

    /** @return list<array{title:string,url:string,external_id:?string,content:string}> */
    private function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $feed = simplexml_load_string($xml, options: LIBXML_NOCDATA | LIBXML_NONET);
        libxml_use_internal_errors($previous);

        if ($feed === false || ! isset($feed->channel->item)) {
            throw new CollectionFailed('Response was not a readable RSS feed.');
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
}
