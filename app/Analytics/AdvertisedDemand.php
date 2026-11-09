<?php

namespace App\Analytics;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one definition of "advertised demand", so every report counts the same way.
 *
 *  - A vacancy counts once. Copies of the same vacancy and rejected ads are left out.
 *  - Only ads the reader has analysed are measured (analysis done or waiting for review). Ads that were not read, or
 *    whose text is gone, are reported as the gap between "collected" and "analysed", never counted as "asked for nothing".
 *  - Only items that count as "what employers ask for": not removed by a person, and required or preferred. An
 *    "added advantage" is shown elsewhere and a mere mention is ignored.
 *  - A figure is a share of ANALYSED vacancies and always comes with that number. Below MIN_SAMPLE vacancies it is
 *    marked unreliable and reports show the count without a share.
 *
 * These figures describe advertisements in the collected sources. They are not the Zambian labour market and not hiring.
 */
class AdvertisedDemand
{
    public const MIN_SAMPLE = 5;

    public const COUNTED = ['required', 'preferred'];

    public const DEGREE_LEVELS = ['bachelor', 'postgraduate', 'masters', 'phd'];

    /** A vacancy is entry-level when its title says so (intern, junior, graduate) or it asks for at most a year of experience. */
    public const ENTRY_SENIORITY = ['intern', 'junior'];

    public const ENTRY_MAX_YEARS = 1;

    /** @param 'all'|'open' $scope open = still published */
    public function __construct(public readonly string $scope = 'all', public readonly ?int $province = null) {}

    /** Vacancies counted once, read or not. */
    public function collectedQuery(): Builder
    {
        return DB::table('jobs')->whereNotIn('jobs.status', ['duplicate', 'rejected'])
            ->when($this->scope === 'open', fn ($q) => $q->where('jobs.status', 'published'))
            ->when($this->province, fn ($q, $p) => $q->whereIn('jobs.location_id', DB::table('locations')->where('id', $p)->orWhere('parent_id', $p)->select('id')));
    }

    /** Vacancies counted once that the reader has analysed. */
    public function analysedQuery(): Builder
    {
        return $this->collectedQuery()->whereIn('jobs.analysis_status', ['done', 'needs_review']);
    }

    /** @return array{collected:int, analysed:int, stating:int} stating = analysed vacancies that ask for at least one qualification */
    public function counts(): array
    {
        return [
            'collected' => $this->collectedQuery()->count(),
            'analysed' => $this->analysedQuery()->count(),
            'stating' => $this->asks()->distinct()->count('jobs.id'),
        ];
    }

    /**
     * The qualifications asked for, most often first.
     *
     * @return Collection<int, object{id:int, name:string, kind:string, vacancies:int, required:int, share:?float, reliable:bool}>
     */
    public function qualificationsAsked(int $limit = 15): Collection
    {
        $analysed = max(1, $this->analysedQuery()->count());

        return $this->asks()->join('directory_qualifications as d', 'd.id', '=', 'jq.directory_qualification_id')
            ->groupBy('d.id', 'd.name', 'd.kind')
            ->selectRaw("d.id, d.name, d.kind, count(distinct jobs.id) as vacancies, count(distinct case when jq.requirement_level = 'required' then jobs.id end) as required")
            ->orderByDesc('vacancies')->orderBy('d.name')->limit($limit)->get()
            ->map(fn ($r) => $this->withShare($r, $analysed));
    }

    /**
     * Education levels asked for (a vacancy asking for either a diploma or a degree counts under both).
     *
     * @return Collection<int, object{key:string, name:string, vacancies:int, share:?float, reliable:bool}>
     */
    public function levels(?Builder $vacancies = null): Collection
    {
        $analysed = max(1, ($vacancies ?? $this->analysedQuery())->count());

        return $this->asks($vacancies)->join('qualification_levels as l', 'l.key', '=', 'jq.education_level')
            ->groupBy('l.key', 'l.name', 'l.rank')->selectRaw('l.key, l.name, l.rank, count(distinct jobs.id) as vacancies')
            ->orderBy('l.rank')->get()->map(fn ($r) => $this->withShare($r, $analysed));
    }

