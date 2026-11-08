<?php

namespace App\Processing;

use App\Extraction\DefaultRules;
use App\Extraction\Extractor;
use App\Models\RawJobPost;
use RuntimeException;

/**
 * Reads the fields of a job out of one stored ad. Pure reading: no database writes, no network.
 *
 * Evidence, strongest first, per field:
 *   1. structured  - the job data the ad published about itself (JobPosting, API fields, import columns)
 *   2. rule        - the source's own extraction rules, then the built-in label rules, over the ad text
 *   3. title       - the ad's title
 * A field with no evidence stays null. Each value remembers which kind of evidence gave it.
 */
class JobExtractor
{
    public function __construct(private Extractor $extractor) {}

    /**
     * @return array{
     *   fields: array<string, mixed>,
     *   sources: array<string, string>,
     *   warnings: list<string>,
     *   confidence: int,
     *   needs_review: bool
     * }
     */
    public function extract(RawJobPost $post): array
    {
        $facts = is_array($post->structured_data) ? $post->structured_data : [];
        $rules = DefaultRules::merge((array) ($post->source?->collector_config['extraction'] ?? []));
        $read = $this->extractor->extract($post, $rules, Extractor::FIELDS + Extractor::EXTRA_FIELDS);
        $text = $read['values'];
        $formats = $rules['date_formats'];
        $warnings = $read['warnings'];

        $title = $this->clean($facts['title'] ?? null, 500);
        $titleFrom = 'structured';
        if ($title === null) {
            $title = $this->clean($read['title'], 500);
            $titleFrom = $read['employer'] !== null && $title !== $this->clean($post->raw_title, 500) ? 'rule' : 'title';
        }
        if ($title === null) {
            $title = $this->clean($this->firstLine($post), 500);
            $titleFrom = 'text';
        }
        if ($title === null) {
            throw new RuntimeException('The ad has no title and no text to take one from.');
        }

        $values = [];
        $sources = ['title' => $titleFrom];
        $set = function (string $field, mixed $structured, mixed $fromText, ?string $textKind = 'rule') use (&$values, &$sources) {
            foreach (['structured' => $structured, $textKind => $fromText] as $kind => $value) {
                if ($value !== null && $value !== '') {
                    $values[$field] = $value;
                    $sources[$field] = $kind;

                    return;
                }
            }
            $values[$field] = null;
        };

        $set('employer_text', $this->clean($facts['employer'] ?? null, 200), $this->clean($read['employer'], 200) ?? $this->clean($text['employer'] ?? null, 200));
        $set('location_text', $this->location($facts), $this->clean($text['location'] ?? null, 255));
        $set('employment_type', $this->clean($this->listText($facts['employment_type'] ?? null), 30), $this->clean($text['employment_type'] ?? null, 30));
        $set('qualification_text', $this->clean($this->listText($facts['education'] ?? $facts['qualifications'] ?? null), 255), $this->clean($text['qualification_text'] ?? null, 255));
        $set('experience_text', $this->clean($this->listText($facts['experience'] ?? null), 255), $this->clean($text['experience_text'] ?? null, 255));
        $set('application_url', $this->url($facts['application_url'] ?? $facts['apply_url'] ?? null), $this->url($text['application_url'] ?? null));
        $set('closing_date', $this->date($facts['valid_through'] ?? $facts['closing_date'] ?? $facts['deadline'] ?? null, $formats), $this->date($text['closing_date'] ?? null, $formats));
        $set('posted_date', $this->date($facts['date_posted'] ?? $facts['posted_date'] ?? null, $formats), $this->date($text['posted_date'] ?? null, $formats));

        [$salaryText, $salaryMin, $salaryMax, $currency] = $this->salary($facts['salary'] ?? null);
        $values['salary_text'] = $salaryText ?? $this->clean($text['salary_text'] ?? null, 255);
        $sources['salary_text'] = $salaryText !== null ? 'structured' : 'rule';
        $values['salary_min'] = $salaryMin;
        $values['salary_max'] = $salaryMax;
        $values['salary_currency'] = $currency;
        if ($values['salary_text'] === null) {
            unset($sources['salary_text']);
        }

        $sources = array_intersect_key($sources, array_filter($values + ['title' => $title], fn ($v) => $v !== null));

        $bodyLength = $post->raw_content === null ? 0 : mb_strlen(trim(strip_tags($post->raw_content)));
        if ($post->raw_content === null) {
            $warnings[] = 'The ad text is no longer stored, so only the facts and the title were read.';
        }

        [$confidence, $missing] = $this->score($titleFrom, $values, $bodyLength);
        if ($missing) {
            $warnings[] = 'Not found: '.implode(', ', $missing).'.';
        }

        return [
            'fields' => ['title' => $title] + $values,
            'sources' => $sources,
            'warnings' => array_values(array_unique($warnings)),
            'confidence' => $confidence,
            'needs_review' => $confidence < 50,
        ];
    }

