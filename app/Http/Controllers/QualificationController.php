<?php

namespace App\Http\Controllers;

use App\Analytics\AdvertisedDemand;
use App\Models\Job;
use App\Standardization\DefaultTerms;
use App\Standardization\SubjectResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * What employers ask for, counted once per vacancy. A vacancy found on three sites is one vacancy (the copies and the
 * rejected ads are left out), so a qualification is never made to look more popular than it is.
 *
 * The qualification and experience figures come from what the ad stated in a form the reader recognises; the skills
 * come from the reader of the whole ad, through AdvertisedDemand (required and preferred only).
 */
class QualificationController extends Controller
{
    /** How many rows each list on the main page shows; the full list is on its own page. */
    private const SHOWN = 5;

    public function __construct(private SubjectResolver $subjects) {}

    /** The most rows any one list can have on its own page. */
    private const MOST = 500;

    public function index(Request $request): View
    {
        [$scope, $province] = $this->filters($request);

        // every count below starts from the same set of vacancies
        $base = fn () => $this->base($scope, $province);

        $total = $base()->count();
        $read = $base()->whereNotNull('qualification_level')->count();
        $notRecognised = $base()->whereNull('qualification_level')->whereNotNull('qualification_text')->count();
        $experienceRead = $base()->whereNotNull('experience_label')->count();

        $levels = $base()->whereNotNull('qualification_level')
            ->selectRaw('qualification_level as k, count(*) as n')->groupBy('qualification_level')->get()
            // "Degree" and "Bachelor's Degree" are the same rung: an ad that says only "degree" means a first degree
            ->groupBy(fn ($l) => $l->k === 'degree' ? 'bachelor' : $l->k)
            ->map(fn ($rows, $k) => ['name' => DefaultTerms::LEVELS[$k][0] ?? ucfirst((string) $k), 'n' => (int) $rows->sum('n')])
            ->sortByDesc('n')->values();

        [$labelCounts, $fieldCounts, $labelsNamed] = $this->labelCounts($base());
        $labels = $this->ranked($labelCounts);
        $fields = $this->ranked($fieldCounts);
        $experience = $this->experienceRows($base());

        $demand = new AdvertisedDemand($scope, $province ? (int) $province : null);
        $skills = $demand->skillsAsked(self::MOST);

        return view('qualifications.index', [
            'skills' => $skills->take(self::SHOWN)->values(),
            'skillsTotal' => $skills->count(),
            'skillVacancies' => $demand->analysedQuery()->count(),
            'filters' => ['scope' => $scope, 'province' => $province],
            'provinces' => DB::table('locations')->where('type', 'province')->orderBy('name')->pluck('name', 'id'),
            'total' => $total,
            'read' => $read,
            'notRecognised' => $notRecognised,
            'nothing' => max(0, $total - $read - $notRecognised),
            'levels' => $levels,
            // only qualifications that name a subject: a bare level ("Diploma") is already in the Education level card
            'labels' => $labels->take(self::SHOWN)->values(),
            'labelsNamed' => $labelsNamed,
            'labelsTotal' => $labels->count(),
            'fields' => $fields->take(self::SHOWN)->values(),
            'fieldsTotal' => $fields->count(),
            'experienceRead' => $experienceRead,
            'experience' => $experience->take(self::SHOWN)->values(),
            'experienceTotal' => $experience->count(),
        ]);
    }

