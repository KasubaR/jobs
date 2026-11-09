<?php

namespace App\Taxonomy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Installs the taxonomy and dictionaries from database/taxonomy/*.php into their tables.
 *
 * It only ever ADDS what is missing (matched on each table's key). A row that is already there, whether it was
 * installed earlier or added or edited by a person, is left exactly as it is, so installing again after an update
 * brings in the new entries and never overwrites a correction.
 */
class DefaultTaxonomy
{
    /** @return array<string, int> how many rows each table gained */
    public static function install(): array
    {
        $now = now();
        $json = fn (?array $a) => $a === null || $a === [] ? null : json_encode(array_values($a), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stamp = ['origin' => 'seed', 'created_at' => $now, 'updated_at' => $now];
        $added = [];

        $insert = function (string $table, array $row) use (&$added) {
            $new = DB::table($table)->insertOrIgnore($row);
            $added[$table] = ($added[$table] ?? 0) + $new;

            if ($new === 0) {
                $added[$table.' (aliases)'] = ($added[$table.' (aliases)'] ?? 0) + self::addMissingAliases($table, $row);
            }
        };

        foreach (self::data('industries') as [$code, $name, $aliases]) {
            $insert('industries', ['code' => $code, 'name' => $name, 'slug' => Str::slug($name), 'aliases' => $json($aliases)] + $stamp);
        }

        foreach (self::data('seniority') as [$key, $name, $rank, $aliases]) {
            $insert('seniority_levels', ['key' => $key, 'name' => $name, 'rank' => $rank, 'aliases' => $json($aliases)] + $stamp);
        }

        foreach (self::data('fields') as $broadKey => [$broadName, $broadAliases, $specifics]) {
            $insert('fields_of_study', ['key' => $broadKey, 'name' => $broadName, 'parent_id' => null, 'aliases' => $json($broadAliases)] + $stamp);
            $parentId = DB::table('fields_of_study')->where('key', $broadKey)->value('id');

            foreach ($specifics as $key => [$name, $aliases]) {
                $insert('fields_of_study', ['key' => $key, 'name' => $name, 'parent_id' => $parentId, 'aliases' => $json($aliases)] + $stamp);
            }
        }

        foreach (self::data('awards') as [$key, $name, $level, $fieldKey, $aliases]) {
            $insert('academic_awards', ['key' => $key, 'name' => $name, 'level' => $level, 'field_key' => $fieldKey, 'aliases' => $json($aliases)] + $stamp);
        }

        $professional = self::data('professional');
        foreach ($professional['qualifications'] as [$key, $name, $body, $fieldKey, $aliases]) {
            $insert('professional_qualifications', ['key' => $key, 'name' => $name, 'body' => $body, 'field_key' => $fieldKey, 'aliases' => $json($aliases)] + $stamp);
        }
        foreach ($professional['registrations'] as [$key, $name, $body, $kind, $aliases]) {
            $insert('professional_registrations', ['key' => $key, 'name' => $name, 'body' => $body, 'kind' => $kind, 'aliases' => $json($aliases)] + $stamp);
        }

        $occupations = self::data('occupations');
        foreach ($occupations['major'] as [$code, $title]) {
            $insert('occupations', ['code' => $code, 'title' => $title, 'level' => 'major', 'parent_code' => null, 'aliases' => null] + $stamp);
        }
        foreach ($occupations['submajor'] as [$code, $title]) {
            $insert('occupations', ['code' => $code, 'title' => $title, 'level' => 'submajor', 'parent_code' => substr($code, 0, 1), 'aliases' => null] + $stamp);
        }
        foreach ($occupations['unit'] as [$code, $title, $aliases]) {
            $insert('occupations', ['code' => $code, 'title' => $title, 'level' => 'unit', 'parent_code' => substr($code, 0, 2), 'aliases' => $json($aliases)] + $stamp);
        }

        foreach (self::data('skills') as [$name, $type, $category, $aliases]) {
            $insert('skills', ['name' => $name, 'type' => $type, 'category' => $category, 'aliases' => $json($aliases)] + $stamp);
        }

        return $added;
    }

    /** Natural key of each table that has aliases. */
    private const KEYS = ['industries' => 'code', 'seniority_levels' => 'key', 'fields_of_study' => 'key', 'academic_awards' => 'key', 'professional_qualifications' => 'key',
        'professional_registrations' => 'key', 'occupations' => 'code', 'skills' => 'name'];

    /**
     * An entry that already exists gains any alias the data file now lists and it lacks. Only aliases are added, never
     * removed or reworded, and only to rows still marked 'seed': once a person has taken a row over it is theirs.
     */
    private static function addMissingAliases(string $table, array $row): int
    {
        $wanted = json_decode((string) ($row['aliases'] ?? ''), true) ?: [];
        $key = self::KEYS[$table] ?? null;
        $existing = $wanted && $key ? DB::table($table)->where($key, $row[$key])->where('origin', 'seed')->first() : null;
        if (! $existing) {
            return 0;
        }

        $have = json_decode((string) $existing->aliases, true) ?: [];
        $known = array_map('mb_strtolower', $have);
        $missing = array_values(array_filter($wanted, fn ($a) => ! in_array(mb_strtolower($a), $known, true)));
        if (! $missing) {
            return 0;
        }

        DB::table($table)->where('id', $existing->id)->update(['aliases' => json_encode(array_merge($have, $missing), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]);

        return count($missing);
    }

    private static function data(string $file): array
    {
        return require database_path("taxonomy/{$file}.php");
    }
}
