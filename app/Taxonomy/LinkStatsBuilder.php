<?php

namespace App\Taxonomy;

use Illuminate\Support\Facades\DB;

/**
 * Counts, from the vacancies, which occupations, skills and industries go with each directory qualification.
 *
 * One vacancy counts once (copies and rejected ads are left out, as on the Qualifications page). Only items that count
 * as "what employers ask for" are used: not removed, and required or preferred. Everything is rebuilt from the
 * current vacancies, so nothing stale survives and a person's removal of an item is respected.
 */
class LinkStatsBuilder
{
    /** Below this many vacancies a share is too thin to show as a finding. */
    public const MIN_SAMPLE = 5;

    private const COUNTED = ['required', 'preferred'];

    /** @return array<string, int> rows written per link type */
    public function rebuild(): array
    {
        $now = now();
        $written = ['occupation' => 0, 'skill' => 0, 'industry' => 0];

        DB::transaction(function () use ($now, &$written) {
            DB::table('qualification_link_stats')->delete();

            // one row per (vacancy, qualification): required if any of its items for it is required
            $asks = $this->vacancies()
                ->join('job_qualifications as jq', 'jq.job_id', '=', 'jobs.id')
                ->whereNotNull('jq.directory_qualification_id')
                ->where('jq.removed', false)->whereIn('jq.requirement_level', self::COUNTED)
                ->groupBy('jobs.id', 'jq.directory_qualification_id')
                ->selectRaw("jobs.id as job_id, jq.directory_qualification_id as q, max(case when jq.requirement_level = 'required' then 1 else 0 end) as required")
                ->get();

            $totals = [];
            $requiredBy = [];
            foreach ($asks as $a) {
                $totals[$a->q] = ($totals[$a->q] ?? 0) + 1;
                $requiredBy[$a->job_id][$a->q] = (int) $a->required;
            }
            if ($asks->isEmpty()) {
                return;
            }

            $jobIds = array_keys($requiredBy);
            $targets = ['occupation' => [], 'skill' => [], 'industry' => []];

            foreach (array_chunk($jobIds, 500) as $chunk) {
                foreach (DB::table('jobs')->whereIn('id', $chunk)->whereNotNull('occupation_code')->pluck('occupation_code', 'id') as $job => $code) {
                    $targets['occupation'][$job][] = (string) $code;
                }
                foreach (DB::table('job_skills')->whereIn('job_id', $chunk)->where('removed', false)->whereIn('requirement_level', self::COUNTED)->get(['job_id', 'skill_id']) as $row) {
                    $targets['skill'][$row->job_id][] = (string) $row->skill_id;
                }
                // the job's own industry, else its employer's
                foreach (DB::table('jobs')->leftJoin('employers', 'employers.id', '=', 'jobs.employer_id')->whereIn('jobs.id', $chunk)
                    ->selectRaw('jobs.id as id, coalesce(jobs.industry_id, employers.industry_id) as industry')->get() as $row) {
                    if ($row->industry !== null) {
                        $targets['industry'][$row->id][] = (string) $row->industry;
                    }
                }
            }

            $counts = [];
            foreach ($requiredBy as $job => $qualifications) {
                foreach ($targets as $type => $byJob) {
                    foreach (array_unique($byJob[$job] ?? []) as $target) {
                        foreach ($qualifications as $q => $required) {
                            $c = &$counts[$type][$q][$target];
                            $c = ($c ?? ['jobs' => 0, 'required' => 0]);
                            $c['jobs']++;
                            $c['required'] += $required;
                            unset($c);
                        }
                    }
                }
            }

            $rows = [];
            foreach ($counts as $type => $byQualification) {
                foreach ($byQualification as $q => $byTarget) {
                    foreach ($byTarget as $target => $c) {
                        $rows[] = ['directory_qualification_id' => $q, 'link_type' => $type, 'target' => (string) $target, 'jobs' => $c['jobs'],
                            'required_jobs' => $c['required'], 'qualification_jobs' => $totals[$q], 'computed_at' => $now];
                        $written[$type]++;
                    }
                }
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('qualification_link_stats')->insert($chunk);
            }
        });

        return $written;
    }

    /** Vacancies counted once: copies and rejected ads left out. */
    private function vacancies(): \Illuminate\Database\Query\Builder
    {
        return DB::table('jobs')->whereNotIn('jobs.status', ['duplicate', 'rejected']);
    }
}
