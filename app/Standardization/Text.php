<?php

namespace App\Standardization;

/** Text helpers shared by every standardizer, so the same words always give the same key. */
final class Text
{
    /** Lower case, no apostrophes or dots inside words, everything else as single spaces: "B.Sc." and "BSc" both give "bsc". */
    public static function normalize(?string $text): string
    {
        $text = mb_strtolower((string) $text);
        $text = str_replace(['’', '‘', "'", '`'], '', $text);
        $text = preg_replace('/(?<=\p{L})\.(?=\p{L})/u', '', $text);
        $text = str_replace('&', ' and ', $text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /** Plain text with line breaks kept, tags and entities removed. */
    public static function plain(?string $html): string
    {
        $html = preg_replace('#<(?:br|/p|/li|/h\d|/div|/tr)\b[^>]*>#i', "\n", (string) $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/[ \t\x{00A0}]+/u", ' ', $text));
    }

    /** Legal endings that say nothing about who the employer is. */
    private const LEGAL = ['ltd', 'limited', 'plc', 'inc', 'incorporated', 'llc', 'llp', 'corp', 'corporation', 'co', 'company', 'pty', 'gmbh'];

    /**
     * The key an employer is matched on: "Zamtel (Z) Ltd" and "ZAMTEL" give the same key.
     * Only legal endings are removed, so two different names never become one.
     */
    public static function employerKey(?string $name): string
    {
        $words = explode(' ', preg_replace('/^the /', '', self::normalize($name)));
        $removed = false;

        while ($words && in_array(end($words), self::LEGAL, true)) {
            array_pop($words);
            $removed = true;
        }

        if ($removed && $words && end($words) === 'z') { // the "(Z)" in "Zamtel (Z) Ltd"
            array_pop($words);
        }

        return trim(implode(' ', $words));
    }
}