    /** One list in full: every row, not only the top few. */
    public function all(Request $request, string $panel): View
    {
        [$scope, $province] = $this->filters($request);
        $base = $this->base($scope, $province);

        [$labelCounts, $fieldCounts, $named] = $this->labelCounts(clone $base);
        $read = (clone $base)->whereNotNull('experience_label')->count();
        $demand = new AdvertisedDemand($scope, $province ? (int) $province : null);
        $analysed = $demand->analysedQuery()->count();

        [$title, $sub, $rows, $of] = match ($panel) {
            'qualifications' => ['Most requested qualifications', 'Level and subject, of '.number_format($named).' '.Str::plural('vacancy', $named).' that name a subject', $this->ranked($labelCounts), $named],
            'fields' => ['Fields of study', 'Where the ad names one, of '.number_format($named).' '.Str::plural('vacancy', $named), $this->ranked($fieldCounts), $named],
            'experience' => ['Experience asked for', number_format($read).' '.Str::plural('vacancy', $read).' state a length of experience', $this->experienceRows($base), $read],
            'skills' => ['Skills', 'Required or preferred, of '.number_format($analysed).' '.Str::plural('vacancy', $analysed).' the reader has analysed', $demand->skillsAsked(self::MOST)
                ->map(fn ($r) => ['name' => $r->name, 'n' => (int) $r->vacancies, 'text' => $r->share !== null ? $r->share.'%' : 'too few for a share'])->values(), $analysed],
            default => abort(404),
        };

        return view('qualifications.all', ['title' => $title, 'sub' => $sub, 'rows' => $rows, 'of' => $of, 'filters' => ['scope' => $scope, 'province' => $province]]);
    }

    /** @return array{0: string, 1: mixed} */
    private function filters(Request $request): array
    {
        $filters = $request->validate([
            'scope' => ['nullable', 'in:all,open'],
            'province' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('type', 'province')],
        ]);

        return [$filters['scope'] ?? 'all', $filters['province'] ?? null];
    }

    /** The vacancies every count starts from: copies and rejected ads are left out. */
    private function base(string $scope, mixed $province)
    {
        return Job::query()
            ->whereNotIn('status', ['duplicate', 'rejected'])
            ->when($scope === 'open', fn ($q) => $q->where('status', 'published'))
            ->when($province, fn ($q, $p) => $q->whereIn('location_id', DB::table('locations')->where('id', $p)->orWhere('parent_id', $p)->select('id')));
    }

    /** @return Collection<int, array{name: string, n: int}> */
    private function experienceRows($base): Collection
    {
        return (clone $base)->whereNotNull('experience_label')
            ->selectRaw('experience_label as name, count(*) as n')->groupBy('experience_label')->orderByDesc('n')->get()
            ->map(fn ($e) => ['name' => $e->name, 'n' => (int) $e->n]);
    }

    /**
     * "Degree in Law, Procurement, Engineering" asks for three different qualifications: each counts once for that vacancy.
     *
     * @return array{0: array<string,int>, 1: array<string,int>, 2: int} level-and-subject counts, subject counts, vacancies naming a subject
     */
    private function labelCounts($query): array
    {
        $withField = $query->whereNotNull('qualification_field')->get(['qualification_level', 'qualification_field']);
        $labelCounts = [];
        $fieldCounts = [];
        foreach ($withField as $job) {
            $levelKey = $job->qualification_level === 'degree' ? 'bachelor' : $job->qualification_level;
            $level = DefaultTerms::LEVELS[$levelKey][0] ?? ucfirst((string) $levelKey);
            foreach ($this->subjects($job->qualification_field) as $subject) {
                $fieldCounts[$subject] = ($fieldCounts[$subject] ?? 0) + 1;
                $labelCounts["{$level} in {$subject}"] = ($labelCounts["{$level} in {$subject}"] ?? 0) + 1;
            }
        }

        return [$labelCounts, $fieldCounts, $withField->count()];
    }

    /**
     * The separate subjects in a field of study, each matched to a known subject (see SubjectResolver). Commas,
     * semicolons, "/" and " or " separate them; "and" and "&" do not, because "Banking & Finance" is one subject.
     *
     * @return list<string>
     */
    private function subjects(string $field): array
    {
        return array_column($this->subjects->resolve($field), 'name');
    }

    /** @param array<string, int> $counts @return Collection<int, array{name: string, n: int}> most requested first, then by name */
    private function ranked(array $counts): Collection
    {
        return collect($counts)->map(fn ($n, $name) => ['name' => (string) $name, 'n' => $n])->values()
            ->sort(fn ($a, $b) => [$b['n'], $a['name']] <=> [$a['n'], $b['name']])->values();
    }
}
