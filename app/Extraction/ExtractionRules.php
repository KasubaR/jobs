<?php

namespace App\Extraction;

/** Validation for the extraction rules a person types into the source form. */
class ExtractionRules
{
    public const MAX_PATTERN_LENGTH = 200;

    /** Pattern text (no delimiters) -> a full regex. Case-insensitive, unicode, dot matches newlines no. */
    public static function wrap(string $pattern): string
    {
        return '~'.str_replace('~', '\~', $pattern).'~iu';
    }

    /** A reason the pattern is unusable, or null when it is fine. */
    public static function patternProblem(string $pattern, bool $builtIn = false): ?string
    {
        if (trim($pattern) === '') {
            return 'The pattern is empty.';
        }

        // The length limit is for patterns people type; the built-in rules (DefaultRules) are longer and fixed.
        if (! $builtIn && strlen($pattern) > self::MAX_PATTERN_LENGTH) {
            return 'The pattern is longer than '.self::MAX_PATTERN_LENGTH.' characters.';
        }

        if (@preg_match(self::wrap($pattern), '') === false) {
            return 'The pattern is not a valid regular expression.';
        }

        if (@preg_match_all('~(?<!\\\\)\((?!\?)~', $pattern) < 1 && ! preg_match('~\(\?<\w+>~', $pattern)) {
            return 'The pattern needs a capture group, e.g. closing date:\s*(.+).';
        }

        // Nested unbounded repeats such as (a+)+ can run for a very long time.
        if (preg_match('~\((?:[^()\\\\]|\\\\.)*[+*](?:[^()\\\\]|\\\\.)*\)[+*{]~', $pattern)) {
            return 'Nested repeats like (a+)+ are not allowed because they can hang.';
        }

        return null;
    }

    /** @return list<string> human-readable problems with a whole rules array (empty = fine) */
    public static function validate(array $rules): array
    {
        $problems = [];

        $separator = $rules['title_separator'] ?? '';
        if ($separator !== '' && mb_strlen($separator) > 20) {
            $problems[] = 'The title separator is longer than 20 characters.';
        }

        foreach (($rules['date_formats'] ?? []) as $format) {
            if (! is_string($format) || $format === '' || strlen($format) > 30) {
                $problems[] = 'A date format is empty or too long.';
            }
        }

        foreach (($rules['fields'] ?? []) as $i => $rule) {
            $n = $i + 1;

            if (! isset(Extractor::FIELDS[$rule['field'] ?? ''])) {
                $problems[] = "Rule {$n}: unknown field.";
            }

            if ($problem = self::patternProblem((string) ($rule['pattern'] ?? ''))) {
                $problems[] = "Rule {$n}: {$problem}";
            }
        }

        return $problems;
    }
}
