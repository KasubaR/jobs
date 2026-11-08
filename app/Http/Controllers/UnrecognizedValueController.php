<?php

namespace App\Http\Controllers;

use App\Standardization\DefaultTerms;
use App\Standardization\ExperienceParser;
use App\Standardization\JobStandardizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Values the rules could not standardize. One decision here teaches the dictionary and fixes every job waiting on it. */
class UnrecognizedValueController extends Controller
{
    public const KINDS = [
        'qualification' => 'Qualifications',
        'experience' => 'Experience',
        'employment_type' => 'Job types',
        'location' => 'Places',
    ];

    public function index(): View
    {
        $rows = DB::table('unrecognized_values')
            ->selectRaw('kind, normalized, min(sample) as sample, max(reason) as reason, count(distinct job_id) as jobs')
            ->groupBy('kind', 'normalized')->orderByDesc('jobs')->orderBy('normalized')->get()->groupBy('kind');

        return view('values.index', [
            'kinds' => self::KINDS,
            'rows' => $rows,
            'levels' => collect(DefaultTerms::LEVELS)->map(fn ($l) => $l[0]),
            'types' => DefaultTerms::EMPLOYMENT_TYPES,
            'places' => DB::table('locations')->orderBy('name')->get(['id', 'name', 'type']),
            'mapped' => DB::table('term_mappings')->where('origin', 'person')->count(),
        ]);
    }

    public function store(Request $request, JobStandardizer $standardizer): RedirectResponse
    {
        $base = $request->validate([
            'kind' => ['required', Rule::in(array_keys(self::KINDS))],
            'normalized' => ['required', 'string', 'max:191'],
            'action' => ['required', Rule::in(['map', 'ignore'])],
        ]);

        // Only values that are actually waiting can be mapped, so nothing arbitrary is written into the dictionary.
        abort_unless(DB::table('unrecognized_values')->where('kind', $base['kind'])->where('normalized', $base['normalized'])->exists(), 422, 'That value is not waiting for a decision.');

        $standard = null;
        $meta = ['ignore' => true];

        if ($base['action'] === 'map') {
            [$standard, $meta] = $this->mapping($request, $base['kind']);
        }

        DB::table('term_mappings')->updateOrInsert(
            ['kind' => $base['kind'], 'normalized' => $base['normalized']],
            ['standard_value' => $standard, 'meta' => json_encode($meta), 'origin' => 'person', 'updated_at' => now(), 'created_at' => now()],
        );

        $jobs = $standardizer->reapply($base['kind'], $base['normalized']);

        return redirect()->route('values.index')->with('status', "Saved. {$jobs} ".($jobs === 1 ? 'job was' : 'jobs were').' updated, and the same text is understood from now on.');
    }

    /** @return array{0: ?string, 1: array<string, mixed>} the standard value and its details */
    private function mapping(Request $request, string $kind): array
    {
        switch ($kind) {
            case 'qualification':
                $d = $request->validate(['level' => ['required', Rule::in(array_keys(DefaultTerms::LEVELS))], 'field' => ['nullable', 'string', 'max:120']]);
                $field = trim((string) ($d['field'] ?? '')) ?: null;
                $name = DefaultTerms::LEVELS[$d['level']][0];

                return [$field ? "{$name} in {$field}" : $name, ['level' => $d['level'], 'field' => $field]];

            case 'experience':
                $d = $request->validate(['min_years' => ['required', 'numeric', 'min:0', 'max:60'], 'max_years' => ['nullable', 'numeric', 'gte:min_years', 'max:60']]);
                $min = (int) round($d['min_years'] * 12);
                $max = isset($d['max_years']) ? (int) round($d['max_years'] * 12) : null;

                return [ExperienceParser::label($min, $max, false), ['min' => $min, 'max' => $max === $min ? null : $max]];

            case 'employment_type':
                $d = $request->validate(['type' => ['required', Rule::in(array_keys(DefaultTerms::EMPLOYMENT_TYPES))]]);

                return [$d['type'], []];

            default: // location
                $d = $request->validate(['location_id' => ['required', 'integer', Rule::exists('locations', 'id')]]);

                return [DB::table('locations')->where('id', $d['location_id'])->value('name'), ['location_id' => (int) $d['location_id']]];
        }
    }
}
