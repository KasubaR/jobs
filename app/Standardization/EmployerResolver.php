<?php

namespace App\Standardization;

use Illuminate\Support\Facades\DB;

/**
 * Finds or creates the employer record for an employer name. "Zamtel (Z) Ltd" and "ZAMTEL" are one employer;
 * "Zambia Sugar" and "Zambia Breweries" stay two. The name as the ad wrote it stays on the job (employer_text).
 */
class EmployerResolver
{
    public function __construct(private TermDictionary $terms) {}

    public function resolve(?string $name): ?int
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $name));
        $key = Text::employerKey($name);

        if ($key === '' || mb_strlen($key) < 2) {
            return null;
        }

        if (($alias = $this->terms->find('employer', $key)) && isset($alias->meta['employer_id'])) {
            return (int) $alias->meta['employer_id']; // two names a person has said are one employer
        }

        $id = DB::table('employers')->where('normalized_name', $key)->value('id');

        return $id ?? DB::table('employers')->insertGetId([
            'name' => mb_substr($name, 0, 255), 'normalized_name' => mb_substr($key, 0, 255), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
