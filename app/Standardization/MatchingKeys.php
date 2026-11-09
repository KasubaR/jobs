<?php

namespace App\Standardization;

use App\Models\Job;

/**
 * Phase 6, step 4: the keys that duplicate detection compares. They sit next to the originals and never replace them.
 *
 *  match_title        the title without filler ("URGENT", "Vacancy:", reference numbers, " at Employer", position counts)
 *  match_employer     the employer without legal endings
 *  match_location     the standard place name, or the cleaned place text when it is not a known place
 *  match_url          the application link without tracking parts, so one link written two ways is one key
 *  match_date         posted date, else the day the ad was first seen
 *  content_fingerprint / content_hash_norm
 *                     taken from the ad text with boilerplate, contact details and dates removed. They survive the
 *                     90-day clearing of the raw text, so copies can still be found after it.
 */
class MatchingKeys
{
    /** Fewest meaningful words an ad text needs before a fingerprint means anything. */
    public const MIN_WORDS = 30;

    private const FILLER = '~\b(?:urgent(?:ly)?|vacanc(?:y|ies)|job opportunit(?:y|ies)|opportunit(?:y|ies)|job opening|now hiring|we are hiring|hiring|wanted|re[\s-]?advertis(?:ed|ement)|advert(?:isement)?|positions?|posts?)\b~u';

    private const WORD_FORMS = ['sr' => 'senior', 'snr' => 'senior', 'jr' => 'junior', 'jnr' => 'junior', 'mgr' => 'manager', 'asst' => 'assistant', 'admin' => 'administrator'];

    private const STOP = ['a', 'an', 'the', 'of', 'for', 'and', 'in', 'at', 'to', 'with', 'on'];

    private const TRACKING = '~^(?:utm_.*|fbclid|gclid|msclkid|mc_[a-z]+|ref|ref_src|source|src|trk|trackingid|igshid|_ga|campaign)$~i';

    /** Lines of an ad that every site words differently: not part of what the vacancy is. */
    /** Lines up to this long that mention boilerplate are dropped whole; longer ones are cleaned a sentence at a time. */
    private const SHORT_LINE = 300;

    /** A sentence every site words differently and that says nothing about what the job is. */
    private const BOILERPLATE_SENTENCE = '~(?:how to apply|to apply|apply now|apply online|apply here|click here|closing date|deadline|date posted|posted on|published on|share this|follow us|all rights reserved|job reference|reference number|ref(?:erence)? ?no|view all jobs|similar jobs|back to jobs|return to jobs|print this)~iu';

    /** A line that starts with a label ("Location: Kitwe"): a fact kept elsewhere, worded differently on every site. */
    private const LABEL_LINE = '~^\s*(?:employer|company|organi[sz]ation|location|duty station|salary|job type|employment type|qualifications?|experience)\s*:~iu';

    private const STOPWORDS = ['the', 'and', 'for', 'are', 'you', 'our', 'with', 'will', 'have', 'has', 'that', 'this', 'from', 'your', 'who', 'all', 'any', 'can', 'not', 'but', 'its', 'their', 'they', 'been', 'must', 'also', 'such', 'other', 'than', 'into', 'work', 'job', 'role', 'position'];

    public function __construct(private TermDictionary $terms) {}

    /** @return array<string, mixed> columns to set on the job */
    public function build(Job $job, ?string $rawContent): array
    {
        $keys = [
            'match_title' => self::title((string) $job->title, $job->employer_text) ?: null,
            'match_employer' => Text::employerKey($job->employer_text) ?: null,
            'match_location' => $this->location($job),
            'match_url' => self::canonicalUrl($job->application_url),
            'match_date' => ($job->posted_date ?? $job->first_seen_at)?->toDateString(),
        ];

        // No text any more (cleared after 90 days, or never kept): keep the fingerprint made while the text existed.
        if ($rawContent === null) {
            return $keys;
        }

        [$fingerprint, $hash] = self::fingerprint($rawContent);

        return $keys + ['content_fingerprint' => $fingerprint, 'content_hash_norm' => $hash];
    }

    public static function title(string $title, ?string $employer = null): string
    {
        $t = mb_strtolower(trim($title));

        if ($employer !== null && ($key = Text::employerKey($employer)) !== '' && preg_match('/^(.*?)\s+(?:at|@|-|–|\|)\s+(.+)$/u', $t, $m) && Text::employerKey($m[2]) === $key) {
            $t = $m[1]; // "Budget Analyst at Example Charity" is the same job as "Budget Analyst"
        }

        $t = preg_replace('/\[[^\]]*\]/u', ' ', $t);
        $t = preg_replace('/\((?=[^)]*(?:urgent|re-?advert|position|vacanc|ref|no\.|x\s?\d|\d+\s*posts?))[^)]*\)/u', ' ', $t);
        $t = preg_replace('/\b(?:ref(?:erence)?|vacancy|job)\s*(?:no|number|id|code)?\.?\s*[:#]?\s*[a-z\/\-]*\d[\w\-\/]*/u', ' ', $t);
        $t = preg_replace('~\b[a-z]{1,5}[/-]\d{2,}(?:[/-][\w]+)*\b|#\d+|\bx\s?\d+\b|\b\d+\s*(?:positions?|posts?|vacancies)\b~u', ' ', $t);
        $t = preg_replace(self::FILLER, ' ', $t);

        $words = [];
        foreach (explode(' ', Text::normalize($t)) as $w) {
            $w = self::WORD_FORMS[$w] ?? $w;

            if ($w === '' || in_array($w, self::STOP, true)) {
                continue;
            }

            if (strlen($w) > 3 && str_ends_with($w, 's') && ! preg_match('/(?:ss|us|is)$/', $w)) {
                $w = substr($w, 0, -1); // "engineers" and "engineer" are one word for matching
            }

            $words[] = $w;
        }

        return mb_substr($words ? implode(' ', $words) : Text::normalize($title), 0, 300);
    }

