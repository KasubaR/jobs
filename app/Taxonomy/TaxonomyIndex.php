<?php

namespace App\Taxonomy;

use App\Standardization\Text;
use Illuminate\Support\Facades\DB;

/**
 * Looks wording up in the taxonomy: what a phrase IS (find) and which entries a piece of text MENTIONS (mentions).
 * Read-only. The tables are read once per kind and kept for the life of the object; call flush() after a change.
 *
 * Matching is on whole words after Text::normalize (lower case, no punctuation), longest phrase first, and each
 * stretch of text is used up once ("Microsoft Excel" is one mention, not Microsoft + Excel). An alias that starts
 * with "=" in the data must match with exactly that capitalisation, for short acronyms that are also ordinary
 * words ("=SAP" matches "SAP" and not "sap"); those are matched on the original text.
 */
class TaxonomyIndex
{
    /**
     * kind => [table, label column, whether the label is itself wording to look for].
     * A seniority level's name ("Head", "Standard") is a label for people, not a word to find in a job title.
     */
    public const KINDS = [
        'skill' => ['skills', 'name', true],
        'award' => ['academic_awards', 'name', true],
        'field' => ['fields_of_study', 'name', true],
        'professional' => ['professional_qualifications', 'name', true],
        'registration' => ['professional_registrations', 'name', true],
        'occupation' => ['occupations', 'title', true],
        'industry' => ['industries', 'name', true],
        'seniority' => ['seniority_levels', 'name', false],
    ];

    /** Words that signal seniority and say nothing about which occupation it is. */
    private const LEVEL_WORDS = ['senior', 'snr', 'sr', 'junior', 'jnr', 'jr', 'principal', 'lead'];

    /** @var array<string, array{entries: array<int, object>, phrases: array<string, object>, exact: list<array{0:string,1:object,2:string}>, conflicts: array<string, list<string>>}> */
    private array $built = [];

