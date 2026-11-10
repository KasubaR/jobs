<?php

namespace App\Ai\Evaluation;

/**
 * Compares an AI answer (after ExtractionValidator) with the answer a person wrote for the same advert.
 *
 * An item matches when it is the same thing in the same place, however it is worded:
 *   qualifications            same level, and the same quoted passage or a very similar name
 *   professional_registrations a name that equals or contains the other ("HPCZ" and "Health Professions Council of Zambia (HPCZ)")
 *   experience_requirements   same years and the same quoted passage
 * A matched item is then checked for strength. Nothing here calls an AI or changes data.
 *
 * An item the AI gave that the written answer lacks is "extra", not "wrong": the written answer is a draft that may have
 * missed something, so extras are listed for a person to judge.
 */
class Scorer
{
    public const SECTIONS = ['qualifications', 'professional_registrations', 'experience_requirements'];

    private const RANK = ['mandatory' => 3, 'preferred' => 2, 'advantage' => 1, 'mentioned' => 0];

    private const STOP = ['the', 'of', 'in', 'or', 'and', 'a', 'an', 'to', 'with', 'for', 'from', 'on', 'at', 'by', 'as', 'is', 'be', 'will', 'must', 'have', 'has'];

    /**
     * @param array<string, mixed> $expected the written answer
     * @param array<string, mixed> $got the validated AI answer
     * @return array{sections: array<string, array<string, mixed>>, found: array{expected: bool, got: bool}}
     */
    public function score(array $expected, array $got): array
    {
        $out = ['sections' => [], 'found' => ['expected' => (bool) ($expected['qualification_found'] ?? false), 'got' => (bool) ($got['qualification_found'] ?? false)]];

        foreach (self::SECTIONS as $section) {
            $want = $expected[$section] ?? [];
            $have = $got[$section] ?? [];
            $used = [];
            $matched = [];
            $missed = [];

            foreach ($want as $w) {
                $hit = $this->find($section, $w, $have, $used);
                if ($hit === null) {
                    $missed[] = $this->label($section, $w) + ['type' => $w['requirement_type']];

                    continue;
                }
                $used[$hit] = true;
                $matched[] = [
                    'item' => $this->label($section, $w), 'expected' => $w['requirement_type'], 'got' => $have[$hit]['requirement_type'],
                    'result' => $this->strength($w['requirement_type'], $have[$hit]['requirement_type']),
                ];
            }

            $extra = [];
            foreach ($have as $i => $h) {
                $used[$i] ?? $extra[] = $this->label($section, $h) + ['type' => $h['requirement_type']];
            }

            $out['sections'][$section] = ['expected' => count($want), 'got' => count($have), 'matched' => $matched, 'missed' => $missed, 'extra' => $extra];
        }

        return $out;
    }

    /**
     * Adds several scored adverts together.
     *
     * @param list<array{sector:string, score:array}> $scored
     * @return array<string, mixed>
     */
    public function summarise(array $scored): array
    {
        $blank = fn () => ['expected' => 0, 'got' => 0, 'matched' => 0, 'strength_ok' => 0, 'overstated' => 0, 'understated' => 0];
        $total = $blank();
        $bySection = array_fill_keys(self::SECTIONS, null);
        $bySector = [];
        $byType = [];
        $found = ['both_yes' => 0, 'both_no' => 0, 'missed' => 0, 'invented' => 0];

        foreach (self::SECTIONS as $s) {
            $bySection[$s] = $blank();
        }

        foreach ($scored as $row) {
            $sector = $row['sector'];
            $bySector[$sector] ??= $blank();
            $f = $row['score']['found'];
            $found[match (true) { $f['expected'] && $f['got'] => 'both_yes', ! $f['expected'] && ! $f['got'] => 'both_no', $f['expected'] => 'missed', default => 'invented' }]++;

            foreach ($row['score']['sections'] as $name => $s) {
                $add = ['expected' => $s['expected'], 'got' => $s['got'], 'matched' => count($s['matched']),
                    'strength_ok' => count(array_filter($s['matched'], fn ($m) => $m['result'] === 'correct')),
                    'overstated' => count(array_filter($s['matched'], fn ($m) => $m['result'] === 'overstated')),
                    'understated' => count(array_filter($s['matched'], fn ($m) => $m['result'] === 'understated'))];
                foreach ($add as $k => $v) {
                    $total[$k] += $v;
                    $bySection[$name][$k] += $v;
                    $bySector[$sector][$k] += $v;
                }
                foreach ($s['matched'] as $m) {
                    $byType[$m['expected']]['expected'] = ($byType[$m['expected']]['expected'] ?? 0) + 1;
                    $byType[$m['expected']]['strength_ok'] = ($byType[$m['expected']]['strength_ok'] ?? 0) + ($m['result'] === 'correct' ? 1 : 0);
                }
                foreach ($s['missed'] as $m) {
                    $byType[$m['type']]['expected'] = ($byType[$m['type']]['expected'] ?? 0) + 1;
                    $byType[$m['type']]['strength_ok'] ??= 0;
                    $byType[$m['type']]['missed'] = ($byType[$m['type']]['missed'] ?? 0) + 1;
                }
            }
        }

        $rates = fn (array $a) => $a + ['precision' => $this->ratio($a['matched'], $a['got']), 'recall' => $this->ratio($a['matched'], $a['expected']), 'strength' => $this->ratio($a['strength_ok'], $a['matched'])];
        foreach ($byType as $t => $v) {
            $byType[$t] = $v + ['missed' => 0, 'found' => $v['expected'] - ($v['missed'] ?? 0), 'recall' => $this->ratio($v['expected'] - ($v['missed'] ?? 0), $v['expected'])];
        }

        return [
            'total' => $rates($total),
            'by_section' => array_map($rates, $bySection),
            'by_sector' => array_map($rates, $bySector),
            'by_expected_type' => $byType,
            'qualification_found' => $found,
        ];
    }

