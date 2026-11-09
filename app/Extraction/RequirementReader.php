<?php

namespace App\Extraction;

use App\Standardization\ExperienceParser;
use App\Standardization\QualificationParser;
use App\Taxonomy\TaxonomyIndex;

/**
 * Phase 7, step 3: reads what an ad ASKS FOR out of its text. Pure reading: no database writes, no network.
 *
 * For every sentence it works out (1) how strongly the ad asks (required, preferred, advantage, mentioned) from the
 * section the sentence sits in and from cue words in the sentence itself, then (2) what is asked:
 *   - a named award or credential the taxonomy knows (BEng, ACCA, an EIZ registration)
 *   - an education level, with a field of study when one is given ("Degree in Accounting and Finance")
 *   - skills, and years of experience
 * Wording the taxonomy does not know is kept as `candidate_text`, never forced into the nearest entry. A
 * sentence naming two levels it cannot untangle is kept as a candidate for a person to look at.
 * Every item keeps the ad's own sentence as `evidence`.
 *
 * Either-or wording ("ACCA or CIMA", "Diploma or Degree in X") puts the items in one alternative group. Groups are
 * numbered 1, 2, 3 ... within the ad. A sentence is grouped per construct: the named credentials together, the
 * level clauses together, and the fields of one clause together.
 */
class RequirementReader
{
    public const VERSION = 1;

    private const RANK = ['mentioned' => 0, 'advantage' => 1, 'preferred' => 2, 'required' => 3];

    /** The parser's "degree" on its own means a first degree or higher; stored as bachelor with less confidence. */
    private const TO_EDUCATION_LEVEL = ['degree' => 'bachelor'];

    private const BULLET = '/^\s*(?:[-•*·▪●◦–]|\d{1,2}[.)]|[a-z][.)])\s+/u';

    /** @var array<string, int>|null field key => id */
    private ?array $fieldIds = null;

    public function __construct(
        private TaxonomyIndex $taxonomy,
        private QualificationParser $qualifications,
        private ExperienceParser $experience,
    ) {}

    /**
     * @param  ?string  $body  the ad as plain text (null when it is no longer stored)
     * @param  ?string  $publishedEducation  the education the ad published as data, when it did
     * @param  ?string  $publishedExperience  the experience the ad published as data, when it did
     * @return array{qualifications: list<array<string,mixed>>, skills: list<array<string,mixed>>, experiences: list<array<string,mixed>>, review: bool, read_text: bool}
     */
    public function read(?string $body, ?string $publishedEducation = null, ?string $publishedExperience = null): array
    {
        $this->fieldIds ??= collect($this->taxonomy->all('field'))->pluck('id', 'key')->all();

        $state = (object) ['group' => 0, 'qualifications' => [], 'skills' => [], 'experiences' => [], 'review' => false];

        foreach ([[$publishedEducation, 'published'], [$publishedExperience, 'published']] as [$text, $source]) {
            if ($text !== null && trim($text) !== '') {
                $this->sentence($state, trim($text), 'requirements', $source);
            }
        }

        $readText = $body !== null && trim($body) !== '';
        if ($readText) {
            $section = null;

            foreach (preg_split('/\R/u', $body) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                [$section, $rest] = $this->heading($line, $section);

                foreach ($rest === '' ? [] : preg_split('/(?<=[.;!?])\s+(?=[A-Z0-9(])/u', $rest) as $sentence) {
                    $this->sentence($state, trim($sentence), $section, 'rule');
                }
            }
        }

        return [
            'qualifications' => array_values($state->qualifications),
            'skills' => array_values($state->skills),
            'experiences' => array_values($state->experiences),
            'review' => $state->review,
            'read_text' => $readText,
        ];
    }

    // ---- sections ----

    /** @return array{0: ?string, 1: string} the section now in force, and the text of the line that is not a heading */
    private function heading(string $line, ?string $section): array
    {
        $plain = preg_replace(self::BULLET, '', $line);
        $isBullet = $plain !== $line;

        if (preg_match('/^(.{2,60}?)\s*:\s*(.*)$/u', $plain, $m) && ($found = $this->sectionOf($m[1])) !== null) {
            return [$found, trim($m[2])];
        }

        if (! $isBullet && mb_strlen($plain) <= 50 && ! preg_match('/[.;]$/', $plain) && str_word_count($plain) <= 6 && ($found = $this->sectionOf($plain)) !== null) {
            return [$found, ''];
        }

        return [$section, $plain];
    }