    /** The entry a phrase names exactly (a name or an alias), or null. */
    public function find(string $kind, string $text): ?object
    {
        $idx = $this->index($kind);
        $key = Text::normalize($text);

        if ($key !== '' && isset($idx['phrases'][$key])) {
            return $idx['phrases'][$key];
        }

        foreach ($idx['exact'] as [$pattern, $entry]) {
            if (preg_match($pattern, $text)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Every entry of a kind the text mentions, in order of first appearance.
     *
     * @return list<object>
     */
    public function mentions(string $kind, string $text): array
    {
        $idx = $this->index($kind);
        $original = ' '.Text::normalize($text).' ';
        $rest = $original;
        $hits = [];

        foreach ($idx['phrases'] as $phrase => $entry) { // longest first
            $needle = " {$phrase} ";

            if (! str_contains($rest, $needle)) {
                continue;
            }

            $hits[$entry->id] ??= ['entry' => $entry, 'pos' => mb_strpos($original, $needle) ?: 0];
            $rest = str_replace($needle, ' ', $rest);
        }

        $order = PHP_INT_MAX - 1000;
        foreach ($idx['exact'] as [$pattern, $entry, $plain]) {
            if (! isset($hits[$entry->id]) && preg_match($pattern, $text)) {
                // where it sits in the text, so it is listed in order of appearance with the others
                $where = $plain !== '' ? mb_strpos($original, " {$plain} ") : false;
                $hits[$entry->id] = ['entry' => $entry, 'pos' => $where === false ? $order++ : $where];
            }
        }

        uasort($hits, fn ($a, $b) => $a['pos'] <=> $b['pos']);

        return array_values(array_map(fn ($h) => $h['entry'], $hits));
    }

    /** The occupation (unit group) a job title belongs to, or null. Seniority words are ignored ("Senior Accountant" is an Accountant). */
    public function occupation(string $title): ?object
    {
        $unit = $this->unitFor(Text::normalize($title));

        if ($unit === null) {
            $words = array_values(array_filter(explode(' ', Text::normalize($title)), fn ($w) => ! in_array($w, self::LEVEL_WORDS, true)));
            $unit = $words ? $this->unitFor(implode(' ', $words)) : null;
        }

        return $unit;
    }

    /** The seniority a title signals: the highest level among its words, or "standard" when it has none. */
    public function seniority(string $title): object
    {
        $levels = $this->index('seniority')['entries'];
        $found = $this->mentions('seniority', $title);
        usort($found, fn ($a, $b) => $b->rank <=> $a->rank);

        return $found[0] ?? collect($levels)->firstWhere('key', 'standard');
    }

    /** @return array<string, list<string>> phrases that name more than one entry in a kind (a data error to fix) */
    public function conflicts(string $kind): array
    {
        return $this->index($kind)['conflicts'];
    }

    /** @return array<int, object> every entry of a kind, by id */
    public function all(string $kind): array
    {
        return $this->index($kind)['entries'];
    }

    public function flush(): void
    {
        $this->built = [];
    }

    private function unitFor(string $normalized): ?object
    {
        $entry = $normalized === '' ? null : ($this->index('occupation')['phrases'][$normalized] ?? null);

        return $entry && $entry->level === 'unit' ? $entry : null;
    }

    /** @return array{entries: array<int, object>, phrases: array<string, object>, exact: list<array{0:string,1:object,2:string}>, conflicts: array<string, list<string>>} */
    private function index(string $kind): array
    {
        if (isset($this->built[$kind])) {
            return $this->built[$kind];
        }

        [$table, $labelColumn, $labelIsWording] = self::KINDS[$kind] ?? throw new \InvalidArgumentException("Unknown taxonomy kind '{$kind}'.");

        $entries = [];
        foreach (DB::table($table)->get() as $row) {
            $row->kind = $kind;
            $row->label = $row->{$labelColumn};
            $row->aliases = $row->aliases ? (json_decode($row->aliases, true) ?: []) : [];
            $entries[$row->id] = $row;
        }

        foreach ($entries as $entry) { // a field knows its broad field; an occupation its parent group
            $entry->parent = null;
            if ($kind === 'field' && $entry->parent_id) {
                $entry->parent = $entries[$entry->parent_id] ?? null;
            }
        }
        if ($kind === 'occupation') {
            $byCode = collect($entries)->keyBy('code');
            foreach ($entries as $entry) {
                $entry->parent = $entry->parent_code ? ($byCode[$entry->parent_code] ?? null) : null;
            }
        }

        $phrases = [];
        $conflicts = [];
        $exact = [];

        foreach ($entries as $entry) {
            // An entry whose own name is also listed as a case-exact alias ("SAP" and "=SAP") must match only in that case,
            // so its name is not also taken as plain wording ("sap", or "net" for ".NET").
            $nameIsExact = in_array('='.$entry->label, $entry->aliases, true);
            $words = $labelIsWording && ! $nameIsExact ? array_merge([$entry->label], $entry->aliases) : $entry->aliases;

            foreach ($words as $word) {
                if (str_starts_with((string) $word, '=')) {
                    $exact[] = ['/(?<![\p{L}\p{N}])'.preg_quote(substr($word, 1), '/').'(?![\p{L}\p{N}])/u', $entry, Text::normalize(substr($word, 1))];

                    continue;
                }

                $key = Text::normalize($word);
                if (mb_strlen($key) < 2 || ctype_digit($key)) {
                    continue; // too short to mean anything on its own
                }

                if (isset($phrases[$key]) && $phrases[$key]->id !== $entry->id) {
                    $conflicts[$key] = array_values(array_unique([...($conflicts[$key] ?? [$phrases[$key]->label]), $entry->label]));

                    continue; // the first entry keeps the phrase; the conflict is reported
                }

                $phrases[$key] = $entry;
            }
        }

        uksort($phrases, fn ($a, $b) => strlen($b) <=> strlen($a) ?: strcmp($a, $b));

        return $this->built[$kind] = ['entries' => $entries, 'phrases' => $phrases, 'exact' => $exact, 'conflicts' => $conflicts];
    }
}
