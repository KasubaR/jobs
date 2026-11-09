<?php

namespace App\Import;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Loads qualification-directory records from a CSV file, one record type per file.
 *
 *   organizations   key, name, type, country, website, aliases
 *   qualifications  key, name, kind, level, field, awarding_organization, duration_months, entry_requirements, aliases
 *   recognitions    qualification, authority, status, scope, reference, valid_from, valid_to
 *   programmes      qualification, provider, name, mode, duration_months, location, accreditation_status, url
 *
 * Qualifications, authorities and providers are named by their `key`. `level` is a qualification level key, `field` a
 * field-of-study key, `aliases` are separated by "|".
 *
 * Any row of a qualifications, recognitions or programmes file may also carry source columns:
 *   source_url, source_document, note, verified_on (YYYY-MM-DD), verified_by
 * A source creates an evidence row. A record is marked verified (qualification status, verified_at) only when it has a
 * source AND a verified_on date, so nothing becomes "verified" without something to point at.
 *
 * Rows are matched on their natural key and updated, so loading the same file again changes nothing. A bad row is
 * reported with its row number and the rest are stored. Comma, semicolon or tab separated; UTF-8 or Windows-1252.
 */
class DirectoryImporter
{
    public const TYPES = ['organizations', 'qualifications', 'recognitions', 'programmes'];

    private const ORG_TYPES = ['awarding_body', 'professional_body', 'university', 'college', 'regulator', 'exam_board'];
    private const KINDS = ['academic', 'professional', 'registration', 'vocational', 'short_course'];
    private const RECOGNITION = ['accredited', 'registered', 'recognised', 'not_recognised', 'unknown'];
    private const MODES = ['full_time', 'part_time', 'distance', 'online', 'blended'];

    /** Columns a file may leave out. */
    private const OPTIONAL = [
        'organizations' => ['country', 'website', 'aliases'],
        'qualifications' => ['level', 'field', 'awarding_organization', 'duration_months', 'entry_requirements', 'aliases', 'source_url', 'source_document', 'note', 'verified_on', 'verified_by'],
        'recognitions' => ['scope', 'reference', 'valid_from', 'valid_to', 'source_url', 'source_document', 'note', 'verified_on', 'verified_by'],
        'programmes' => ['name', 'mode', 'duration_months', 'location', 'accreditation_status', 'url', 'source_url', 'source_document', 'note', 'verified_on', 'verified_by'],
    ];

    /** Optional columns the file does not have: those fields are left as they are, not blanked. @var list<string> */
    private array $absent = [];