    public function ratio(int|float $part, int|float $whole): ?float
    {
        return $whole > 0 ? round($part / $whole, 3) : null;
    }

    // ---- matching ----

    /** @param list<array<string, mixed>> $have */
    private function find(string $section, array $want, array $have, array $used): ?int
    {
        $best = null;
        foreach ($have as $i => $h) {
            if (isset($used[$i]) || ! $this->same($section, $want, $h)) {
                continue;
            }
            if ($h['requirement_type'] === $want['requirement_type']) {
                return $i;   // right thing, right strength
            }
            $best ??= $i;
        }

        return $best;
    }

    private function same(string $section, array $a, array $b): bool
    {
        return match ($section) {
            'qualifications' => ($a['level'] ?? null) === ($b['level'] ?? null)
                && ($this->overlap($a['original_text'], $b['original_text']) >= 0.5 || $this->overlap($a['name'], $b['name']) >= 0.6),
            'professional_registrations' => $this->nameMatch($a['name'], $b['name']),
            default => ($a['years_min'] ?? null) === ($b['years_min'] ?? null) && ($a['years_max'] ?? null) === ($b['years_max'] ?? null)
                && $this->overlap($a['original_text'], $b['original_text']) >= 0.5,
        };
    }

    private function nameMatch(string $a, string $b): bool
    {
        [$x, $y] = [implode(' ', $this->words($a)), implode(' ', $this->words($b))];

        return $x !== '' && $y !== '' && ($x === $y || str_contains($x, $y) || str_contains($y, $x) || $this->overlap($a, $b) >= 0.5);
    }

    /** Share of the words of the shorter text that the other has. */
    private function overlap(string $a, string $b): float
    {
        [$x, $y] = [$this->words($a), $this->words($b)];
        if ($x === [] || $y === []) {
            return 0.0;
        }

        return count(array_intersect($x, $y)) / min(count($x), count($y));
    }

    /** @return list<string> */
    private function words(string $s): array
    {
        $s = mb_strtolower(strtr($s, ['’' => "'", '‘' => "'", '–' => ' ', '—' => ' ']));

        return array_values(array_unique(array_diff(preg_split('/[^\p{L}\p{N}]+/u', $s, -1, PREG_SPLIT_NO_EMPTY), self::STOP)));
    }

    private function strength(string $expected, string $got): string
    {
        return match (true) {
            $expected === $got => 'correct',
            (self::RANK[$got] ?? 0) > (self::RANK[$expected] ?? 0) => 'overstated',
            default => 'understated',
        };
    }

    private function label(string $section, array $item): array
    {
        return ['name' => $item['name'] ?? (($item['years_min'] ?? '?').(isset($item['years_max']) ? '–'.$item['years_max'] : '').' years'.(isset($item['area']) ? ' '.$item['area'] : ''))];
    }
}