    private function sectionOf(string $label): ?string
    {
        $l = mb_strtolower($label);

        return match (true) {
            (bool) preg_match('/how to apply|to apply|application|about (?:us|the (?:company|organi[sz]ation))|benefits|closing date|deadline|salary|remuneration|contact/', $l) => 'ignore',
            (bool) preg_match('/added advantage|advantage|nice to have|bonus|\bplus\b/', $l) => 'advantage',
            (bool) preg_match('/desirable|preferred|desired/', $l) => 'preferred',
            (bool) preg_match('/responsibilit|duties|key tasks|what you.?ll do|what you will do|job purpose|role purpose|the role|job description/', $l) => 'duties',
            (bool) preg_match('/requirement|qualification|eligibility|who you are|looking for|essential|minimum|person specification|competenc|experience|skills|education/', $l) => 'requirements',
            default => null,
        };
    }

    /** How strongly this sentence asks: its own cue words first, then its section. Null when nothing says. */
    private function levelOf(string $s, ?string $section): ?string
    {
        return match (true) {
            (bool) preg_match('/\b(?:an?|added|the)\s+(?:added\s+)?(?:advantage|asset)\b|\badvantageous\b|\bwould\s+be\s+(?:an?\s+)?(?:plus|advantage|asset)\b/i', $s) => 'advantage',
            (bool) preg_match('/\b(?:preferred|preferably|desirable|desired|ideally|nice\s+to\s+have)\b/i', $s) => 'preferred',
            (bool) preg_match('/\b(?:must|required|requires?|essential|mandatory|minimum|at\s+least|should\s+(?:have|hold|possess)|needs?\s+to)\b/i', $s) => 'required',
            $section === 'requirements' => 'required',
            $section === 'preferred' => 'preferred',
            $section === 'advantage' => 'advantage',
            $section === 'duties' => 'mentioned',
            default => null,
        };
    }

    // ---- one sentence ----

    private function sentence(object $state, string $s, ?string $section, string $source): void
    {
        if ($s === '' || $section === 'ignore') {
            return;
        }

        $level = $this->levelOf($s, $section);
        $weak = $level === null;            // no section and no cue: kept, but less trusted
        $evidence = mb_substr($s, 0, 300);
        $base = ['source' => $source, 'evidence' => $evidence];

        // skills are read everywhere; unlabelled or duty text only mentions them
        $skillLevel = $level ?? 'mentioned';
        foreach ($this->taxonomy->mentions('skill', $s) as $skill) {
            $this->addSkill($state, $skill->id, $skillLevel, $base + ['confidence' => $this->score($skillLevel === 'mentioned' ? 50 : 72, $source)]);
        }

        if ($level === 'mentioned') {
            return; // a duty is not a requirement
        }

        $asked = $level ?? 'required';
        $this->qualificationsIn($state, $s, $asked, $weak, $base);
        $this->experienceIn($state, $s, $asked, $weak, $base);
    }

    // ---- qualifications ----

