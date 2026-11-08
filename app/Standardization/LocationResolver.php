<?php

namespace App\Standardization;

/**
 * Turns the place text of an ad into a known place: "Lusaka City", "Lusaka, Zambia" and "Lusaka" all give Lusaka
 * (the town); "Kitwe, Copperbelt Province" gives Kitwe. The original text is never touched.
 *
 * Order: a person's or the seeded mapping for the exact word, then the known places by name, then (only when exactly
 * one town is named inside longer text, such as "Kansanshi Mine, Solwezi") that town. Anything else is reported,
 * not guessed. Words that name no particular place ("Zambia", "Nationwide") are recognized and set nothing.
 */
class LocationResolver
{
    private const RANK = ['province' => 1, 'district' => 2, 'town' => 3];

    public function __construct(private TermDictionary $terms) {}

    /**
     * @return array{location_id:?int, multiple:bool, unknown:list<array{key:string,sample:string}>, matched:list<int>}
     */
    public function resolve(?string $text): array
    {
        $matched = [];
        $unknown = [];
        $places = $this->terms->places();
        $byKey = [];
        foreach ($places as $p) {
            $byKey[$p->key][] = $p;
        }

        foreach (preg_split('/[,;\/|()]|\s+-\s+|\band\b/iu', (string) $text) as $piece) {
            $key = Text::normalize($piece);
            if ($key === '') {
                continue;
            }

            if ($row = $this->terms->find('location', $key)) {
                if (! ($row->meta['ignore'] ?? false) && isset($row->meta['location_id'])) {
                    $matched[(int) $row->meta['location_id']] = true;
                }

                continue; // mapped, or deliberately "no particular place"
            }

            $province = false;
            $name = preg_replace('/^(?:in|at|near)\s+/', '', $key);
            if (preg_match('/^(.+?)\s+province$/', $name, $m)) {
                $name = $m[1];
                $province = true;
            }
            $name = trim(preg_replace('/\s+(?:city|town|district|municipality|urban)$/', '', $name));

            if ($name === 'zambia' || $name === '') {
                continue;
            }

            $candidates = $byKey[$name] ?? [];
            if ($province) {
                $candidates = array_values(array_filter($candidates, fn ($p) => $p->type === 'province'));
            }

            if ($candidates) {
                usort($candidates, fn ($a, $b) => self::RANK[$b->type] <=> self::RANK[$a->type]);
                $matched[$candidates[0]->id] = true;

                continue;
            }

            $inside = [];
            foreach ($places as $p) {
                if ($p->type === 'town' && preg_match('/\b'.preg_quote($p->key, '/').'\b/', $name)) {
                    $inside[$p->id] = true;
                }
            }

            if (count($inside) === 1) {
                $matched[array_key_first($inside)] = true;
            } else {
                $unknown[$key] = ['key' => $key, 'sample' => trim($piece)];
            }
        }

        $ids = array_keys($matched);
        $byId = [];
        foreach ($places as $p) {
            $byId[$p->id] = $p;
        }

        // The most specific place wins. A province named next to one of its own towns is the same place, not a second one;
        // places that are not inside one another mean the ad names several.
        usort($ids, fn ($a, $b) => self::RANK[$byId[$b]->type] <=> self::RANK[$byId[$a]->type]);
        $parents = array_flip(array_filter(array_map(fn ($id) => $byId[$id]->parent_id, $ids)));
        $separate = array_filter($ids, fn ($id) => ! isset($parents[$id]));

        return [
            'location_id' => $ids[0] ?? null,
            'multiple' => count($separate) > 1,
            'unknown' => array_values($unknown),
            'matched' => $ids,
        ];
    }
}
