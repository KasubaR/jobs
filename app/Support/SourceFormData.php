<?php

namespace App\Support;

/** Turns the flat fields of the source form into the nested settings stored on the source. */
class SourceFormData
{
    /** @return array{title_separator:string,date_formats:list<string>,fields:list<array{field:?string,pattern:?string}>} */
    public static function extraction(array $input): array
    {
        $rules = $input['extraction'] ?? [];

        $formats = array_values(array_filter(array_map('trim', explode(',', (string) ($rules['date_formats'] ?? '')))));

        $fields = [];
        foreach ((array) ($rules['fields'] ?? []) as $row) {
            $field = trim((string) ($row['field'] ?? ''));
            $pattern = (string) ($row['pattern'] ?? '');
            if ($field !== '' || trim($pattern) !== '') {
                $fields[] = ['field' => $field, 'pattern' => $pattern];
            }
        }

        return [
            'title_separator' => (string) ($rules['title_separator'] ?? ''),
            'date_formats' => $formats,
            'fields' => $fields,
        ];
    }

    /** @return array<string, mixed> */
    public static function html(array $input): array
    {
        $html = (array) ($input['html'] ?? []);

        $out = [];
        foreach (['item', 'link', 'title', 'content', 'next'] as $key) {
            $value = trim((string) ($html[$key] ?? ''));
            if ($value !== '') {
                $out[$key] = $value;
            }
        }

        $out['fetch_detail'] = filter_var($html['fetch_detail'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $out['structured_data'] = filter_var($html['structured_data'] ?? false, FILTER_VALIDATE_BOOLEAN);
        foreach (['max_pages', 'max_items', 'max_detail_requests'] as $key) {
            if (isset($html[$key]) && $html[$key] !== '') {
                $out[$key] = is_numeric($html[$key]) ? (int) $html[$key] : $html[$key];
            }
        }

        return $out;
    }

    /** What is saved in sources.collector_config. */
    public static function config(array $input, ?string $collector): ?array
    {
        $config = [];

        if ($collector === 'html_listing') {
            $config['html'] = self::html($input);
        }

        $extraction = self::extraction($input);
        if ($extraction['title_separator'] !== '' || $extraction['date_formats'] || $extraction['fields']) {
            $config['extraction'] = $extraction;
        }

        return $config ?: null;
    }
}