    /**
     * How much of a usable record was found, 0 to 100. A title alone is not enough to trust, so it stays below the
     * review line; a title with an employer and an ad text passes.
     *
     * @return array{0:int, 1:list<string>}
     */
    private function score(string $titleFrom, array $v, int $bodyLength): array
    {
        $points = ['title' => $titleFrom === 'structured' ? 25 : 20];
        $checks = [
            'employer' => ['employer_text', 20],
            'location' => ['location_text', 15],
            'closing date' => ['closing_date', 15],
            'posted date' => ['posted_date', 5],
            'job type' => ['employment_type', 5],
        ];
        $missing = [];

        foreach ($checks as $label => [$field, $weight]) {
            if ($v[$field] !== null) {
                $points[$label] = $weight;
            } else {
                $missing[] = $label;
            }
        }

        if ($bodyLength >= 200) {
            $points['text'] = 10;
        } else {
            $missing[] = 'enough ad text';
        }

        if ($v['qualification_text'] !== null || $v['experience_text'] !== null || $v['salary_text'] !== null) {
            $points['requirements'] = 5;
        }

        return [min(100, array_sum($points)), $missing];
    }

    private function firstLine(RawJobPost $post): ?string
    {
        if ($post->raw_content === null) {
            return null;
        }

        foreach (preg_split('/\R/', html_entity_decode(strip_tags(preg_replace('#</?(?:p|br|li|div|h\d)\b[^>]*>#i', "\n", $post->raw_content)), ENT_QUOTES | ENT_HTML5, 'UTF-8')) as $line) {
            if (trim($line) !== '') {
                return $line;
            }
        }

        return null;
    }

    /** Plain text, spaces collapsed, cut to the column length. */
    private function clean(mixed $value, int $max): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    private function listText(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = implode(', ', array_filter($value, 'is_string'));
        }

        return is_string($value) ? $value : null;
    }

    /** "Lusaka, Lusaka Province; Ndola" from the published places; place names only. */
    private function location(array $facts): ?string
    {
        $places = [];

        foreach ((array) ($facts['locations'] ?? []) as $place) {
            if (! is_array($place)) {
                continue;
            }

            $name = $place['text'] ?? implode(', ', array_filter([$place['locality'] ?? null, $place['region'] ?? null]));
            if (is_string($name) && trim($name) !== '') {
                $places[] = trim($name);
            }
        }

        if (! $places && is_string($facts['location'] ?? null)) {
            $places[] = $facts['location'];
        }

        return $this->clean(implode('; ', array_unique($places)), 255);
    }

    /** @param list<string> $formats */
    private function date(mixed $value, array $formats): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        return Extractor::parseDate(preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ].*)?$/', $value) ? substr($value, 0, 10) : $value, $formats);
    }

    private function url(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $url = rtrim(trim($value), '.,;:)]');

        return preg_match('~^https?://~i', $url) && filter_var($url, FILTER_VALIDATE_URL) && strlen($url) <= 500 ? $url : null;
    }

    /** @return array{0:?string,1:?float,2:?float,3:?string} text, min, max, currency */
    private function salary(mixed $salary): array
    {
        if (is_string($salary)) {
            return [$this->clean($salary, 255), null, null, null];
        }

        if (! is_array($salary) || (! isset($salary['min']) && ! isset($salary['max']))) {
            return [null, null, null, null];
        }

        $min = is_numeric($salary['min'] ?? null) ? (float) $salary['min'] : null;
        $max = is_numeric($salary['max'] ?? null) ? (float) $salary['max'] : null;
        $currency = $this->clean($salary['currency'] ?? null, 10);
        $fmt = fn (float $n) => number_format($n, $n == floor($n) ? 0 : 2);
        $range = $min !== null && $max !== null && $min != $max ? $fmt($min).'–'.$fmt($max) : $fmt($min ?? $max);
        $unit = $this->clean($salary['unit'] ?? null, 20);

        return [trim(($currency ? $currency.' ' : '').$range.($unit ? ' per '.strtolower($unit) : '')), $min, $max, $currency];
    }
}
