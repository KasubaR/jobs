<?php

namespace App\Collectors\Html;

use DateTimeImmutable;
use Throwable;

/**
 * Reads the machine-readable job data a page publishes about itself: a schema.org
 * "JobPosting" inside <script type="application/ld+json"> (the format job sites add for search engines).
 *
 * It returns:
 *  - facts: a fixed, small set of fields (title, employer, place, dates, type, pay, ...). Never contact
 *    details (emails, phone numbers, contact persons) and never the ad text, so facts can be kept for good.
 *  - description: the ad text, kept apart because it is raw text and follows the 90-day retention rule.
 *
 * Reading only. Anything odd (broken JSON, huge blocks, no JobPosting) is skipped, never an error.
 */
class JobPostingParser
{
    private const MAX_BLOCK_BYTES = 200_000;

    private const MAX_BLOCKS = 20;

    private const MAX_NODES = 300;

    private const MAX_DEPTH = 8;

    private const MAX_LIST = 10;

    /** @return array{facts: array<string, mixed>, description: ?string}|null */
    public function parse(string $html): ?array
    {
        foreach ($this->jsonBlocks($html) as $block) {
            $posting = $this->findJobPosting($block);

            if ($posting !== null) {
                return $this->normalise($posting);
            }
        }

        return null;
    }

    /** @return list<mixed> every readable JSON-LD block on the page */
    private function jsonBlocks(string $html): array
    {
        $blocks = [];

        $page = new HtmlPage($html);
        foreach (array_slice($page->select('script[type="application/ld+json"]'), 0, self::MAX_BLOCKS) as $node) {
            $text = trim($node->textContent);
            $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);                       // byte order mark
            $text = preg_replace('/^\s*<!--|-->\s*$|^\s*\/\*<!\[CDATA\[\*\/|\/\*\]\]>\*\/\s*$/', '', $text); // comment / CDATA wrappers

            if ($text === '' || strlen($text) > self::MAX_BLOCK_BYTES) {
                continue;
            }

            $data = json_decode($text, true, 64);
            if (is_array($data)) {
                $blocks[] = $data;
            }
        }

        return $blocks;
    }

    /** First JobPosting in a JSON-LD structure (it may sit in a list or an @graph). */
    private function findJobPosting(mixed $node, int $depth = 0, int &$seen = 0): ?array
    {
        if (! is_array($node) || $depth > self::MAX_DEPTH || ++$seen > self::MAX_NODES) {
            return null;
        }

        if ($this->isJobPosting($node)) {
            return $node;
        }

        $children = array_is_list($node) ? $node : (is_array($node['@graph'] ?? null) ? $node['@graph'] : []);

        foreach ($children as $child) {
            if (($found = $this->findJobPosting($child, $depth + 1, $seen)) !== null) {
                return $found;
            }
        }

        return null;
    }

    private function isJobPosting(array $node): bool
    {
        $types = $node['@type'] ?? null;

        return in_array('JobPosting', is_array($types) ? $types : [$types], true);
    }

    /** @return array{facts: array<string, mixed>, description: ?string} */
    private function normalise(array $p): array
    {
        $facts = array_filter([
            'title' => $this->text($p['title'] ?? $p['name'] ?? null, 300),
            'employer' => $this->employer($p['hiringOrganization'] ?? null),
            'locations' => $this->locations($p['jobLocation'] ?? null),
            'remote' => ($p['jobLocationType'] ?? null) === 'TELECOMMUTE' ?: null,
            'date_posted' => $this->date($p['datePosted'] ?? null),
            'valid_through' => $this->date($p['validThrough'] ?? null),
            'employment_type' => $this->list($p['employmentType'] ?? null, upper: true),
            'salary' => $this->salary($p['baseSalary'] ?? null),
            'industry' => $this->list($p['industry'] ?? null),
            'category' => $this->list($p['occupationalCategory'] ?? null),
            'identifier' => $this->identifier($p['identifier'] ?? null),
            'education' => $this->requirement($p['educationRequirements'] ?? null, 'credentialCategory'),
            'experience' => $this->experience($p['experienceRequirements'] ?? null),
            'skills' => $this->text(is_array($p['skills'] ?? null) ? implode(', ', array_filter($p['skills'], 'is_string')) : ($p['skills'] ?? null), 500),
            'qualifications' => $this->text(is_string($p['qualifications'] ?? null) ? $p['qualifications'] : null, 500),
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        $description = is_string($p['description'] ?? null) ? trim($p['description']) : null;

        return ['facts' => $facts, 'description' => $description !== '' ? $description : null];
    }

    /** Plain text: tags removed, spaces collapsed, cut to length. */
    private function text(mixed $value, int $max = 300): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    private function employer(mixed $org): ?string
    {
        if (is_array($org)) {
            return $this->text($org['name'] ?? null, 200);
        }

        return $this->text($org, 200);
    }

    /** @return list<array<string, string>>|null place names only (no street address) */
    private function locations(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $places = array_is_list($value) ? $value : [$value];
        $out = [];

        foreach (array_slice($places, 0, self::MAX_LIST) as $place) {
            $address = is_array($place) ? ($place['address'] ?? null) : null;

            if (is_string($address)) {
                $out[] = ['text' => $this->text($address, 200)];

                continue;
            }

            if (! is_array($address)) {
                continue;
            }

            $country = $address['addressCountry'] ?? null;
            $row = array_filter([
                'locality' => $this->text($address['addressLocality'] ?? null, 120),
                'region' => $this->text($address['addressRegion'] ?? null, 120),
                'country' => $this->text(is_array($country) ? ($country['name'] ?? null) : $country, 120),
            ]);

            if ($row) {
                $out[] = $row;
            }
        }

        return $out ?: null;
    }

    /** @return array{currency?:string,min?:float|int,max?:float|int,unit?:string}|null */
    private function salary(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $amount = is_array($value['value'] ?? null) ? $value['value'] : $value;

        $num = fn ($n) => is_numeric($n) ? $n + 0 : null;
        $single = $num($amount['value'] ?? null);

        $out = array_filter([
            'currency' => $this->text($value['currency'] ?? $amount['currency'] ?? null, 10),
            'min' => $num($amount['minValue'] ?? null) ?? $single,
            'max' => $num($amount['maxValue'] ?? null) ?? $single,
            'unit' => $this->text($amount['unitText'] ?? null, 20),
        ], fn ($v) => $v !== null && $v !== '');

        return isset($out['min']) || isset($out['max']) ? $out : null;
    }

    private function identifier(mixed $value): ?string
    {
        return $this->text(is_array($value) ? ($value['value'] ?? null) : $value, 100);
    }

    private function requirement(mixed $value, string $key): ?string
    {
        if (is_array($value)) {
            $value = array_is_list($value) ? ($value[0] ?? null) : $value;
            $value = is_array($value) ? ($value[$key] ?? null) : $value;
        }

        return $this->text($value, 200);
    }

    private function experience(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = array_is_list($value) ? ($value[0] ?? null) : $value;

            if (is_array($value) && is_numeric($value['monthsOfExperience'] ?? null)) {
                return $value['monthsOfExperience'].' months';
            }

            return null;
        }

        return $this->text($value, 200);
    }

    /** @return list<string>|null */
    private function list(mixed $value, bool $upper = false): ?array
    {
        $items = is_array($value) ? $value : [$value];
        $out = [];

        foreach (array_slice($items, 0, self::MAX_LIST) as $item) {
            $text = $this->text($item, 100);
            if ($text !== null) {
                $out[] = $upper ? strtoupper($text) : $text;
            }
        }

        return $out ?: null;
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable(trim($value)))->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }
}
