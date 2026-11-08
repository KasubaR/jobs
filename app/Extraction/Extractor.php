<?php

namespace App\Extraction;

use App\Models\RawJobPost;
use Carbon\Carbon;

/**
 * Applies a source's extraction rules to one stored ad. Rules are plain data
 * (no code), stored in sources.collector_config['extraction']:
 *
 *   title_separator : split "Title at Employer" on the LAST occurrence of this text
 *   date_formats    : PHP date formats tried for date fields, e.g. "j F Y", "d/m/Y"
 *   fields[]        : { field, pattern } - the pattern's first capture group becomes the value
 *
 * Works on stored text only. It never makes a network request.
 */
class Extractor
{
    /** Fields a pattern may fill. */
    public const FIELDS = [
        'closing_date' => 'Closing date',
        'posted_date' => 'Posted date',
        'employment_type' => 'Employment type',
        'location' => 'Location',
        'salary_text' => 'Salary',
    ];

    /** Fields only the processing stage reads (with the built-in label rules); not offered in the source form. */
    public const EXTRA_FIELDS = [
        'employer' => 'Employer',
        'experience_text' => 'Experience',
        'qualification_text' => 'Qualification',
        'application_url' => 'Application link',
    ];

    public const DATE_FIELDS = ['closing_date', 'posted_date'];

    /** Date layouts understood when a source names none of its own (day first, as in Zambia). */
    public const DEFAULT_DATE_FORMATS = ['j F Y', 'j M Y', 'F j Y', 'M j Y', 'd/m/Y', 'j/n/Y', 'Y-m-d', 'j n Y'];

    private const MAX_TEXT = 100_000;

    private const BACKTRACK_LIMIT = '50000';

    /**
     * @param  array<string, mixed>  $rules
     * @return array{title:?string, employer:?string, values:array<string,?string>, warnings:list<string>}
     */
    public function extract(RawJobPost $post, array $rules, ?array $allowed = null): array
    {
        $allowed ??= self::FIELDS;
        $result = ['title' => $post->raw_title, 'employer' => null, 'values' => [], 'warnings' => []];

        $this->splitTitle($result, (string) ($rules['title_separator'] ?? ''));

        if ($post->raw_content === null) {
            $result['warnings'][] = 'Raw text was removed (retention), so only the title could be read.';

            return $result;
        }

        $text = $this->plainText($post->raw_content);

        foreach (($rules['fields'] ?? []) as $rule) {
            $field = $rule['field'] ?? null;
            if (! isset($allowed[$field]) || array_key_exists($field, $result['values']) && $result['values'][$field] !== null) {
                continue; // first rule that matches wins
            }

            $raw = $this->match((string) ($rule['pattern'] ?? ''), $text, $result['warnings'], ($rule['built_in'] ?? false) === true);
            $result['values'][$field] = $raw === null ? null : $this->finish($field, $raw, $rules['date_formats'] ?? [], $result['warnings']);
        }

        return $result;
    }

    /** @param array{title:?string, employer:?string} $result */
    private function splitTitle(array &$result, string $separator): void
    {
        $title = (string) $result['title'];

        if ($separator === '' || ($pos = strrpos($title, $separator)) === false) {
            return;
        }

        $employer = trim(substr($title, $pos + strlen($separator)));
        $job = trim(substr($title, 0, $pos));

        if ($job !== '' && $employer !== '') {
            $result['title'] = $job;
            $result['employer'] = $employer;
        }
    }

    private function plainText(string $html): string
    {
        $html = preg_replace('#<(br|/p|/li|/h\d|/div|/tr)\b[^>]*>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text);

        return mb_substr(trim($text), 0, self::MAX_TEXT);
    }

    /** @param list<string> $warnings */
    private function match(string $pattern, string $text, array &$warnings, bool $builtIn = false): ?string
    {
        if (ExtractionRules::patternProblem($pattern, $builtIn) !== null) {
            $warnings[] = "Skipped an invalid pattern: {$pattern}";

            return null;
        }

        $previous = ini_set('pcre.backtrack_limit', self::BACKTRACK_LIMIT);
        $found = @preg_match(ExtractionRules::wrap($pattern), $text, $m);
        ini_set('pcre.backtrack_limit', $previous === false ? '1000000' : $previous);

        if ($found === false) {
            $warnings[] = "Pattern was too slow and was stopped: {$pattern}";

            return null;
        }

        $value = trim($m[1] ?? '');

        return $found && $value !== '' ? $value : null;
    }

    /** @param list<string> $warnings */
    private function finish(string $field, string $raw, array $formats, array &$warnings): ?string
    {
        if (! in_array($field, self::DATE_FIELDS, true)) {
            return mb_substr($raw, 0, 255);
        }

        if ($date = self::parseDate($raw, $formats)) {
            return $date;
        }

        $warnings[] = "Could not read the date '{$raw}' with the given formats.";

        return null;
    }

    /**
     * A date written by a person, as Y-m-d, or null. Tries the text as it is, then with ordinals ("30th"),
     * commas and dashes/dots between the parts tidied, so "30th November, 2026" and "30-11-2026" read too.
     *
     * @param  list<string>  $formats
     */
    public static function parseDate(string $raw, array $formats): ?string
    {
        $tidy = trim(preg_replace('/\s+/', ' ', str_replace(',', ' ', preg_replace('/(\d)(?:st|nd|rd|th)\b/i', '$1', $raw))));
        $candidates = array_values(array_unique([$raw, $tidy, preg_replace('/\s+/', ' ', preg_replace('/[\-.]+/', ' ', $tidy))]));

        foreach ($candidates as $text) {
            foreach ($formats as $format) {
                $date = \DateTimeImmutable::createFromFormat('!'.$format, $text);
                $errors = \DateTimeImmutable::getLastErrors(); // false when there were no problems (PHP 8.2+)

                // A warning means PHP "fixed" the input, e.g. 31/02 -> 3 March. Treat that as unreadable.
                if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                    return $date->format('Y-m-d');
                }
            }
        }

        return null;
    }
}
