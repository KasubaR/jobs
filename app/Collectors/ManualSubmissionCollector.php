<?php

namespace App\Collectors;

use App\Models\Source;
use App\Models\SourceRun;
use DomainException;
use RuntimeException;

/**
 * For sources that are not collected automatically: government PDFs, newspaper notices,
 * partnership sources, or any site where you read the ads yourself. Ads arrive pasted in one at a
 * time (submit) or as a file (submitMany: the CSV/JSON upload and the collect:import command, which
 * outside scripts can also use). Everything goes through exactly the same storage rules as an
 * automatic collection. It never makes a network request.
 */
class ManualSubmissionCollector extends BaseCollector
{
    private const MAX_CONTENT = 100_000;

    private const MAX_STRUCTURED_BYTES = 20_000;

    private const MAX_STRUCTURED_DEPTH = 4;

    public function method(): string
    {
        return 'manual';
    }

    /** Manual sources have nothing to fetch; ads arrive through submit() / submitMany(). */
    protected function fetch(Source $source): array
    {
        throw new RuntimeException("'{$source->name}' is a manual source: add ads with the 'Add ad' or 'Import file' action, it is not collected automatically.");
    }

    public function collect(Source $source, string $trigger = 'scheduled'): SourceRun
    {
        $this->fetch($source);
    }

    public function check(Source $source): AccessCheck
    {
        return new AccessCheck(true, null, 0, 0, 'Manual source: ads are pasted or imported by hand, so there is nothing to connect to.');
    }

    /** Collection methods whose ads this collector may store. The Python worker's collector overrides it. */
    protected function allowedMethods(): array
    {
        return ['manual'];
    }

    /** Runs after a run is saved. Manual sources are not scheduled; the Python worker's collector reschedules. */
    protected function afterRun(Source $source, SourceRun $run): void {}

