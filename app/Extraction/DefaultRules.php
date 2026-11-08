<?php

namespace App\Extraction;

/**
 * The built-in label rules used for every source after the source's own rules.
 * They only read text that names the field ("Closing date: 30 November 2026"). Nothing is guessed:
 * a date without a year ("by 30 November") is not read, and a field with no label stays empty.
 */
class DefaultRules
{
    /** A date with a year: 30 November 2026, 30th Nov 2026, November 30, 2026, 30/11/2026, 30-11-2026, 2026-11-30. */
    private const DATE = '(\d{1,2}(?:st|nd|rd|th)?[ \t/.\-]+(?:[a-z]{3,9}|\d{1,2})[ \t,/.\-]+\d{4}|[a-z]{3,9}\.?[ \t]+\d{1,2}(?:st|nd|rd|th)?,?[ \t]+\d{4}|\d{4}-\d{2}-\d{2})';

    private const SEP = '\s*[:\-–]\s*';

    /** @return list<array{field:string,pattern:string}> */
    public static function fields(): array
    {
        $d = self::DATE;
        $s = self::SEP;

        return [
            ['field' => 'closing_date', 'pattern' => '(?:closing date|date of closing|application deadline|deadline(?: for applications)?|applications? (?:close|closes|closing)(?: on)?|closes on)'.$s.'?'.$d],
            ['field' => 'closing_date', 'pattern' => '(?:apply|applications?|submit[a-z]*)[^.\n]{0,80}?\s(?:by|before|not later than)\s+'.$d],
            ['field' => 'posted_date', 'pattern' => '(?:date posted|posted(?: on)?|published(?: on)?|date published)'.$s.'?'.$d],
            ['field' => 'employment_type', 'pattern' => '(?:job type|employment type|contract type|type of employment|position type)'.$s.'([^\n]{2,40})'],
            ['field' => 'location', 'pattern' => '(?:location|duty station|place of work|work location|job location|based in)'.$s.'([^\n]{2,100})'],
            ['field' => 'salary_text', 'pattern' => '(?:salary range|salary|remuneration|pay)'.$s.'([^\n]{2,100})'],
            ['field' => 'employer', 'pattern' => '(?:employer|company|organi[sz]ation|hiring organi[sz]ation)'.$s.'([^\n]{2,100})'],
            ['field' => 'qualification_text', 'pattern' => '(?:minimum qualifications?|qualifications?|educational requirements?|education)'.$s.'([^\n]{2,150})'],
            ['field' => 'experience_text', 'pattern' => '(?:work experience|experience)'.$s.'([^\n]{2,100})'],
            ['field' => 'experience_text', 'pattern' => '(\d{1,2}\s*(?:\+|-\s*\d{1,2}|to\s+\d{1,2})?\s*(?:years?|yrs?)\'?\s+(?:of\s+)?(?:relevant\s+|working\s+|work\s+)?experience)'],
            ['field' => 'application_url', 'pattern' => '(?:apply online|apply (?:at|here|via|through|on)|application link|how to apply)'.$s.'?(https?://[^\s<>"\']+)'],
        ];
    }

    /** Source rules first (they win), then the built-in ones. */
    public static function merge(array $source): array
    {
        // Only the fixed rules below may be marked built-in; a source's own rules never can (they keep the length limit).
        $own = array_map(fn ($r) => ['field' => $r['field'] ?? null, 'pattern' => $r['pattern'] ?? ''], (array) ($source['fields'] ?? []));
        $builtIn = array_map(fn ($r) => $r + ['built_in' => true], self::fields());

        return [
            'title_separator' => $source['title_separator'] ?? '',
            'date_formats' => array_values(array_unique(array_merge($source['date_formats'] ?? [], Extractor::DEFAULT_DATE_FORMATS))),
            'fields' => array_merge($own, $builtIn),
        ];
    }
}
