<?php

namespace App\Standardization;

/**
 * "3 years' experience", "three (3) years", "minimum of 3 years", "2-3 yrs" and "36 months" become months and a label
 * such as "3 Years". One figure gives the minimum; "at least", "minimum" and "5+" are shown as "5+ Years".
 * Two different figures in one text ("5 years in mining, 2 in management") are ambiguous and left for a person.
 */
class ExperienceParser
{
    private const WORDS = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10, 'twelve' => 12, 'fifteen' => 15];

    public function __construct(private TermDictionary $terms) {}

    /**
     * @return array{status:string, key?:string, min?:?int, max?:?int, label?:?string, reason?:string}
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

        if ($mapped = $this->terms->find('experience', $key)) {
            if ($mapped->meta['ignore'] ?? false) {
                return $out + ['status' => 'ignored'];
            }

            $min = $mapped->meta['min'] ?? null;
            $max = $mapped->meta['max'] ?? null;

            return $out + ['status' => 'ok', 'min' => $min, 'max' => $max, 'label' => self::label($min, $max, false)];
        }

        if (preg_match('/\b(?:no|without|zero)\s+(?:prior\s+|previous\s+|work\s+)?experience\b|\bfresh\s+graduates?\b|\bentry[\s-]level\b/i', $text)) {
            return $out + ['status' => 'ok', 'min' => 0, 'max' => null, 'label' => 'No experience'];
        }

        $lower = mb_strtolower($text);
        $lower = preg_replace_callback('/\b('.implode('|', array_keys(self::WORDS)).')\b(?:\s*\(\s*\d+\s*\))?/u', fn ($m) => (string) self::WORDS[$m[1]], $lower);
        $lower = preg_replace('/(\d)\s*\(\s*\d+\s*\)/', '$1', $lower);

        $unit = '(years?|yrs?|months?|mos?)\b';
        $number = '(\d+(?:\.\d+)?)';
        $matches = [];

        // a range first ("2-3 years"), then single figures that the range did not already use
        $rest = $lower;
        if (preg_match_all('/'.$number.'\s*(?:-|–|to)\s*'.$number.'\s*'.$unit.'/u', $rest, $m, PREG_SET_ORDER)) {
            foreach ($m as $r) {
                $matches[] = [self::months($r[1], $r[3]), self::months($r[2], $r[3]), false];
            }
            $rest = preg_replace('/'.$number.'\s*(?:-|–|to)\s*'.$number.'\s*'.$unit.'/u', ' ', $rest);
        }

        if (preg_match_all('/(?:(minimum|min|at\s+least|atleast|over|more\s+than)\s+(?:of\s+)?)?'.$number.'\s*(\+|plus)?\s*'.$unit.'/u', $rest, $m, PREG_SET_ORDER)) {
            foreach ($m as $r) {
                $matches[] = [self::months($r[2], $r[4]), null, ($r[1] ?? '') !== '' || ($r[3] ?? '') !== ''];
            }
        }

        $distinct = array_unique(array_map(fn ($x) => $x[0].'/'.$x[1], $matches));

        if (count($distinct) > 1) {
            return $out + ['status' => 'ambiguous', 'reason' => 'gives more than one length of experience'];
        }

        if (! $matches) {
            return $out + ['status' => 'unknown'];
        }

        [$min, $max, $atLeast] = $matches[0];

        return $out + ['status' => 'ok', 'min' => $min, 'max' => $max, 'label' => self::label($min, $max, $atLeast)];
    }

    /** "3 Years", "2–3 Years", "5+ Years", "6 Months", "1 Year". */
    public static function label(?int $min, ?int $max, bool $atLeast): ?string
    {
        if ($min === null) {
            return null;
        }

        if ($min === 0 && $max === null) {
            return 'No experience';
        }

        $fmt = function (int $months): string {
            if ($months >= 12 && $months % 12 === 0) {
                $y = intdiv($months, 12);

                return $y.' '.($y === 1 ? 'Year' : 'Years');
            }

            return $months.' '.($months === 1 ? 'Month' : 'Months');
        };

        if ($max !== null && $max !== $min) {
            $same = ($min % 12 === 0 && $max % 12 === 0) || ($min < 12 && $max < 12);
            $lo = $same ? preg_replace('/ (Years?|Months?)$/', '', $fmt($min)) : $fmt($min);

            return $lo.'–'.$fmt($max);
        }

        return $atLeast ? preg_replace('/ (Years?|Months?)$/', '+ $1', $fmt($min)) : $fmt($min);
    }

    private static function months(string $number, string $unit): int
    {
        return (int) round((float) $number * (str_starts_with($unit, 'm') ? 1 : 12));
    }
}
