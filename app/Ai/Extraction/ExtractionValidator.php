<?php

namespace App\Ai\Extraction;

/**
 * Checks an AI answer against the schema and the ad it was given, and keeps only what holds up.
 *
 * The model is asked to follow the rules in QualificationSchema; this class does not rely on that. Whatever the model
 * returns, an item is kept only if:
 *   - it has the right shape and allowed values,
 *   - the wording it quotes (original_text) is really in the ad, so nothing is invented,
 *   - it is not work experience dressed as a qualification,
 *   - its strength is not stronger than its own wording allows ("preferred" is never kept as mandatory),
 *   - a registration or skill is named in the ad, and a field of study appears in the quoted wording.
 * Rejected items are returned with the reason, never silently lost. Nothing is mapped to the taxonomy here.
 */
class ExtractionValidator
{
    /** Below this an item is kept but marked for a person to check. */
    public const MIN_CONFIDENCE = 0.6;

    private const LEVEL_ALIASES = [
        'bachelors' => 'bachelor', 'bachelors_degree' => 'bachelor', 'bachelor_degree' => 'bachelor', 'degree' => 'bachelor', 'undergraduate' => 'bachelor',
        'masters_degree' => 'masters', 'master' => 'masters', 'master_degree' => 'masters',
        'doctorate' => 'phd', 'doctoral' => 'phd', 'doctorate_degree' => 'phd',
        'post_graduate' => 'postgraduate', 'postgraduate_diploma' => 'postgraduate', 'postgraduate_certificate' => 'postgraduate',
        'grade_12' => 'school', 'grade12' => 'school', 'secondary' => 'school', 'high_school' => 'school', 'school_certificate' => 'school',
        'advanced_diploma' => 'diploma', 'higher_diploma' => 'diploma',
    ];

    private const TYPE_ALIASES = [
        'required' => 'mandatory', 'essential' => 'mandatory', 'must' => 'mandatory',
        'desirable' => 'preferred', 'desired' => 'preferred',
        'added_advantage' => 'advantage', 'asset' => 'advantage', 'optional' => 'advantage',
    ];

    private const STRONG_CUE = '/\b(must|required|requires?|essential|mandatory|minimum|should (?:hold|have|possess)|need(?:s)? to (?:have|hold)|compulsory)\b/i';

    private const WEAK_CUE = '/\b(preferred|preferably|desirable|desired|ideally|added advantage|an advantage|is an asset|an asset|would be considered|will be considered|beneficial|a plus)\b/i';

