<?php

namespace App\Taxonomy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Folds the three taxonomy qualification tables (academic_awards, professional_qualifications,
 * professional_registrations) into the Qualification Directory, and points the job rows at the result.
 *
 * Like DefaultTaxonomy it only ever ADDS: a directory row that exists (matched on legacy_table + legacy_key) is left
 * exactly as it is, and a job row that already has a directory_qualification_id is not touched. Nothing is dropped from
 * the source tables. Folded rows are `unverified`: a dictionary entry says the name exists in ads, not that it was checked.
 */
class DirectoryFold
{
    private const LEVELS = [
        ['school', 'School certificate', 1],
        ['certificate', 'Certificate', 2],
        ['diploma', 'Diploma', 3],
        ['bachelor', "Bachelor's degree", 4],
        ['postgraduate', 'Postgraduate certificate or diploma', 5],
        ['masters', "Master's degree", 6],
        ['phd', 'Doctorate', 7],
    ];

    /** @return array<string, int> rows gained per table */
    public static function install(): array
    {
        $now = now();
        $stamp = ['origin' => 'seed', 'created_at' => $now, 'updated_at' => $now];
        $added = ['qualification_levels' => 0, 'organizations' => 0, 'directory_qualifications' => 0, 'job_qualifications' => 0];

        foreach (self::LEVELS as [$key, $name, $rank]) {
            $added['qualification_levels'] += DB::table('qualification_levels')->insertOrIgnore(['key' => $key, 'name' => $name, 'rank' => $rank] + $stamp);
        }
        $levelId = DB::table('qualification_levels')->pluck('id', 'key');
        $fieldId = DB::table('fields_of_study')->pluck('id', 'key');

        $organization = function (?string $name, string $type) use (&$added, $stamp): ?int {
            if ($name === null || trim($name) === '') {
                return null;
            }
            $key = Str::limit(Str::slug($name), 80, '');
            $added['organizations'] += DB::table('organizations')->insertOrIgnore(['key' => $key, 'name' => $name, 'type' => $type] + $stamp);

            return DB::table('organizations')->where('key', $key)->value('id');
        };

        $fold = function (string $table, string $kind, callable $map) use (&$added, $stamp) {
            foreach (DB::table($table)->get() as $row) {
                $added['directory_qualifications'] += DB::table('directory_qualifications')->insertOrIgnore([
                    'key' => $kind.'.'.$row->key,
                    'name' => $row->name,
                    'kind' => $kind,
                    'aliases' => $row->aliases,
                    'status' => 'unverified',
                    'origin' => $row->origin,
                    'legacy_table' => $table,
                    'legacy_key' => $row->key,
                    'created_at' => $row->created_at ?? $stamp['created_at'],
                    'updated_at' => $row->updated_at ?? $stamp['updated_at'],
                ] + $map($row));
            }
        };

        $fold('academic_awards', 'academic', fn ($r) => [
            'level_id' => $levelId[$r->level] ?? null,
            'field_id' => $r->field_key ? ($fieldId[$r->field_key] ?? null) : null,
        ]);
        $fold('professional_qualifications', 'professional', fn ($r) => [
            'field_id' => $r->field_key ? ($fieldId[$r->field_key] ?? null) : null,
            'awarding_organization_id' => $organization($r->body, 'awarding_body'),
        ]);
        $fold('professional_registrations', 'registration', fn ($r) => [
            'registration_kind' => $r->kind,
            'awarding_organization_id' => $organization($r->body, 'professional_body'),
        ]);

        // point job rows at the directory (the old columns stay)
        foreach ([
            'academic_award_id' => 'academic_awards',
            'professional_qualification_id' => 'professional_qualifications',
            'professional_registration_id' => 'professional_registrations',
        ] as $column => $table) {
            $added['job_qualifications'] += DB::update(
                "update job_qualifications set directory_qualification_id = (
                    select d.id from directory_qualifications d join {$table} t on t.`key` = d.legacy_key
                    where d.legacy_table = ? and t.id = job_qualifications.{$column}
                ) where directory_qualification_id is null and {$column} is not null",
                [$table],
            );
        }

        return $added;
    }
}
