<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * A read-only baseline of what the system holds and how well qualifications are being read. Changes nothing.
 * Run it on the live server: `php artisan audit:baseline`.
 */
class AuditBaselineCommand extends Command
{
    protected $signature = 'audit:baseline {--list=60 : how many vacancies with no recognised qualification to list}';

    protected $description = 'Report what is stored, how qualifications are read, and which vacancies have none (changes nothing)';

    /** Words that suggest an ad states an academic or professional requirement. */
    private const HINT = '/\b(degree|diploma|bachelor|b\.?sc|b\.?com|b\.?a\b|master|mba|phd|certificate|grade\s*12|o[- ]?level|a[- ]?level|qualification|acca|cima|zica|cpa|licen[cs]e|trade test|apprentice|graduate)\b/iu';

    /** Fewer characters than this of ad text is too little to hold requirements. */
    private const THIN = 300;

    public function handle(): int
    {
        $this->line('BASELINE ('.now()->toDateTimeString().', '.DB::getDriverName().')');

        $counted = fn () => DB::table('jobs')->whereNotIn('status', ['duplicate', 'rejected']);
        $total = $counted()->count();

        $this->section('1. What is stored');
        $this->kv('Stored ads (raw_job_posts)', DB::table('raw_job_posts')->count());
        $this->kv('  with ad text kept', DB::table('raw_job_posts')->whereNotNull('raw_content')->count());
        $this->kv('  text cleared by retention', DB::table('raw_job_posts')->whereNotNull('raw_purged_at')->whereNotNull('first_seen_at')->count());
        $this->kv('  from sources that keep no text (retain_raw off)', DB::table('raw_job_posts')->join('sources', 'sources.id', '=', 'raw_job_posts.source_id')->where('sources.retain_raw', false)->count());
        $this->kv('Clean vacancy records (jobs)', DB::table('jobs')->count());
        $this->kv('  by status', $this->map(DB::table('jobs')->groupBy('status')->selectRaw('status k, count(*) n')->pluck('n', 'k')));
        $this->kv('  by reading state', $this->map(DB::table('jobs')->groupBy('analysis_status')->selectRaw('analysis_status k, count(*) n')->pluck('n', 'k')));
        $this->kv('  by source', $this->map(DB::table('jobs')->join('raw_job_posts as r', 'r.id', '=', 'jobs.raw_job_post_id')->join('sources as s', 's.id', '=', 'r.source_id')->groupBy('s.name')->selectRaw('s.name k, count(*) n')->pluck('n', 'k')));
        $this->kv('Counted vacancies (copies and rejected left out)', $total);
        $this->kv('Reading rules version in use / stored', ['code' => \App\Processing\ExtractionProcessor::VERSION, 'stored' => $this->map(DB::table('jobs')->groupBy('extraction_version')->selectRaw('extraction_version k, count(*) n')->pluck('n', 'k'))]);

        $this->section('2. Duplicates');
        $this->kv('Marked as copies', DB::table('jobs')->where('status', 'duplicate')->count());
        $this->kv('Candidate pairs by outcome', $this->map(DB::table('job_duplicate_candidates')->groupBy('outcome')->selectRaw('outcome k, count(*) n')->pluck('n', 'k')));

        $this->section('3. Incomplete records (counted vacancies)');
        $this->kv('No employer', $counted()->whereNull('employer_text')->count());
        $this->kv('No place', $counted()->whereNull('location_text')->count());
        $this->kv('No closing date', $counted()->whereNull('closing_date')->count());
        $this->kv('Low confidence (under 50)', $counted()->where('confidence', '<', 50)->count());
        $this->kv('Waiting for a person (needs review)', $counted()->where('review_status', 'needs_review')->count());

        // ---- text and qualification coverage, per vacancy ----
        $rows = $counted()->join('raw_job_posts as r', 'r.id', '=', 'jobs.raw_job_post_id')
            ->leftJoin('sources as s', 's.id', '=', 'r.source_id')
            ->get(['jobs.id', 'jobs.title', 'jobs.employer_text', 'jobs.status', 'jobs.analysis_status', 'jobs.qualification_level', 'jobs.qualification_text', 'r.raw_content', 's.name as source']);

        $counts = DB::table('job_qualifications')->where('removed', false)->groupBy('job_id')
            ->selectRaw("job_id, sum(case when requirement_level in ('required','preferred') then 1 else 0 end) as counted, sum(case when requirement_level = 'advantage' then 1 else 0 end) as adv, sum(case when candidate_text is not null and directory_qualification_id is null and education_level is null then 1 else 0 end) as cand")
            ->get()->keyBy('job_id');

        $len = fn ($r) => $r->raw_content === null ? 0 : mb_strlen(trim(strip_tags($r->raw_content)));
        $both = $onlyOld = $onlyWhole = $neither = 0;
        $disagree = $agree = 0;
        $reasons = ['no ad text kept' => 0, 'ad text too short' => 0, 'ad names a qualification the reader missed' => 0, 'ad appears to state none' => 0, 'not analysed yet' => 0];
        $none = [];
        $lengths = [];

        foreach ($rows as $r) {
            $n = $len($r);
            $r->raw_content !== null && $lengths[] = $n;
            $c = $counts[$r->id] ?? null;
            $old = $r->qualification_level !== null;
            $whole = $c && $c->counted > 0;

            match (true) {
                $old && $whole => $both++, $old => $onlyOld++, $whole => $onlyWhole++, default => $neither++,
            };

            if ($old && $whole) {
                $levels = DB::table('job_qualifications')->where('job_id', $r->id)->where('removed', false)->whereIn('requirement_level', ['required', 'preferred'])->whereNotNull('education_level')->pluck('education_level')->all();
                $levels === [] ? null : (in_array($this->level($r->qualification_level), $levels, true) ? $agree++ : $disagree++);
            }

            if (! $old && ! $whole) {
                $reason = match (true) {
                    ! in_array($r->analysis_status, ['done', 'needs_review'], true) && $r->raw_content !== null => 'not analysed yet',
                    $r->raw_content === null => 'no ad text kept',
                    $n < self::THIN => 'ad text too short',
                    preg_match(self::HINT, strip_tags($r->raw_content)) === 1 => 'ad names a qualification the reader missed',
                    default => 'ad appears to state none',
                };
                $reasons[$reason]++;
                $none[] = [$r->id, mb_strimwidth($r->title, 0, 38, '…'), mb_strimwidth((string) $r->employer_text, 0, 22, '…'), $r->source, $n, $r->analysis_status, $reason];
            }
        }

        $this->section('4. How qualifications are read today');
        $this->line('  Two readers exist, and two screens use different ones:');
        $this->line('  A. Labelled-line reader (Phase 6): jobs.qualification_* from published data or a "Qualification:" line. Used by the Qualifications page panels.');
        $this->line('  B. Whole-ad reader (Phase 7): job_qualifications rows with a requirement level. Used by the Insights page (required/preferred only, analysed vacancies only).');
        $this->kv('Counted vacancies', $total);
        $this->kv('  read by both A and B', $both);
        $this->kv('  read by A only', $onlyOld);
        $this->kv('  read by B only', $onlyWhole);
        $this->kv('  read by neither (no recognised qualification)', $neither);
        $this->kv('  A and B agree on the education level / disagree', "{$agree} / {$disagree}");
        $this->kv('Insights counts a vacancy as stating a qualification', $both + $onlyWhole.' of '.$total.' ('.($total ? round(($both + $onlyWhole) / $total * 100) : 0).'%)');
        $this->kv('Qualifications page counts a vacancy as stating one', $both + $onlyOld.' of '.$total.' ('.($total ? round(($both + $onlyOld) / $total * 100) : 0).'%)');
        $this->kv('Rows in job_qualifications / job_skills / job_experiences', [DB::table('job_qualifications')->count(), DB::table('job_skills')->count(), DB::table('job_experiences')->count()]);
        $this->kv('Items still unplaced (candidate wording)', DB::table('job_qualifications')->whereNotNull('candidate_text')->whereNull('directory_qualification_id')->whereNull('education_level')->where('removed', false)->count());

        $this->section('5. Vacancies with no recognised qualification: '.$neither);
        foreach ($reasons as $why => $n) {
            $this->kv("  {$why}", $n);
        }
        if ($lengths) {
            sort($lengths);
            $this->kv('Ad text length, characters (min / median / max) over ads with text', [$lengths[0], $lengths[intdiv(count($lengths), 2)], end($lengths)]);
            $this->kv('Ads with text under '.self::THIN.' characters', count(array_filter($lengths, fn ($l) => $l < self::THIN)));
        }
        $this->newLine();
        $this->table(['id', 'title', 'employer', 'source', 'chars', 'reading', 'probable reason'], array_slice($none, 0, max(0, (int) $this->option('list'))));
        count($none) > (int) $this->option('list') && $this->line('  … '.(count($none) - (int) $this->option('list')).' more; use --list=500');

        $this->section('6. What is not measured here');
        $this->line('  Accuracy: there is no hand-checked set of ads yet, so precision and recall cannot be stated. Agreement between readers A and B above is a');
        $this->line('  consistency check, not accuracy. "Probable reason" is a keyword guess; read the listed ads to confirm it.');

        return self::SUCCESS;
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->info($title);
    }

    private function kv(string $label, mixed $value): void
    {
        $this->line(sprintf('  %-62s %s', $label, is_scalar($value) || $value === null ? $value : json_encode($value)));
    }

    private function map($pluck): string
    {
        return $pluck->map(fn ($n, $k) => ($k === '' || $k === null ? '(none)' : $k).' '.$n)->implode(', ') ?: '-';
    }

    /** The key education_level uses for a Phase 6 level. */
    private function level(string $legacy): string
    {
        return ['degree' => 'bachelor', 'masters' => 'masters'][$legacy] ?? $legacy;
    }
}
