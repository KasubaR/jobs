<?php

namespace App\Import;

/**
 * Reads a file of ads (CSV or JSON) into rows. It only reads: checking each ad and storing it is
 * ManualSubmissionCollector::submitMany(), so a file gets exactly the rules a pasted ad gets.
 *
 * CSV: a header row, then one ad per row. Columns: title, content (required), url, external_id,
 *      structured (optional JSON text). Comma, semicolon or tab separated; UTF-8 or Windows-1252
 *      (what Excel saves); ad text may span several lines inside quotes.
 * JSON: a list of ads, or {"source": <id or name>, "ads": [...]} with the same fields.
 */
class AdFileParser
{
    public const COLUMNS = ['title', 'url', 'content', 'external_id', 'structured'];

    /**
     * @return array{rows: list<array{row:int,data:array}>, source: string|int|null}
     *
     * @throws ImportFileException
     */
    public function parse(string $content, string $format): array
    {
        $max = (int) config('job_intel.import_max_bytes');
        if (strlen($content) > $max) {
            throw new ImportFileException('The file is larger than '.round($max / 1_048_576, 1).' MB.');
        }

        if (trim($content) === '') {
            throw new ImportFileException('The file is empty.');
        }

        $content = $this->utf8($content);

        $result = match ($format) {
            'csv' => ['rows' => $this->csv($content), 'source' => null],
            'json' => $this->json($content),
            default => throw new ImportFileException('Only .csv and .json files can be imported.'),
        };

        $limit = (int) config('job_intel.import_max_rows');
        if (count($result['rows']) > $limit) {
            throw new ImportFileException('The file has '.count($result['rows'])." ads; at most {$limit} can be imported at once. Split it into smaller files.");
        }

        return $result;
    }

    /** Format from a file name; null when it is neither. */
    public static function formatFor(string $filename): ?string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'csv' => 'csv',
            'json' => 'json',
            default => null,
        };
    }

    private function utf8(string $content): string
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content); // byte order mark

        // Excel saves "CSV" in the Windows code page unless asked for UTF-8.
        return mb_check_encoding($content, 'UTF-8') ? $content : mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }

    /** @return list<array{row:int,data:array}> */
    private function csv(string $content): array
    {
        $first = strtok($content, "\r\n") ?: '';
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn ($d) => substr_count($first, $d))->first();

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $header = fgetcsv($stream, 0, $delimiter, '"', '');
        $columns = array_map(fn ($h) => strtolower(trim((string) $h)), $header ?: []);

        foreach (['title', 'content'] as $required) {
            if (! in_array($required, $columns, true)) {
                fclose($stream);
                throw new ImportFileException("The first row must name the columns, and \"{$required}\" is missing. Columns: ".implode(', ', self::COLUMNS).'.');
            }
        }

        $rows = [];
        $line = 1;

        while (($cells = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $line++;

            if ($cells === [null] || count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue; // blank line
            }

            $data = [];
            foreach ($columns as $i => $name) {
                if (in_array($name, self::COLUMNS, true)) {
                    $data[$name] = $cells[$i] ?? '';
                }
            }

            $rows[] = ['row' => $line, 'data' => $data];

            if (count($rows) > (int) config('job_intel.import_max_rows') + 1) {
                break; // already too many; the caller reports it without reading the rest
            }
        }

        fclose($stream);

        return $rows;
    }

    /** @return array{rows: list<array{row:int,data:array}>, source: string|int|null} */
    private function json(string $content): array
    {
        $data = json_decode($content, true, 64);

        if (! is_array($data)) {
            throw new ImportFileException('The file is not valid JSON: '.json_last_error_msg().'.');
        }

        $source = null;
        if (! array_is_list($data)) {
            $source = $data['source'] ?? null;
            $data = $data['ads'] ?? null;

            if (! is_array($data) || ! array_is_list($data)) {
                throw new ImportFileException('A JSON object must have an "ads" list: {"source": ..., "ads": [ ... ]}.');
            }
        }

        $rows = [];
        foreach ($data as $i => $ad) {
            // Row numbers count from 1, so "Row 3" is the third ad in the list.
            $rows[] = ['row' => $i + 1, 'data' => is_array($ad) ? $ad : []];
        }

        return ['rows' => $rows, 'source' => is_scalar($source) ? $source : null];
    }
}