    /**
     * Entry-level vacancies, and what they ask for.
     *
     * @return array{vacancies:int, reliable:bool, levels:Collection, qualifications:Collection}
     */
    public function entryLevel(): array
    {
        $entry = fn () => $this->analysedQuery()->where(fn ($q) => $q->whereIn('jobs.seniority', self::ENTRY_SENIORITY)
            ->orWhereExists(fn ($e) => $e->from('job_experiences as je')->whereColumn('je.job_id', 'jobs.id')->where('je.removed', false)
                ->whereIn('je.requirement_level', self::COUNTED)->whereNotNull('je.min_years')->where('je.min_years', '<=', self::ENTRY_MAX_YEARS)));

        $total = $entry()->count();

        return [
            'vacancies' => $total,
            'reliable' => $total >= self::MIN_SAMPLE,
            'levels' => $this->levels($entry()),
            'qualifications' => $this->asks($entry())->join('directory_qualifications as d', 'd.id', '=', 'jq.directory_qualification_id')
                ->groupBy('d.id', 'd.name', 'd.kind')->selectRaw('d.id, d.name, d.kind, count(distinct jobs.id) as vacancies')
                ->orderByDesc('vacancies')->orderBy('d.name')->limit(10)->get()->map(fn ($r) => $this->withShare($r, max(1, $total))),
        ];
    }

    /**
     * Professional qualifications (ACCA, CIMA ...) and how often the same vacancy also asks for a degree.
     *
     * @return array{degree_vacancies:int, with_professional:int, qualifications:Collection}
     */
    public function professionalWithDegrees(): array
    {
        $degree = "exists (select 1 from job_qualifications dq where dq.job_id = jobs.id and dq.removed = 0 and dq.requirement_level in ('required', 'preferred') and dq.education_level in ('".implode("', '", self::DEGREE_LEVELS)."'))";

        $degreeVacancies = $this->asks()->whereIn('jq.education_level', self::DEGREE_LEVELS)->distinct()->count('jobs.id');
        $withProfessional = $this->asks()->join('directory_qualifications as p', 'p.id', '=', 'jq.directory_qualification_id')->where('p.kind', 'professional')
            ->whereRaw($degree)->distinct()->count('jobs.id');

        $rows = $this->asks()->join('directory_qualifications as d', 'd.id', '=', 'jq.directory_qualification_id')->where('d.kind', 'professional')
            ->groupBy('d.id', 'd.name')
            ->selectRaw("d.id, d.name, count(distinct jobs.id) as vacancies, count(distinct case when {$degree} then jobs.id end) as with_degree")
            ->orderByDesc('vacancies')->orderBy('d.name')->limit(10)->get()
            ->map(function ($r) {
                $r->reliable = $r->vacancies >= self::MIN_SAMPLE;
                $r->share = $r->reliable ? round($r->with_degree / $r->vacancies * 100) : null;

                return $r;
            });

        return ['degree_vacancies' => $degreeVacancies, 'with_professional' => $withProfessional, 'qualifications' => $rows];
    }

    // ---- by group: an occupation or an industry ----

    public const DIMENSIONS = ['occupation', 'industry'];

    /**
     * The occupations (at unit group or sub-major level) or industries that analysed vacancies fall in, largest first.
     * A vacancy with no known occupation or industry is not in any group, and `known` says how many were placed.
     *
     * @param 'occupation'|'industry' $dimension
     * @param 'unit'|'submajor' $level occupation detail: 4-digit unit groups, or the broader 2-digit sub-major groups
     * @return array{groups: Collection<int, object{key:string, name:string, vacancies:int, reliable:bool}>, known:int, analysed:int}
     */
    public function groups(string $dimension, string $level = 'submajor'): array
    {
        [$key, $name] = $this->groupColumns($dimension);

        $rows = $this->grouped($this->analysedQuery(), $dimension, $level)
            ->selectRaw("{$key} as `key`, {$name} as name, count(distinct jobs.id) as vacancies")
            ->groupBy(DB::raw($key), DB::raw($name))->orderByDesc('vacancies')->orderBy('name')->get()
            ->map(function ($r) {
                $r->reliable = $r->vacancies >= self::MIN_SAMPLE;

                return $r;
            });

        return ['groups' => $rows, 'known' => (int) $rows->sum('vacancies'), 'analysed' => $this->analysedQuery()->count()];
    }