    private function qualificationsIn(object $state, string $s, string $level, bool $weak, array $base): void
    {
        $source = $base['source'];
        $named = [];
        $tables = ['award' => 'academic_award_id', 'professional' => 'professional_qualification_id', 'registration' => 'professional_registration_id'];
        foreach ($tables as $kind => $column) {
            foreach ($this->taxonomy->mentions($kind, $s) as $entry) {
                if ($kind === 'award' && ! $entry->field_key) {
                    continue; // BSc, BA, MSc ... only say the level; the level + field reading below covers them, so "BSc in Accountancy" and "Degree in Accounting" are one item
                }
                $named[] = [$kind, $column, $entry];
            }
        }

        // education level, and field of study when given
        $clean = preg_replace('/\b(?:high|good|strong|certain|some|reasonable|great)\s+degree\s+of\b/i', ' ', $s);
        $clean = preg_replace('/\b(?:certificate|diploma|licen[cs]e)\s+of\s+(?:good\s+conduct|incorporation|competen[cs]e|clearance)\b|\bpolice\s+clearance\b/i', ' ', $clean);
        $clauses = preg_split('/\s+(?:or|and\/or|\/)\s+(?=(?:an?\s+)?(?:bachelor|degree|diploma|certificate|master|msc|mba|phd|doctorate|bsc|grade\s*12|form\s*5))/iu', $clean);

        $parsed = [];
        foreach ($clauses as $clause) {
            $p = $this->qualifications->parse($clause);
            if ($p['status'] === 'ambiguous' && preg_match('/\d+\s*(?:years?|yrs?)/i', $clause)) {
                continue; // "5 years (with a diploma) or 2 years (with a degree)" is about experience, not a qualification
            }
            if ($p['status'] === 'ambiguous') {
                $state->review = true;
                $this->addQualification($state, $base + [
                    'education_level' => null, 'field_id' => null, 'candidate_text' => mb_substr($clause, 0, 255),
                    'requirement_level' => $level, 'alternative_group' => null, 'confidence' => $this->score(35, $source),
                ]);
            } elseif ($p['status'] === 'ok' && ($p['level'] ?? null) !== 'professional') {
                $parsed[] = $p;
            }
        }

        // "Degree or Diploma in Accounting": a clause with no field takes the field of the one that has it
        $sharedField = null;
        foreach ($parsed as $p) {
            $sharedField ??= $p['field'] ?? null;
        }

        $refined = [];
        $clauseGroup = count($parsed) > 1 ? ++$state->group : null;
        foreach ($parsed as $p) {
            $educationLevel = self::TO_EDUCATION_LEVEL[$p['level']] ?? $p['level'];
            $fieldText = $p['field'] ?? ($clauseGroup !== null ? $sharedField : null);
            if ($fieldText !== null) {
                $fieldText = trim(preg_replace('/\s+or\s+(?:its|their|an?|any)\s+equivalent.*$/iu', '', $fieldText), ' ,');
                $fieldText = $fieldText === '' || $educationLevel === 'school' ? null : $fieldText;   // "Grade 12 with credit in Maths" names subjects, not a field of study
            }
            $certain = $p['level'] !== 'degree';
            $fields = $fieldText === null ? [] : $this->fieldsIn($fieldText);
            $fieldGroup = count($fields) > 1 ? ++$state->group : null;

            if ($fieldText !== null && $fields === []) {
                $state->review = true; // a field the taxonomy does not know: kept as wording, not forced onto an entry
                $this->addQualification($state, $base + [
                    'education_level' => $educationLevel, 'field_id' => null, 'candidate_text' => mb_substr($fieldText, 0, 255),
                    'requirement_level' => $level, 'alternative_group' => $clauseGroup, 'confidence' => $this->score(50, $source),
                ]);

                continue;
            }

            foreach ($fields ?: [null] as $field) {
                if ($award = $this->coveredByNamedAward($named, $educationLevel, $field)) {
                    if ($field !== null) {
                        $refined[$award->id][] = $field->id;   // "BEng in Mechanical Engineering": the award, with the narrower field
                    }

                    continue;
                }

                $this->addQualification($state, $base + [
                    'education_level' => $educationLevel, 'field_id' => $field?->id, 'candidate_text' => null,
                    'requirement_level' => $level, 'alternative_group' => $fieldGroup ?? $clauseGroup,
                    'confidence' => $this->score(($field ? 75 : ($certain ? 70 : 60)) - ($weak ? 10 : 0) - ($certain ? 0 : 5), $source),
                ]);
            }
        }
        $group = count($named) > 1 && preg_match('/\bor\b|\//i', $s) ? ++$state->group : null;
        foreach ($named as [$kind, $column, $entry]) {
            $own = $kind === 'award' && $entry->field_key ? ($this->fieldIds[$entry->field_key] ?? null) : null;
            $fieldIds = array_values(array_unique($refined[$entry->id] ?? [])) ?: [$own];   // "BSc in Accounting or Finance": the award, once per field
            $fieldGroup = count($fieldIds) > 1 ? ++$state->group : $group;

            foreach ($fieldIds as $fieldId) {
                $this->addQualification($state, $base + [
                    'named_kind' => $kind, 'named_key' => $entry->key, $column => $entry->id,
                    'education_level' => $kind === 'award' ? $entry->level : null, 'field_id' => $fieldId,
                    'candidate_text' => null, 'requirement_level' => $level, 'alternative_group' => $fieldGroup,
                    'confidence' => $this->score($weak ? 75 : 85, $source),
                ]);
            }
        }
    }