    /** @return array{read:int, created:int, updated:int, errors:list<string>} */
    public function import(string $type, string $content): array
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unknown type "'.$type.'". Use one of: '.implode(', ', self::TYPES).'.');
        }

        $rows = $this->rows($content);
        $result = ['read' => count($rows), 'created' => 0, 'updated' => 0, 'errors' => []];

        foreach ($rows as $number => $row) {
            try {
                $this->absent = array_values(array_diff(self::OPTIONAL[$type], array_keys($row)));
                $outcome = DB::transaction(fn () => $this->{$type}($row + array_fill_keys(self::OPTIONAL[$type], '')));
                $result[$outcome]++;
            } catch (Throwable $e) {
                $result['errors'][] = "Row {$number}: ".$e->getMessage();
            }
        }

        return $result;
    }

    // ---- one row of each type; each returns 'created' or 'updated' ----

    private function organizations(array $r): string
    {
        $this->need($r, ['key', 'name', 'type']);
        $this->oneOf($r['type'], self::ORG_TYPES, 'type');

        return $this->upsert('organizations', ['key' => $r['key']], $this->present([
            'name' => $r['name'], 'type' => $r['type'], 'country' => strtoupper($r['country'] ?: 'ZM'),
            'website' => $this->url($r['website'] ?? ''), 'aliases' => $this->aliases($r['aliases'] ?? ''), 'origin' => 'person',
        ], ['country' => 'country', 'website' => 'website', 'aliases' => 'aliases']));
    }

    private function qualifications(array $r): string
    {
        $this->need($r, ['key', 'name', 'kind']);
        $this->oneOf($r['kind'], self::KINDS, 'kind');

        $level = $r['level'] === '' ? null : $this->idOf('qualification_levels', 'key', $r['level'], 'level');
        $field = $r['field'] === '' ? null : $this->idOf('fields_of_study', 'key', $r['field'], 'field');
        $org = $r['awarding_organization'] === '' ? null : $this->idOf('organizations', 'key', $r['awarding_organization'], 'awarding_organization');
        $months = $this->whole($r['duration_months'] ?? '', 'duration_months');
        $existing = DB::table('directory_qualifications')->where('key', $r['key'])->first();
        $source = $this->sourceOf($r);

        $values = $this->present(['name' => $r['name'], 'kind' => $r['kind'], 'level_id' => $level, 'field_id' => $field, 'awarding_organization_id' => $org,
            'typical_duration_months' => $months, 'entry_requirements' => $r['entry_requirements'] ?: null, 'aliases' => $this->aliases($r['aliases'] ?? ''), 'origin' => $existing->origin ?? 'person'],
            ['level' => 'level_id', 'field' => 'field_id', 'awarding_organization' => 'awarding_organization_id', 'duration_months' => 'typical_duration_months', 'entry_requirements' => 'entry_requirements', 'aliases' => 'aliases']);
        if ($source && $source['verified_at']) {
            $values['status'] = 'verified';
        }

        $outcome = $this->upsert('directory_qualifications', ['key' => $r['key']], $values);
        $this->evidence('directory_qualifications', $r['key'], \App\Models\DirectoryQualification::class, 'existence', $source);

        return $outcome;
    }

    private function recognitions(array $r): string
    {
        $this->need($r, ['qualification', 'authority', 'status']);
        $this->oneOf($r['status'], self::RECOGNITION, 'status');

        $qualification = $this->idOf('directory_qualifications', 'key', $r['qualification'], 'qualification');
        $authority = $this->idOf('organizations', 'key', $r['authority'], 'authority');
        $source = $this->sourceOf($r);

        $outcome = $this->upsert('recognitions', ['qualification_id' => $qualification, 'authority_id' => $authority], $this->present([
            'status' => $r['status'], 'scope' => $r['scope'] ?: null, 'reference' => $r['reference'] ?: null,
            'valid_from' => $this->date($r['valid_from'] ?? '', 'valid_from'), 'valid_to' => $this->date($r['valid_to'] ?? '', 'valid_to'),
        ], ['scope' => 'scope', 'reference' => 'reference', 'valid_from' => 'valid_from', 'valid_to' => 'valid_to']) + ($source && $source['verified_at'] ? ['verified_at' => $source['verified_at']] : []));
        $id = DB::table('recognitions')->where('qualification_id', $qualification)->where('authority_id', $authority)->value('id');
        $this->attach($id, \App\Models\Recognition::class, 'recognition', $source);

        return $outcome;
    }

    private function programmes(array $r): string
    {
        $this->need($r, ['qualification', 'provider']);
        if ($r['mode'] !== '') {
            $this->oneOf($r['mode'], self::MODES, 'mode');
        }

        $qualification = $this->idOf('directory_qualifications', 'key', $r['qualification'], 'qualification');
        $provider = $this->idOf('organizations', 'key', $r['provider'], 'provider');
        $location = $r['location'] === '' ? null : $this->idOf('locations', 'name', $r['location'], 'location');
        $source = $this->sourceOf($r);

        $outcome = $this->upsert('programmes', ['qualification_id' => $qualification, 'provider_id' => $provider, 'name' => $r['name'] ?: null, 'location_id' => $location], $this->present([
            'mode' => $r['mode'] ?: null, 'duration_months' => $this->whole($r['duration_months'] ?? '', 'duration_months'),
            'accreditation_status' => $r['accreditation_status'] ?: null, 'url' => $this->url($r['url'] ?? ''),
        ], ['mode' => 'mode', 'duration_months' => 'duration_months', 'accreditation_status' => 'accreditation_status', 'url' => 'url']) + ($source && $source['verified_at'] ? ['verified_at' => $source['verified_at']] : []));
        $id = DB::table('programmes')->where($this->nullSafe(['qualification_id' => $qualification, 'provider_id' => $provider, 'name' => $r['name'] ?: null, 'location_id' => $location]))->value('id');
        $this->attach($id, \App\Models\Programme::class, 'offered', $source);

        return $outcome;
    }

    // ---- sources and evidence ----

    /** @return ?array{url:?string, document:?string, note:?string, verified_by:?string, verified_at:?string} */
    private function sourceOf(array $r): ?array
    {
        $url = $this->url($r['source_url'] ?? '');
        $document = ($r['source_document'] ?? '') ?: null;
        if ($url === null && $document === null) {
            return null;
        }

        $on = $this->date($r['verified_on'] ?? '', 'verified_on');

        return ['url' => $url, 'document' => $document, 'note' => ($r['note'] ?? '') ?: null, 'verified_by' => ($r['verified_by'] ?? '') ?: null, 'verified_at' => $on ? $on.' 00:00:00' : null];
    }

    private function evidence(string $table, string $key, string $class, string $claim, ?array $source): void
    {
        $this->attach(DB::table($table)->where('key', $key)->value('id'), $class, $claim, $source);
    }

    private function attach(int $subjectId, string $class, string $claim, ?array $source): void
    {
        if ($source === null) {
            return;
        }

        DB::table('evidence')->updateOrInsert(
            ['subject_type' => $class, 'subject_id' => $subjectId, 'claim' => $claim, 'url' => $source['url'], 'document' => $source['document']],
            ['note' => $source['note'], 'verified_by' => $source['verified_by'], 'verified_at' => $source['verified_at'], 'retrieved_on' => $source['verified_at'] ? substr($source['verified_at'], 0, 10) : null,
                'updated_at' => now(), 'created_at' => now()],
        );
    }

    // ---- helpers ----

    /** Drops the values whose column the file does not have, so a partial file never blanks what it does not mention. */
    private function present(array $values, array $columns): array
    {
        foreach ($this->absent as $name) {
            if (isset($columns[$name])) {
                unset($values[$columns[$name]]);
            }
        }

        return $values;
    }

    private function upsert(string $table, array $match, array $values): string
    {
        $query = DB::table($table)->where($this->nullSafe($match));
        if ($query->exists()) {
            $query->update($values + ['updated_at' => now()]);

            return 'updated';
        }

        DB::table($table)->insert($match + $values + ['created_at' => now(), 'updated_at' => now()]);

        return 'created';
    }

    /** where() cannot compare to null with "="; turn those into whereNull. */
    private function nullSafe(array $match): \Closure
    {
        return function ($q) use ($match) {
            foreach ($match as $column => $value) {
                $value === null ? $q->whereNull($column) : $q->where($column, $value);
            }
        };
    }

    private function need(array $r, array $columns): void
    {
        foreach ($columns as $c) {
            if (! array_key_exists($c, $r)) {
                throw new InvalidArgumentException("the file has no \"{$c}\" column");
            }
            if (trim($r[$c]) === '') {
                throw new InvalidArgumentException("\"{$c}\" is empty");
            }
        }
    }

    private function oneOf(string $value, array $allowed, string $column): void
    {
        if (! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException("\"{$column}\" is \"{$value}\"; use one of: ".implode(', ', $allowed));
        }
    }

    private function idOf(string $table, string $column, string $value, string $label): int
    {
        return DB::table($table)->where($column, $value)->value('id') ?? throw new InvalidArgumentException("\"{$label}\" \"{$value}\" is not known");
    }

    private function whole(string $value, string $column): ?int
    {
        if (trim($value) === '') {
            return null;
        }
        if (! ctype_digit(trim($value))) {
            throw new InvalidArgumentException("\"{$column}\" must be a whole number");
        }

        return (int) $value;
    }

    private function date(string $value, string $column): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw new InvalidArgumentException("\"{$column}\" must be a date like 2026-11-09");
        }

        return $value;
    }

    private function url(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (! preg_match('~^https?://~i', $value) || ! filter_var($value, FILTER_VALIDATE_URL) || strlen($value) > 500) {
            throw new InvalidArgumentException("\"{$value}\" is not a web address starting with http:// or https://");
        }

        return $value;
    }

    private function aliases(string $value): ?string
    {
        $list = array_values(array_filter(array_map('trim', explode('|', $value)), fn ($a) => $a !== ''));

        return $list ? json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    }

    // ---- reading the file ----

    /** @return array<int, array<string,string>> rows by their line number in the file (the header is row 1) */
    private function rows(string $content): array
    {
        $content = ltrim($content, "\xEF\xBB\xBF");
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $first = strtok($content, "\n") ?: '';
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn ($d) => substr_count($first, $d))->first();
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $header = fgetcsv($stream, 0, $delimiter, '"', '');
        if (! $header) {
            throw new InvalidArgumentException('The file is empty.');
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $line++;
            if ($cells === [null] || ! array_filter($cells, fn ($c) => trim((string) $c) !== '')) {
                continue;
            }
            $row = array_fill_keys($header, '');
            foreach ($header as $i => $name) {
                $row[$name] = trim((string) ($cells[$i] ?? ''));
            }
            $rows[$line] = $row;
        }
        fclose($stream);

        return $rows;
    }
}