    private const NUMBER_WORDS = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10, 'twelve' => 12, 'fifteen' => 15, 'twenty' => 20];

    /** @var list<array{section:string, reason:string, item:mixed}> */
    private array $rejected = [];

    /** @var list<string> */
    private array $corrections = [];

    public function validate(mixed $response, string $adText): ValidatedExtraction
    {
        $this->rejected = $this->corrections = [];

        if (! is_array($response) || array_is_list($response) && $response !== []) {
            return ValidatedExtraction::failed('The answer is not a JSON object.');
        }
        if (array_intersect(self::keys(), array_keys($response)) === []) {
            return ValidatedExtraction::failed('The answer does not follow the schema (none of its sections are present).');
        }

        $ad = $this->norm($adText);
        $out = [];

        foreach (QualificationSchema::SECTIONS as $section) {
            $items = $response[$section] ?? [];
            if (! is_array($items) || ($items !== [] && ! array_is_list($items))) {
                $this->reject($section, 'The section is not a list.', $items);
                $out[$section] = [];

                continue;
            }
            $kept = [];
            foreach ($items as $item) {
                $clean = is_array($item) ? $this->item($section, $item, $ad) : null;
                $is = is_array($item);
                $is || $this->reject($section, 'The item is not an object.', $item);
                if ($clean !== null && ! $this->isDuplicate($kept, $clean)) {
                    $kept[] = $clean;
                }
            }
            $out[$section] = $kept;
        }

        $found = $out['qualifications'] !== [];
        if (($response['qualification_found'] ?? null) !== $found) {
            $this->corrections[] = 'qualification_found was set to '.($found ? 'true' : 'false').' to match the items that held up.';
        }

        $notes = [];
        foreach (is_array($response['extraction_notes'] ?? null) ? $response['extraction_notes'] : [] as $note) {
            if (is_string($note) && trim($note) !== '' && count($notes) < 10) {
                $notes[] = mb_substr(trim($note), 0, 200);
            }
        }

        $data = ['qualification_found' => $found] + $out + ['extraction_notes' => $notes];

        return new ValidatedExtraction(true, null, $data, $this->rejected, $this->corrections);
    }

    private function item(string $section, array $in, string $ad): ?array
    {
        // ---- the parts every item has ----
        $quote = $in['original_text'] ?? null;
        if (! is_string($quote) || trim($quote) === '') {
            return $this->reject($section, 'No original_text: the wording from the advert is missing.', $in);
        }
        $quote = trim($quote);
        if (mb_strlen($quote) > QualificationSchema::MAX_QUOTE) {
            return $this->reject($section, 'original_text is longer than '.QualificationSchema::MAX_QUOTE.' characters.', $in);
        }
        if (! str_contains($ad, $this->norm($quote))) {
            return $this->reject($section, 'The quoted wording is not in the advert.', $in);
        }

        $type = $this->type($in['requirement_type'] ?? null);
        if ($type === null) {
            return $this->reject($section, 'Unknown requirement_type.', $in);
        }
        $confidence = $this->confidence($in['confidence'] ?? null);
        if ($confidence === null) {
            return $this->reject($section, 'confidence is missing or outside 0 to 1.', $in);
        }

        $group = $in['alternative_group'] ?? null;
        $group = is_int($group) && $group >= 1 ? $group : null;

        $review = [];
        $strong = preg_match(self::STRONG_CUE, $quote) === 1;
        $weak = preg_match(self::WEAK_CUE, $quote, $m) === 1;
        if ($type === 'mandatory' && $weak && ! $strong) {
            $type = preg_match('/advantage|asset|considered|beneficial|plus/i', $m[0]) ? 'advantage' : 'preferred';
            $this->corrections[] = "Lowered to {$type}: the advert's own wording says \"{$m[0]}\".";
        } elseif ($type === 'mandatory' && $weak && $strong) {
            $review[] = 'The wording has both a "required" and a "preferred" cue.';
        }
        if ($confidence < self::MIN_CONFIDENCE) {
            $review[] = 'Low confidence.';
        }

        $base = ['requirement_type' => $type, 'alternative_group' => $group, 'original_text' => $quote, 'confidence' => $confidence];

        $own = match ($section) {
            'qualifications' => $this->qualification($in, $quote, $section),
            'professional_registrations' => $this->named($in, $ad, $section),
            'skills' => $this->skill($in, $ad, $section),
            'experience_requirements' => $this->experience($in, $quote, $section),
            default => $this->other($in, $section),
        };
        if ($own === null) {
            return null;
        }

        return $own + $base + ['needs_review' => $review !== [], 'review_reasons' => $review];
    }

    private function qualification(array $in, string $quote, string $section): ?array
    {
        $name = $this->text($in['name'] ?? null, 150);
        if ($name === null) {
            return $this->reject($section, 'No name.', $in);
        }

        // Rule: no "or equivalent". Two named qualifications are never made one.
        $clean = trim(preg_replace('/[\s,;]*\b(?:or|and\/or)?\s*(?:an?\s+)?equivalent(?:\s+qualification)?\b/i', '', $name), " \t,;");
        if ($clean !== $name && $clean !== '') {
            $this->corrections[] = "Removed \"equivalent\" from the name \"{$name}\": the advert's qualification is not treated as equal to another.";
            $name = $clean;
        }

        $field = isset($in['field']) && is_string($in['field']) ? mb_strtolower(trim($in['field'])) : null;
        $field = $field === '' ? null : ($field === null ? null : mb_substr($field, 0, 100));
        $level = $this->level($in['level'] ?? null);

        // Rule: work experience is not a qualification.
        $asksYears = preg_match('/\b(\d+|'.implode('|', array_keys(self::NUMBER_WORDS)).')\s*\+?\s*(?:\(\d+\)\s*)?(?:years?|yrs?)\b/i', $quote) === 1;
        $namesAward = preg_match('/\b(degree|diploma|certificate|bachelor|master|doctorate|phd|b\.?sc|b\.?a|b\.?com|beng|mba|licen[cs]e|trade test|a-level|o-level|grade\s*\d+|national|advanced|higher)\b/i', $name.' '.$quote) === 1;
        if (preg_match('/\bexperience\b/i', $name) || ($level === null && $field === null && $asksYears && ! $namesAward)) {
            return $this->reject($section, 'This is work experience, not a qualification.', $in);
        }

        // A field of study the quoted wording never mentions was added by the model.
        if ($field !== null && ! $this->mentions($quote.' '.$name, $field)) {
            $this->corrections[] = "Removed the field \"{$field}\": it does not appear in the quoted wording.";
            $field = null;
        }

        return ['name' => $name, 'level' => $level, 'field' => $field];
    }

    private function named(array $in, string $ad, string $section): ?array
    {
        $name = $this->text($in['name'] ?? null, 150);
        if ($name === null) {
            return $this->reject($section, 'No name.', $in);
        }
        if (! str_contains($ad, $this->norm($name))) {
            return $this->reject($section, 'The name is not in the advert.', $in);
        }

        return ['name' => $name];
    }

    private function skill(array $in, string $ad, string $section): ?array
    {
        $own = $this->named($in, $ad, $section);
        if ($own === null) {
            return null;
        }
        $type = $in['type'] ?? null;
        if (! in_array($type, QualificationSchema::SKILL_TYPES, true)) {
            return $this->reject($section, 'Unknown skill type.', $in);
        }

        return $own + ['type' => $type];
    }

    private function experience(array $in, string $quote, string $section): ?array
    {
        $min = $this->years($in['years_min'] ?? null);
        $max = $this->years($in['years_max'] ?? null);
        if ($min === false || $max === false) {
            return $this->reject($section, 'A number of years is not a whole number from 0 to 40.', $in);
        }
        if ($min === null && $max === null) {
            return $this->reject($section, 'No number of years.', $in);
        }
        if ($min !== null && $max !== null && $min > $max) {
            return $this->reject($section, 'The minimum is more than the maximum.', $in);
        }
        foreach (array_filter([$min, $max], fn ($y) => $y !== null) as $y) {
            if (! $this->figureIn($quote, $y)) {
                return $this->reject($section, "The figure {$y} is not in the quoted wording.", $in);
            }
        }
        $area = isset($in['area']) && is_string($in['area']) && trim($in['area']) !== '' ? mb_substr(trim($in['area']), 0, 100) : null;

        return ['years_min' => $min, 'years_max' => $max, 'area' => $area];
    }

    private function other(array $in, string $section): ?array
    {
        $text = $this->text($in['description'] ?? null, 150);

        return $text === null ? $this->reject($section, 'No description.', $in) : ['description' => $text];
    }

    // ---- small helpers ----

    /** @return list<string> */
    private static function keys(): array
    {
        return ['qualification_found', ...QualificationSchema::SECTIONS];
    }

    private function reject(string $section, string $reason, mixed $item): null
    {
        $this->rejected[] = ['section' => $section, 'reason' => $reason, 'item' => $item];

        return null;
    }

    private function text(mixed $v, int $max): ?string
    {
        return is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, $max) : null;
    }

    private function type(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $v = str_replace([' ', '-'], '_', mb_strtolower(trim($v)));
        $v = self::TYPE_ALIASES[$v] ?? $v;

        return in_array($v, QualificationSchema::REQUIREMENT_TYPES, true) ? $v : null;
    }

    private function level(mixed $v): ?string
    {
        if (! is_string($v) || trim($v) === '') {
            return null;
        }
        $key = str_replace([' ', '-', "'"], ['_', '_', ''], mb_strtolower(trim($v)));
        $key = self::LEVEL_ALIASES[$key] ?? $key;
        if (in_array($key, QualificationSchema::LEVELS, true)) {
            return $key;
        }
        $this->corrections[] = "The level \"{$v}\" is not one of the allowed levels, so it was left empty.";

        return null;
    }

    private function confidence(mixed $v): ?float
    {
        if (! is_int($v) && ! is_float($v)) {
            return null;
        }

        return $v >= 0 && $v <= 1 ? round((float) $v, 2) : null;
    }

    /** @return int|false|null null = absent, false = invalid */
    private function years(mixed $v): int|false|null
    {
        if ($v === null) {
            return null;
        }

        return is_int($v) && $v >= 0 && $v <= 40 ? $v : false;
    }

    /** Whether the number appears in the wording, as digits or as a word ("five (5) years"). */
    private function figureIn(string $quote, int $years): bool
    {
        if (preg_match('/(?<!\d)'.$years.'(?!\d)/', $quote)) {
            return true;
        }
        $word = array_search($years, self::NUMBER_WORDS, true);

        return $word !== false && preg_match('/\b'.$word.'\b/i', $quote) === 1;
    }

    /** Whether every word of the subject (by its first five letters) is found in the text. */
    private function mentions(string $text, string $subject): bool
    {
        $text = $this->norm($text);
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $this->norm($subject), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            if (mb_strlen($word) >= 3 && ! str_contains($text, mb_substr($word, 0, 5))) {
                return false;
            }
        }

        return true;
    }

    private function isDuplicate(array $kept, array $item): bool
    {
        foreach ($kept as $k) {
            if (($k['name'] ?? $k['description'] ?? null) === ($item['name'] ?? $item['description'] ?? null) && $k['original_text'] === $item['original_text']
                && ($k['years_min'] ?? null) === ($item['years_min'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /** Text with quotes, dashes, spaces and case made alike, so a quote matches the advert whatever its typography. */
    public function norm(string $s): string
    {
        $s = strtr($s, ["\u{2018}" => "'", "\u{2019}" => "'", "\u{201C}" => '"', "\u{201D}" => '"', "\u{2013}" => '-', "\u{2014}" => '-', "\u{00A0}" => ' ']);

        return trim(mb_strtolower(preg_replace('/\s+/u', ' ', $s)), " \t\n.,;:");
    }
}
