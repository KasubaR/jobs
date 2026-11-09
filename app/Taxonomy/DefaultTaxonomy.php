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
            $added[$table] = ($added[$table] ?? 0) + DB::table($table)->insertOrIgnore($row);
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

    private static function data(string $file): array
    {
        return require database_path("taxonomy/{$file}.php");
    }
}
