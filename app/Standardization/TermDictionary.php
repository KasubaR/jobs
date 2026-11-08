<?php

namespace App\Standardization;

use Illuminate\Support\Facades\DB;

/** Reads the dictionary once per kind and keeps it for the life of the object. Call flush() after a mapping changes. */
class TermDictionary
{
    /** @var array<string, array<string, object>> */
    private array $rows = [];

    private ?array $places = null;

    /** @return array<string, object> every row of a kind, keyed by its normalized text */
    public function all(string $kind): array
    {
        return $this->rows[$kind] ??= DB::table('term_mappings')->where('kind', $kind)->get()
            ->mapWithKeys(function ($row) {
                $row->meta = $row->meta ? json_decode($row->meta, true) : [];

                return [$row->normalized => $row];
            })->all();
    }

    public function find(string $kind, string $normalized): ?object
    {
        return $this->all($kind)[$normalized] ?? null;
    }

    /** Phrases of a kind with their standard value, longest first (so a longer phrase is used up before a shorter one inside it). */
    public function phrases(string $kind): array
    {
        $phrases = array_map(fn ($r) => $r->standard_value, $this->all($kind));
        uksort($phrases, fn ($a, $b) => strlen($b) <=> strlen($a) ?: strcmp($a, $b));

        return $phrases;
    }

    /** @return list<object> every place: id, name, type, parent_id, key (normalized name) */
    public function places(): array
    {
        return $this->places ??= DB::table('locations')->get()->map(function ($p) {
            $p->key = Text::normalize($p->name);

            return $p;
        })->all();
    }

    public function flush(): void
    {
        $this->rows = [];
        $this->places = null;
    }
}