    protected function assertConfigured(Source $source): void
    {
        if (! in_array($source->collection_method, $this->allowedMethods(), true)) {
            throw new RuntimeException("Source '{$source->name}' is not configured for {$this->method()} submission.");
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

        $item = $this->validated($source, $ad);

        return $this->finish($source, 'manual', [$item], 1, []);
    }

    /**
     * Store many ads from a file, as ONE run. A bad row is reported with its row number and the rest are
     * still stored. The run fails only when no ad could be stored at all.
     *
     * @param  list<array{row:int,data:array}>  $rows
     */
    public function submitMany(Source $source, array $rows, string $trigger = 'import'): SourceRun
    {
        $this->assertCollectable($source);

        $items = [];
        $invalid = [];

        foreach ($rows as $row) {
            try {
                $items[] = $this->validated($source, $row['data']);
            } catch (DomainException $e) {
                $title = trim((string) ($row['data']['title'] ?? ''));
                $invalid[] = ['stage' => 'store', 'url' => null, 'message' => "Row {$row['row']}".($title !== '' ? " ({$title})" : '').": {$e->getMessage()}"];
            }
        }

        return $this->finish($source, $trigger, $items, count($rows), $invalid);
    }

    /**
     * @param  list<array<string,mixed>>  $items  valid ads
     * @param  list<array{stage:string,url:?string,message:string}>  $invalid  problems found before storing
     */
    private function finish(Source $source, string $trigger, array $items, int $found, array $invalid): SourceRun
    {
        $run = SourceRun::create([
            'source_id' => $source->id, 'started_at' => now(), 'status' => 'running', 'trigger' => $trigger,
            'items_found' => 0, 'items_new' => 0, 'items_updated' => 0, 'items_unchanged' => 0, 'items_failed' => 0, 'http_errors' => 0,
        ]);

        $this->problems = $invalid;
        $counts = $this->ingest($source, $run, $items);

        $failedAds = count(array_filter($this->problems, fn ($p) => $p['stage'] === 'store'));
        $stored = $counts['new'] + $counts['updated'] + $counts['unchanged'];

        if ($found === 0) {
            $this->problem('other', null, 'The file contains no ads.');
        }

        $status = $stored === 0 ? 'failed' : ($this->problems ? 'partial' : 'success');

        $run->update([
            'finished_at' => now(),
            'status' => $status,
            'items_found' => $found,
            'items_new' => $counts['new'],
            'items_updated' => $counts['updated'],
            'items_unchanged' => $counts['unchanged'],
            'items_failed' => $failedAds,
            'error_message' => $this->problems ? $this->summary() : null,
        ]);

        $this->saveProblems($run);

        if ($stored > 0) {
            $source->update(['last_collection_at' => now()]);
        }

        $this->afterRun($source, $run);

        return $run;
    }

    /**
     * Check one ad and turn it into what the storage path expects.
     *
     * @param  array<string,mixed>  $ad
     * @return array{title:string,url:string,external_id:?string,content:string,structured:?array}
     *
     * @throws DomainException with a message a person can act on
     */
    private function validated(Source $source, array $ad): array
    {
        $title = trim((string) ($ad['title'] ?? ''));
        $content = trim((string) ($ad['content'] ?? ''));
        $url = trim((string) ($ad['url'] ?? ''));
        $externalId = trim((string) ($ad['external_id'] ?? ''));

        if ($title === '' || $content === '') {
            throw new DomainException('Both a title and the ad text are required.');
        }

        if (mb_strlen($title) > 500) {
            throw new DomainException('The title is longer than 500 characters.');
        }

        if (mb_strlen($content) > self::MAX_CONTENT) {
            throw new DomainException('The ad text is longer than '.number_format(self::MAX_CONTENT).' characters.');
        }

        if ($url !== '' && (! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('~^https?://~i', $url) || strlen($url) > 500)) {
            throw new DomainException('The link must be a full http(s) address, or left empty.');
        }

        // Ads without a web address (PDF, newspaper) get a stable reference so repeats are recognised.
        $url = $url !== '' ? $url : 'manual:'.substr(hash('sha256', $source->id.'|'.$title), 0, 40);

        return [
            'title' => $title,
            'url' => $url,
            'external_id' => $externalId !== '' ? mb_substr($externalId, 0, 100) : null,
            'content' => $content,
            'structured' => $this->structured($ad['structured'] ?? null),
        ];
    }

    /**
     * Facts supplied with an ad (same idea as the schema.org data the HTML collector reads). Accepted as
     * an object or a JSON text; kept small and plain so it cannot be used to stuff the database.
     */
    private function structured(mixed $value): ?array
    {
        if (is_string($value) && trim($value) !== '') {
            $value = json_decode($value, true);
        }

        if (! is_array($value) || $value === [] || array_is_list($value)) {
            if ($value === null || $value === '' || $value === []) {
                return null;
            }

            throw new DomainException('The structured data must be a JSON object of facts, such as {"employer": "..."}.');
        }

        $clean = $this->plain($value, 1);

        if (strlen(json_encode($clean)) > self::MAX_STRUCTURED_BYTES) {
            throw new DomainException('The structured data is larger than '.(self::MAX_STRUCTURED_BYTES / 1000).' KB.');
        }

        return $clean ?: null;
    }

    /** Short keys, limited depth, capped strings: nothing that could hide a payload or flood a field. */
    private function plain(array $data, int $depth): array
    {
        $out = [];

        foreach (array_slice($data, 0, 50, true) as $key => $value) {
            $key = is_int($key) ? $key : mb_substr(trim(strip_tags((string) $key)), 0, 60);

            if (is_array($value)) {
                if ($depth < self::MAX_STRUCTURED_DEPTH) {
                    $out[$key] = $this->plain($value, $depth + 1);
                }
            } elseif (is_string($value)) {
                $out[$key] = mb_substr(trim(strip_tags($value)), 0, 1000);
            } elseif (is_int($value) || is_float($value) || is_bool($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