    /** "Bachelor of Engineering" is already the named award BEng; do not also record it as "bachelor, engineering". Returns that award. */
    private function coveredByNamedAward(array $named, string $educationLevel, ?object $field): ?object
    {
        $fields = $this->taxonomy->all('field');

        foreach ($named as [$kind, , $entry]) {
            if ($kind !== 'award' || $entry->level !== $educationLevel) {
                continue;
            }
            $awardField = $entry->field_key ? ($this->fieldIds[$entry->field_key] ?? null) : null;
            $narrower = $field !== null && $awardField !== null && ($fields[$field->id]->parent_id ?? null) === $awardField;

            if ($field === null || $field->id === $awardField || $narrower) {
                return $entry;
            }
        }

        return null;
    }

    /** @return list<object> */
    private function fieldsIn(string $text): array
    {
        $one = $this->taxonomy->find('field', $text);

        return $one ? [$one] : $this->taxonomy->mentions('field', $text);
    }

    // ---- experience ----

    private function experienceIn(object $state, string $s, string $level, bool $weak, array $base): void
    {
        if (! preg_match('/\bexperience\b|\bexp\b|\bworked\b|\btrack\s+record\b/i', $s)) {
            return; // "a 2 year contract" is not experience
        }
        if (preg_match('/^(?:with|after|over|for)\s+(?:more\s+than\s+|over\s+)?\d+\s+years/i', $s)) {
            return; // "With 75 years of experience, our focus is..." is the employer talking about itself
        }

        $p = $this->experience->parse($s);
        if ($p['status'] === 'ambiguous') {
            $state->review = true;

            return;
        }
        if ($p['status'] !== 'ok' || ! isset($p['min'])) {
            return;
        }

        // the table counts whole years: 6 months or more rounds to a year, anything shorter is not recorded
        $min = (int) round($p['min'] / 12);
        $max = isset($p['max']) ? (int) round($p['max'] / 12) : null;
        if ($min === 0 && $p['min'] > 0) {
            return;
        }

        $area = preg_match('/\bexperience\s+(?:in|with|as|of)\s+(?:an?\s+|the\s+)?([^.;,()]{3,100})/iu', $s, $m) ? trim($m[1]) : null;
        $max = $max !== null && $max !== $min ? $max : null;
        $key = $min.'|'.($max ?? '').'|'.mb_strtolower((string) $area);

        $row = $base + ['min_years' => $min, 'max_years' => $max, 'area' => $area,
            'requirement_level' => $level, 'alternative_group' => null, 'confidence' => $this->score($weak ? 65 : 80, $base['source'])];

        $this->keep($state->experiences, $key, $row);
    }

    // ---- collecting without duplicates ----

    private function addQualification(object $state, array $row): void
    {
        $named = $row['named_kind'] ?? null;
        $key = implode('|', [$named ? $named.':'.$row['named_key'] : '', $row['education_level'] ?? '', $row['field_id'] ?? '', mb_strtolower((string) ($row['candidate_text'] ?? ''))]);
        $this->keep($state->qualifications, $key, $row);
    }

    private function addSkill(object $state, int $skillId, string $level, array $base): void
    {
        $this->keep($state->skills, (string) $skillId, $base + ['skill_id' => $skillId, 'requirement_level' => $level]);
    }

    /** The same item twice keeps the stronger requirement, and the ad's own published data over our reading. */
    private function keep(array &$items, string $key, array $row): void
    {
        $row['key'] = $key;
        $old = $items[$key] ?? null;

        if ($old === null
            || self::RANK[$row['requirement_level']] > self::RANK[$old['requirement_level']]
            || ($row['source'] === 'published' && $old['source'] !== 'published' && self::RANK[$row['requirement_level']] === self::RANK[$old['requirement_level']])) {
            $items[$key] = $row;
        }
    }

    /** What the ad published as data it vouches for itself, so it scores a little higher. */
    private function score(int $confidence, string $source): int
    {
        return max(0, min(95, $confidence + ($source === 'published' ? 10 : 0)));
    }
}
