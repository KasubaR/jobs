<?php

namespace App\Http\Controllers;

use App\Models\DirectoryQualification;
use App\Taxonomy\LinkStatsBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The Qualification Directory. A profile keeps four different facts apart, each with its own source:
 * the qualification existing, it being recognised in Zambia, where it can be studied, and employer demand.
 * An advertisement only ever speaks to demand.
 */
class DirectoryController extends Controller
{
    private const TOP = 8;

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'kind' => ['nullable', 'in:academic,professional,registration,general,vocational,short_course'],
        ]);

        $term = trim((string) ($filters['q'] ?? ''));

        // Two things are not qualifications on their own and are left out of the plain list, shown only when asked for:
        //  - a membership or registration (EIZ, ERB): something a person holds, not something they studied for
        //  - a degree or diploma type with no subject ("Bachelor of Arts", "Advanced Diploma": in what?). The ad reader
        //    still needs them to recognise the wording, so they stay in the data. A school certificate is complete without one.
        $schoolLevel = DB::table('qualification_levels')->where('key', 'school')->select('id');
        $general = fn ($q) => $q->where('kind', 'academic')->whereNull('field_id')->where(fn ($w) => $w->whereNull('level_id')->orWhereNotIn('level_id', $schoolLevel));

        $qualifications = DirectoryQualification::query()->with(['level', 'awardingOrganization'])
            ->when($filters['kind'] ?? null,
                fn ($q, $kind) => $kind === 'general' ? $general($q) : $q->where('kind', $kind),
                fn ($q) => $q->where('kind', '!=', 'registration')->whereNot(fn ($w) => $general($w)))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('aliases', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%')))
            ->orderBy('name')->paginate(40)->withQueryString();

        return view('directory.index', ['qualifications' => $qualifications, 'filters' => ['q' => $term, 'kind' => $filters['kind'] ?? null]]);
    }

    public function show(DirectoryQualification $qualification): View
    {
        $id = $qualification->id;
        $qualification->load(['level', 'awardingOrganization.roles']);

        $recognitions = $qualification->recognitions()->with(['authority.roles', 'evidence'])->get();
        $programmes = $qualification->programmes()->with('provider')->get();
        $locations = DB::table('locations')->whereIn('id', $programmes->pluck('location_id')->filter())->pluck('name', 'id');

        $relations = DB::table('qualification_relationships as r')
            ->join('directory_qualifications as o', 'o.id', '=', DB::raw('case when r.from_id = '.(int) $id.' then r.to_id else r.from_id end'))
            ->where(fn ($q) => $q->where('r.from_id', $id)->orWhere('r.to_id', $id))
            ->get(['r.id', 'r.type', 'r.basis', 'r.note', 'r.verified_at', 'r.from_id', 'o.id as other_id', 'o.name as other_name']);

        return view('directory.show', [
            'q' => $qualification,
            'field' => $qualification->field_id ? DB::table('fields_of_study as f')->leftJoin('fields_of_study as p', 'p.id', '=', 'f.parent_id')->where('f.id', $qualification->field_id)->first(['f.name', 'p.name as parent']) : null,
            'recognitions' => $recognitions,
            'programmes' => $programmes,
            'locations' => $locations,
            'relations' => $relations,
            'evidence' => $this->evidence($qualification, $recognitions, $programmes, $relations->pluck('id')),
            'history' => $this->history($qualification, $recognitions, $programmes, $relations->pluck('id')),
            'demand' => $this->demand($id),
            'family' => $this->family($qualification),
            'links' => $this->links($id),
            'wordings' => $this->wordings($id),
            'minSample' => LinkStatsBuilder::MIN_SAMPLE,
        ]);
    }

    /** Every source behind this page's facts, newest first. */
    private function evidence(DirectoryQualification $q, $recognitions, $programmes, $relationIds)
    {
        return DB::table('evidence')->where(function ($w) use ($q, $recognitions, $programmes, $relationIds) {
            $w->where(fn ($x) => $x->where('subject_type', DirectoryQualification::class)->where('subject_id', $q->id));
            foreach ([[\App\Models\Recognition::class, $recognitions], [\App\Models\Programme::class, $programmes], [\App\Models\QualificationRelationship::class, $relationIds->map(fn ($id) => (object) ['id' => $id])]] as [$type, $rows]) {
                if ($rows->isNotEmpty()) {
                    $w->orWhere(fn ($x) => $x->where('subject_type', $type)->whereIn('subject_id', $rows->pluck('id')));
                }
            }
        })->orderByDesc('verified_at')->orderByDesc('id')->get();
    }

    /**
     * The same level in the same field family: "Accounting", "Finance" and the broad "Accounting and Finance" are one family,
     * so "Bachelor's in Accounting" and "Bachelor's in Accounting and Finance" are related, and the family's demand counts a
     * vacancy once however many of them it asks for. The exact entries stay separate; this only relates them.
     *
     * @return ?array{name:string, members:\Illuminate\Support\Collection, vacancies:int}
     */
    private function family(DirectoryQualification $q): ?array
    {
        $field = $q->field_id ? DB::table('fields_of_study')->where('id', $q->field_id)->first() : null;
        if (! $field || ! $q->level_id) {
            return null;
        }

        $rootId = $field->parent_id ?? $field->id;
        $ids = DB::table('fields_of_study')->where('id', $rootId)->orWhere('parent_id', $rootId)->pluck('id');

        $entries = DB::table('directory_qualifications')->where('kind', 'academic')->where('level_id', $q->level_id)
            ->whereIn('field_id', $ids)->whereNull('legacy_table')->get(['id', 'name']);
        if ($entries->count() < 2) {
            return null;
        }

        $perEntry = DB::table('job_qualifications as jq')->join('jobs', 'jobs.id', '=', 'jq.job_id')
            ->whereIn('jq.directory_qualification_id', $entries->pluck('id'))->where('jq.removed', false)->whereIn('jq.requirement_level', ['required', 'preferred'])
            ->whereNotIn('jobs.status', ['duplicate', 'rejected'])
            ->groupBy('jq.directory_qualification_id')->selectRaw('jq.directory_qualification_id as id, count(distinct jq.job_id) as n')->pluck('n', 'id');

        $together = DB::table('job_qualifications as jq')->join('jobs', 'jobs.id', '=', 'jq.job_id')
            ->whereIn('jq.directory_qualification_id', $entries->pluck('id'))->where('jq.removed', false)->whereIn('jq.requirement_level', ['required', 'preferred'])
            ->whereNotIn('jobs.status', ['duplicate', 'rejected'])->distinct()->count('jq.job_id');

        return [
            'name' => DB::table('fields_of_study')->where('id', $rootId)->value('name'),
            'members' => $entries->where('id', '!=', $q->id)->map(fn ($e) => (object) ['id' => $e->id, 'name' => $e->name, 'vacancies' => (int) ($perEntry[$e->id] ?? 0)])->sortByDesc('vacancies')->values(),
            'vacancies' => $together,
        ];
    }

    /** What was changed, by whom and through which door, for this qualification and the records hanging off it. */
    private function history(DirectoryQualification $q, $recognitions, $programmes, $relationIds)
    {
        $subjects = ['directory_qualifications' => collect([$q->id]), 'recognitions' => $recognitions->pluck('id'), 'programmes' => $programmes->pluck('id'), 'qualification_relationships' => $relationIds];

        return DB::table('data_change_logs')->where(function ($w) use ($subjects) {
            foreach ($subjects as $table => $ids) {
                if ($ids->isNotEmpty()) {
                    $w->orWhere(fn ($x) => $x->where('table_name', $table)->whereIn('row_id', $ids));
                }
            }
        })->orderByDesc('id')->limit(15)->get();
    }

    /** Vacancies asking for it, counted once each (copies and rejected ads left out; required or preferred only). */
    private function demand(int $id): array
    {
        $rows = DB::table('job_qualifications as jq')->join('jobs', 'jobs.id', '=', 'jq.job_id')
            ->where('jq.directory_qualification_id', $id)->where('jq.removed', false)->whereIn('jq.requirement_level', ['required', 'preferred'])
            ->whereNotIn('jobs.status', ['duplicate', 'rejected'])
            ->groupBy('jobs.id', 'jobs.status')
            ->selectRaw("jobs.id, jobs.status, max(case when jq.requirement_level = 'required' then 1 else 0 end) as required")->get();

        return [
            'vacancies' => $rows->count(),
            'required' => $rows->where('required', 1)->count(),
            'open' => $rows->where('status', 'published')->count(),
        ];
    }

    /** @return array{occupation: \Illuminate\Support\Collection, skill: \Illuminate\Support\Collection, industry: \Illuminate\Support\Collection, sample: int, computed_at: ?string} */
    private function links(int $id): array
    {
        $stats = DB::table('qualification_link_stats')->where('directory_qualification_id', $id)->orderByDesc('jobs')->get()->groupBy('link_type');
        $names = [
            'occupation' => DB::table('occupations')->pluck('title', 'code'),
            'skill' => DB::table('skills')->pluck('name', 'id'),
            'industry' => DB::table('industries')->pluck('name', 'id'),
        ];

        $out = ['sample' => (int) ($stats->flatten()->first()->qualification_jobs ?? 0), 'computed_at' => $stats->flatten()->first()->computed_at ?? null];
        // a vacancy has one occupation and one industry, so these add up to how many vacancies the figure is known for
        $out['known'] = ['occupation' => (int) ($stats['occupation'] ?? collect())->sum('jobs'), 'industry' => (int) ($stats['industry'] ?? collect())->sum('jobs')];
        foreach ($names as $type => $lookup) {
            $out[$type] = ($stats[$type] ?? collect())->take(self::TOP)->map(fn ($s) => (object) [
                'name' => $lookup[$s->target] ?? $s->target, 'jobs' => $s->jobs, 'share' => $s->qualification_jobs > 0 ? round($s->jobs / $s->qualification_jobs * 100) : 0,
            ])->values();
        }

        return $out;
    }

    /** How employers actually wrote it, in their own words (kept only while the ad text is kept). */
    private function wordings(int $id)
    {
        return DB::table('job_qualifications as jq')->join('jobs', 'jobs.id', '=', 'jq.job_id')
            ->where('jq.directory_qualification_id', $id)->where('jq.removed', false)->whereNotNull('jq.evidence')
            ->whereNotIn('jobs.status', ['duplicate', 'rejected'])
            ->groupBy('jq.evidence')->selectRaw('jq.evidence, count(*) as n, max(jobs.id) as job_id')->orderByDesc('n')->limit(6)->get();
    }
}
