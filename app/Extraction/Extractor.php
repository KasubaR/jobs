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

    public const DATE_FIELDS = ['closing_date', 'posted_date'];

    private const MAX_TEXT = 100_000;

    private const BACKTRACK_LIMIT = '50000';

    /**
     * @param  array<string, mixed>  $rules
     * @return array{title:?string, employer:?string, values:array<string,?string>, warnings:list<string>}
     */
    public function extract(RawJobPost $post, array $rules): array
    {
        $result = ['title' => $post->raw_title, 'employer' => null, 'values' => [], 'warnings' => []];

        $this->splitTitle($result, (string) ($rules['title_separator'] ?? ''));

        if ($post->raw_content === null) {
            $result['warnings'][] = 'Raw text was removed (retention), so only the title could be read.';

            return $result;
        }

        $text = $this->plainText($post->raw_content);

        foreach (($rules['fields'] ?? []) as $rule) {
            $field = $rule['field'] ?? null;
            if (! isset(self::FIELDS[$field]) || array_key_exists($field, $result['values']) && $result['values'][$field] !== null) {
                continue; // first rule that matches wins
            }

            $raw = $this->match((string) ($rule['pattern'] ?? ''), $text, $result['warnings']);
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
    private function match(string $pattern, string $text, array &$warnings): ?string
    {
        if (ExtractionRules::patternProblem($pattern) !== null) {
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

        foreach ($formats as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $raw);
            $errors = \DateTimeImmutable::getLastErrors(); // false when there were no problems (PHP 8.2+)

            // A warning means PHP "fixed" the input, e.g. 31/02 -> 3 March. Treat that as unreadable.
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }

        $warnings[] = "Could not read the date '{$raw}' with the given formats.";

        return null;
    }
}