    /** The link as one stable string, or null when it is not an http(s) link. */
    public static function canonicalUrl(?string $url): ?string
    {
        $parts = parse_url(trim((string) $url));

        if (! $parts || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
            return null;
        }

        $host = preg_replace('/^www\./', '', strtolower($parts['host']));
        $port = isset($parts['port']) && ! in_array($parts['port'], [80, 443], true) ? ':'.$parts['port'] : '';
        $path = rtrim($parts['path'] ?? '', '/');

        parse_str($parts['query'] ?? '', $query);
        $query = array_filter($query, fn ($v, $k) => ! preg_match(self::TRACKING, (string) $k), ARRAY_FILTER_USE_BOTH);
        ksort($query);

        $url = 'https://'.$host.$port.($path === '' ? '' : $path).($query ? '?'.http_build_query($query) : '');

        return mb_substr($url, 0, 500);
    }

    /**
     * @return array{0:?string, 1:?string} simhash (16 hex characters) and sha256 of the cleaned text, or nulls when
     *                                     the text is too short to say anything about the vacancy
     */
    public static function fingerprint(string $rawContent): array
    {
        $words = self::words($rawContent);

        if (count($words) < self::MIN_WORDS) {
            return [null, null];
        }

        $counts = array_fill(0, 64, 0);
        $shingles = max(1, count($words) - 2);

        for ($i = 0; $i < $shingles; $i++) {
            $h = unpack('J', hash('xxh64', implode(' ', array_slice($words, $i, 3)), true))[1];
            for ($bit = 0; $bit < 64; $bit++) {
                $counts[$bit] += (($h >> $bit) & 1) ? 1 : -1;
            }
        }

        $hi = $lo = 0;
        for ($bit = 0; $bit < 32; $bit++) {
            $lo |= ($counts[$bit] > 0 ? 1 : 0) << $bit;
            $hi |= ($counts[$bit + 32] > 0 ? 1 : 0) << $bit;
        }

        return [sprintf('%08x%08x', $hi, $lo), hash('sha256', implode(' ', $words))];
    }

    /** How many of the 64 bits differ: near 0 means nearly the same text, around 32 means unrelated. */
    public static function distance(string $a, string $b): int
    {
        $bits = 0;
        foreach ([0, 8] as $at) {
            $x = hexdec(substr($a, $at, 8)) ^ hexdec(substr($b, $at, 8));
            $bits += substr_count(decbin($x), '1');
        }

        return $bits;
    }

    /** @return list<string> the meaningful words of an ad text, in order */
    public static function words(string $content): array
    {
        // A short line with boilerplate in it ("How to apply: ...") is boilerplate all through and is dropped whole.
        // A long line is the ad's own text with some boilerplate inside it (an ad that is one paragraph beginning
        // "Closing date: 15 December 2026."), so only the boilerplate sentences go and the rest is kept.
        $kept = [];
        foreach (explode("\n", mb_strtolower(Text::plain($content))) as $line) {
            if (trim($line) === '' || preg_match(self::LABEL_LINE, $line)) {
                continue;
            }

            if (mb_strlen($line) <= self::SHORT_LINE) {
                if (! preg_match(self::BOILERPLATE_SENTENCE, $line)) {
                    $kept[] = $line;
                }

                continue;
            }

            foreach (preg_split('/(?<=[.!?])\s+/u', $line) as $sentence) {
                if (trim($sentence) !== '' && ! preg_match(self::BOILERPLATE_SENTENCE, $sentence)) {
                    $kept[] = $sentence;
                }
            }
        }
        $text = implode(' ', $kept);
        $text = preg_replace('~https?://\S+|www\.\S+|[\w.+-]+@[\w-]+\.[\w.-]+~u', ' ', $text);
        $text = preg_replace('/\+?\d[\d\s\-()]{6,}\d/u', ' ', $text); // phone numbers
        $text = preg_replace('/\b\d{1,2}(?:st|nd|rd|th)?\s+[a-z]{3,9},?\s+\d{4}\b|\b\d{1,2}[\/.-]\d{1,2}[\/.-]\d{2,4}\b/u', ' ', $text); // dates

        return array_values(array_filter(explode(' ', Text::normalize($text)), fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, self::STOPWORDS, true)));
    }

    private function location(Job $job): ?string
    {
        if ($job->location_id) {
            foreach ($this->terms->places() as $place) {
                if ((int) $place->id === (int) $job->location_id) {
                    return $place->key;
                }
            }
        }

        $key = Text::normalize($job->location_text);

        return $key === '' ? null : mb_substr($key, 0, 120);
    }
}
