<?php

namespace App\Standardization;

use App\Taxonomy\TaxonomyIndex;

/**
 * Turns the subject an ad names ("Degree in Accounts, Marketing or another relevant discipline") into known subjects.
 *
 * Each subject is matched, in this order, to: a person's decision (the Values page), then the list of fields of study
 * and their other spellings ("Accounts", "Accountancy" and "Accounting" are one subject). A subject that matches
 * nothing is kept as the ad wrote it, marked unknown, so it can be decided once on the Values page.
 * Filler that names no subject ("another relevant discipline", "a similar qualification") is dropped.
 */
class SubjectResolver
{
    /** Words that show the text describes a kind of qualification, not a subject. */
    private const FILLER = '/\b(?:relevant|similar|related|equivalent|appropriate|discipline|qualification|field|other|another)s?\b/iu';

    public function __construct(private TermDictionary $terms, private TaxonomyIndex $taxonomy) {}

    /**
     * @return list<array{name: string, key: string, known: bool}> in the order written, each subject once
     */
    public function resolve(?string $field): array
    {
        $out = [];

        foreach (preg_split('/\s*[,;\/]\s*(?:(?:or|and)\s+)?|\s+or\s+/iu', (string) $field) as $part) {
            // "Accountancy will be a plus": the subject is what comes before the verb
            $part = preg_replace('/\s+(?:will|would|is|are|shall|should|must|may|can)\b.*$/iu', '', $part);
            $part = trim((string) preg_replace('/\s+/u', ' ', (string) $part), " \t.");
            $part = preg_replace('/^(?:an?|the)\s+(?=\S)/iu', '', $part); // "a Marketing" is Marketing
            if ($part === '' || preg_match(self::FILLER, $part)) {
                continue;
            }

            $key = Text::normalize($part);
            $mapped = $this->terms->find('field', $key);

            if ($mapped) {
                if ($mapped->meta['ignore'] ?? false) {
                    continue; // a person said this names no subject
                }
                $name = (string) $mapped->standard_value;
                $known = true;
            } elseif ($entry = $this->taxonomy->find('field', $part)) {
                $name = $entry->name;
                $known = true;
            } else {
                $name = mb_strtoupper(mb_substr($part, 0, 1)).mb_substr(preg_replace('/\s+and\s+/iu', ' & ', $part), 1);
                $known = false;
            }

            $out[mb_strtolower($name)] ??= ['name' => $name, 'key' => $key, 'known' => $known];
        }

        return array_values($out);
    }

    /** The subjects as one text, in the form stored on the job. Null when none is left. */
    public function names(?string $field): ?string
    {
        $names = array_column($this->resolve($field), 'name');

        return $names ? implode(', ', $names) : null;
    }

    public function flush(): void
    {
        $this->terms->flush();
        $this->taxonomy->flush();
    }
}
