<?php

namespace App\Standardization;

/**
 * "BSc Degree", "Bachelor of Science" and "Bachelor's" all become Bachelor's Degree; "Bachelor's in Accounting"
 * becomes Bachelor's Degree in Accounting. The original text is never touched.
 *
 * A person's correction for the exact text wins. When the text names two different levels ("Diploma or Degree")
 * it is ambiguous and is left for a person rather than guessed.
 */
class QualificationParser
{
    /** Levels that can stand alone. "degree" on its own and the professional bodies are fallbacks. */
    private const SPECIFIC = ['school', 'certificate', 'diploma', 'bachelor', 'postgraduate', 'masters', 'phd'];

    /** "Bachelor of Science" names the degree, not the field of study. */
    private const NOT_A_FIELD = ['science', 'arts', 'applied science', 'social science', 'social sciences', 'sciences'];

    public function __construct(private TermDictionary $terms) {}

    /**
     * @return array{status:string, key?:string, level?:?string, rank?:?int, label?:?string, field?:?string, reason?:string}
     *         status: empty | ok | ignored | unknown | ambiguous
     */
    public function parse(?string $text): array
    {
        $text = trim((string) $text);
        if ($text === '') {
            return ['status' => 'empty'];
        }

        $key = Text::normalize($text);
        $out = ['key' => $key];

        if ($mapped = $this->terms->find('qualification', $key)) {
            if ($mapped->meta['ignore'] ?? false) {
                return $out + ['status' => 'ignored'];
            }

            return $out + $this->result($mapped->meta['level'] ?? null, $mapped->meta['field'] ?? null) + ['status' => 'ok'];
        }

        $found = $this->levelsIn($key);
        $specific = array_values(array_intersect(array_keys($found), self::SPECIFIC));

        // "Diploma or Degree" names two levels even though "degree" alone is only a fallback.
        if (isset($found['degree']) && array_intersect($specific, ['school', 'certificate', 'diploma'])) {
            $specific[] = 'degree';
        }

        if (count($specific) > 1) {
            return $out + ['status' => 'ambiguous', 'reason' => 'names more than one level: '.implode(', ', $specific)];
        }

        $level = $specific[0] ?? (isset($found['degree']) ? 'degree' : (isset($found['professional']) ? 'professional' : null));

        if ($level === null) {
            return $out + ['status' => 'unknown'];
        }

        return $out + $this->result($level, $level === 'professional' ? null : $this->field($text, $level)) + ['status' => 'ok'];
    }

    /** @return array{level:string, rank:?int, label:string, field:?string} */
    private function result(?string $level, ?string $field): array
    {
        [$name, $rank] = DefaultTerms::LEVELS[$level] ?? [ucfirst((string) $level), null];

        return ['level' => $level, 'rank' => $rank, 'label' => $field ? "{$name} in {$field}" : $name, 'field' => $field];
    }

    /** @return array<string, true> the levels whose words appear, each phrase used up once so it cannot count twice */
    private function levelsIn(string $key): array
    {
        $rest = " {$key} ";
        $found = [];

        foreach ($this->terms->phrases('qualification_level') as $phrase => $level) {
            if (str_contains($rest, " {$phrase} ")) {
                $found[$level] = true;
                $rest = str_replace(" {$phrase} ", ' ', $rest);
            }
        }

        return $found;
    }

    /** The subject after "in" (or "of"), cut where the sentence moves on. Null when there is none or it says "any field". */
    private function field(string $text, string $level): ?string
    {
        $subject = null;

        if (preg_match('/\bin\s+(.+)$/isu', $text, $m)) {
            $subject = $m[1];
        } elseif (in_array($level, ['bachelor', 'masters', 'postgraduate'], true) && preg_match('/\bof\s+(.+)$/isu', $text, $m)) {
            $subject = $m[1];
            if (in_array(Text::normalize(preg_split('/\bin\b/i', $subject)[0]), self::NOT_A_FIELD, true)) {
                return null;
            }
        }

        if ($subject === null) {
            return null;
        }

        $subject = preg_split('/[;.(\n]|\s+(?:from|at|with|plus|preferably|or\s+(?:equivalent|related|any|similar|a\s+related)|and\s+(?:at\s+least|\d))\b/iu', $subject)[0];
        $subject = trim($subject, " \t,-:");
        $subject = trim(preg_replace('/\s+(?:etc|and\s+others)$/i', '', $subject), " ,");

        if ($subject === '' || preg_match('/^(?:any|another|other|relevant|related|similar|equivalent|a\s+(?:relevant|related|similar)|an?\s+(?:equivalent|appropriate|other))\b/i', $subject) || mb_strlen($subject) > 120) {
            return null;
        }

        return mb_strtolower($subject) === $subject ? mb_convert_case($subject, MB_CASE_TITLE) : $subject;
    }
}