    /**
     * What the vacancies in one group ask for, beside what all analysed vacancies ask for, so a qualification that is
     * simply common everywhere is not mistaken for one that is special to the group.
     *
     * @return array{vacancies:int, reliable:bool, qualifications:Collection, levels:Collection}
     */
    public function qualificationsIn(string $dimension, string $key, string $level = 'submajor', int $limit = 12): array
    {
        $vacancies = fn () => $this->inGroup($this->analysedQuery(), $dimension, $level, $key);
        $size = $vacancies()->distinct()->count('jobs.id');
        $reliable = $size >= self::MIN_SAMPLE;
        $overall = $this->qualificationsAsked(1000)->keyBy('id');

        $rows = $this->asks($vacancies())->join('directory_qualifications as d', 'd.id', '=', 'jq.directory_qualification_id')
            ->groupBy('d.id', 'd.name', 'd.kind')
            ->selectRaw("d.id, d.name, d.kind, count(distinct jobs.id) as vacancies, count(distinct case when jq.requirement_level = 'required' then jobs.id end) as required")
            ->orderByDesc('vacancies')->orderBy('d.name')->limit($limit)->get()
            ->map(function ($r) use ($size, $reliable, $overall) {
                $r = $this->withShare($r, max(1, $size));
                $r->reliable = $r->reliable && $reliable;
                $r->share = $r->reliable ? $r->share : null;
                $r->overall_share = $overall[$r->id]->share ?? null;

                return $r;
            });

        return ['vacancies' => $size, 'reliable' => $reliable, 'qualifications' => $rows, 'levels' => $reliable ? $this->levels($vacancies()) : collect()];
    }

    /** The key and name SQL of a group dimension. */
    private function groupColumns(string $dimension): array
    {
        return $dimension === 'industry' ? ['cast(ind.id as char)', 'ind.name'] : ['occ.code', 'occ.title'];
    }

    /** Joins the group tables onto a vacancy query. */
    private function grouped(Builder $query, string $dimension, string $level): Builder
    {
        if ($dimension === 'industry') {
            return $query->leftJoin('employers as emp', 'emp.id', '=', 'jobs.employer_id')
                ->join('industries as ind', 'ind.id', '=', DB::raw('coalesce(jobs.industry_id, emp.industry_id)'));
        }

        return $query->join('occupations as occ', 'occ.code', '=', $level === 'unit' ? 'jobs.occupation_code' : DB::raw('substr(jobs.occupation_code, 1, 2)'));
    }

    /** Only the vacancies in one group. */
    private function inGroup(Builder $query, string $dimension, string $level, string $key): Builder
    {
        return $this->grouped($query, $dimension, $level)->where($dimension === 'industry' ? 'ind.id' : 'occ.code', $key);
    }

    /** Analysed vacancies joined to the qualification items that count as "asked for". */
    private function asks(?Builder $vacancies = null): Builder
    {
        return ($vacancies ?? $this->analysedQuery())->join('job_qualifications as jq', 'jq.job_id', '=', 'jobs.id')
            ->where('jq.removed', false)->whereIn('jq.requirement_level', self::COUNTED);
    }

    private function withShare(object $row, int $denominator): object
    {
        $row->reliable = $row->vacancies >= self::MIN_SAMPLE;
        $row->share = $row->reliable ? round($row->vacancies / $denominator * 100) : null;

        return $row;
    }
}
