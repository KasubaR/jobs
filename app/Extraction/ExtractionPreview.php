<?php

namespace App\Extraction;

use App\Models\RawJobPost;
use App\Models\Source;

/** Tries a set of rules on a source's most recent stored ads. Reads the database only. */
class ExtractionPreview
{
    public function __construct(private Extractor $extractor) {}

    /**
     * @param  array<string, mixed>  $rules
     * @return array{
     *   problems: list<string>,
     *   total: int,
     *   rows: list<array{url:string,title:?string,employer:?string,values:array<string,?string>,warnings:list<string>}>,
     *   coverage: array<string, int>
     * }
     */
    public function run(Source $source, array $rules, int $limit = 10): array
    {
        $rules = $this->clean($rules);
        $problems = ExtractionRules::validate($rules);

        $posts = RawJobPost::query()
            ->where('source_id', $source->id)
            ->latest('id')
            ->limit($limit)
            ->get();

        if ($problems) {
            return ['problems' => $problems, 'total' => $posts->count(), 'rows' => [], 'coverage' => []];
        }

        $rows = [];
        $coverage = ['employer' => 0];
        foreach (Extractor::FIELDS as $field => $label) {
            $coverage[$field] = 0;
        }

        foreach ($posts as $post) {
            $r = $this->extractor->extract($post, $rules);

            $coverage['employer'] += $r['employer'] !== null ? 1 : 0;
            foreach ($r['values'] as $field => $value) {
                $coverage[$field] += $value !== null ? 1 : 0;
            }

            $rows[] = ['url' => $post->source_url] + array_intersect_key($r, array_flip(['title', 'employer', 'values', 'warnings']));
        }

        return ['problems' => [], 'total' => $posts->count(), 'rows' => $rows, 'coverage' => $coverage];
    }

    /** Drop blank rows the form leaves behind. */
    private function clean(array $rules): array
    {
        $rules['fields'] = array_values(array_filter(
            $rules['fields'] ?? [],
            fn ($r) => filled($r['field'] ?? null) || filled($r['pattern'] ?? null),
        ));
        $rules['date_formats'] = array_values(array_filter($rules['date_formats'] ?? [], 'filled'));
        $rules['title_separator'] = (string) ($rules['title_separator'] ?? '');

        return $rules;
    }
}
