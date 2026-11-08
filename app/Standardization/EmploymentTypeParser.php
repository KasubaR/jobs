<?php

namespace App\Standardization;

/**
 * "FULL_TIME, CONTRACT" or "Full time / Permanent" becomes a list of standard keys. A word the dictionary does not
 * know is reported on its own; the words it does know are still used.
 */
class EmploymentTypeParser
{
    public function __construct(private TermDictionary $terms) {}

    /**
     * @return array{types:list<string>, unknown:list<array{key:string,sample:string}>}
     */
    public function parse(?string $text): array
    {
        $types = [];
        $unknown = [];
        $phrases = $this->terms->all('employment_type');

        foreach (preg_split('/[,;\/|]|\band\b|\bor\b|&|\+/iu', str_replace('_', ' ', (string) $text)) as $piece) {
            $key = Text::normalize($piece);
            if ($key === '') {
                continue;
            }

            $row = $phrases[$key] ?? null;

            if ($row === null) {
                $unknown[$key] = ['key' => $key, 'sample' => trim($piece)];
            } elseif (! ($row->meta['ignore'] ?? false) && $row->standard_value !== null) {
                $types[$row->standard_value] = true;
            }
        }

        $order = array_keys(DefaultTerms::EMPLOYMENT_TYPES);
        $types = array_keys($types);
        usort($types, fn ($a, $b) => array_search($a, $order) <=> array_search($b, $order));

        return ['types' => $types, 'unknown' => array_values($unknown)];
    }
}
