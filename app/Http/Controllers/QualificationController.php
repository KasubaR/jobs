<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Standardization\DefaultTerms;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * What employers ask for, counted once per vacancy. A vacancy found on three sites is one vacancy (the copies and the
 * rejected ads are left out), so a qualification is never made to look more popular than it is.
 *
 * Today each vacancy has the one qualification and the experience the ad stated in a form the reader recognises.
 * Reading the whole body of the ad, and the skills, is the next build step; this page will then draw on that.
 */
class QualificationController extends Controller
{
    private const TOP = 15;

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'scope' => ['nullable', 'in:all,open'],
            'province' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('type', 'province')],
        ]);

        $scope = $filters['scope'] ?? 'all';
        $province = $filters['province'] ?? null;

        // every count below starts from the same set of vacancies
        $base = fn () => Job::query()
            ->whereNotIn('status', ['duplicate', 'rejected'])
            ->when($scope === 'open', fn ($q) => $q->where('status', 'published'))
            ->when($province, fn ($q, $p) => $q->whereIn('location_id', DB::table('locations')->where('id', $p)->orWhere('parent_id', $p)->select('id')));

        $total = $base()->count();
        $read = $base()->whereNotNull('qualification_level')->count();
        $notRecognised = $base()->whereNull('qualification_level')->whereNotNull('qualification_text')->count();
        $experienceRead = $base()->whereNotNull('experience_label')->count();

        $levels = $base()->whereNotNull('qualification_level')
            ->selectRaw('qualification_level as k, qualification_rank as r, count(*) as n')->groupBy('qualification_level', 'qualification_rank')->orderByDesc('n')->get();

        // "Degree in Law, Procurement, Engineering" asks for three different qualifications: each counts once for that vacancy
        $withField = $base()->whereNotNull('qualification_field')->get(['qualification_level', 'qualification_field']);
        $labelCounts = [];
        $fieldCounts = [];
        foreach ($withField as $job) {
            $level = DefaultTerms::LEVELS[$job->qualification_level][0] ?? ucfirst((string) $job->qualification_level);
            foreach ($this->subjects($job->qualification_field) as $subject) {
                $fieldCounts[$subject] = ($fieldCounts[$subject] ?? 0) + 1;
                $labelCounts["{$level} in {$subject}"] = ($labelCounts["{$level} in {$subject}"] ?? 0) + 1;
            }
        }
        $labels = $this->ranked($labelCounts);
        $fields = $this->ranked($fieldCounts);

        return view('qualifications.index', [
            'filters' => ['scope' => $scope, 'province' => $province],
            'provinces' => DB::table('locations')->where('type', 'province')->orderBy('name')->pluck('name', 'id'),
            'total' => $total,
            'read' => $read,
            'notRecognised' => $notRecognised,
            'nothing' => max(0, $total - $read - $notRecognised),
            'levels' => $levels->map(fn ($l) => ['name' => DefaultTerms::LEVELS[$l->k][0] ?? ucfirst($l->k), 'n' => (int) $l->n]),
            // only qualifications that name a subject: a bare level ("Diploma") is already in the Education level card
            'labels' => $labels,
            'labelsNamed' => $withField->count(),
            'fields' => $fields,
            'experienceRead' => $experienceRead,
            'experience' => $base()->whereNotNull('experience_label')
                ->selectRaw('experience_label as name, min(experience_min_months) as months, count(*) as n')->groupBy('experience_label')->orderByDesc('n')->limit(10)->get()
                ->map(fn ($e) => ['name' => $e->name, 'n' => (int) $e->n]),
        ]);
    }

    /**
     * The separate subjects in a field of study. Commas, semicolons, "/" and " or " separate them; "and" and "&" do not,
     * because "Banking & Finance" and "Science and Technology" are one subject each.
     *
     * @return list<string>
     */
    private function subjects(string $field): array
    {
        $seen = [];
        foreach (preg_split('/\s*[,;\/]\s*(?:(?:or|and)\s+)?|\s+or\s+/iu', $field) as $part) {
            $part = trim($part, " \t.");
            if ($part !== '') {
                $seen[mb_strtolower($part)] ??= mb_strtoupper(mb_substr($part, 0, 1)).mb_substr($part, 1);
            }
        }

        return array_values($seen);
    }

    /** @param array<string, int> $counts @return \Illuminate\Support\Collection<int, array{name: string, n: int}> */
    private function ranked(array $counts)
    {
        $rows = collect($counts)->map(fn ($n, $name) => ['name' => (string) $name, 'n' => $n])->values();

        return $rows->sort(fn ($a, $b) => [$b['n'], $a['name']] <=> [$a['n'], $b['name']])->take(self::TOP)->values();
    }
}
