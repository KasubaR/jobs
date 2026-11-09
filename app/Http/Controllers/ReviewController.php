<?php

namespace App\Http\Controllers;

use App\Processing\RequirementApplier;
use App\Support\ChangeLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Qualification wording the reader could not place: a field the taxonomy does not know ("Diploma in Security Studies"),
 * or a sentence it would not guess at. One decision settles every vacancy that used the same wording, and a
 * mapping teaches the matcher by adding the wording as an alias of the field, so the next ad matches by itself.
 *
 * A person's decision is final: the rows are marked reviewed (or removed) and a re-read leaves them alone.
 */
class ReviewController extends Controller
{
    public function index(): View
    {
        $rows = $this->waiting()
            ->groupBy(DB::raw('lower(jq.candidate_text)'), 'jq.education_level')
            ->selectRaw('lower(jq.candidate_text) as wording, jq.education_level as level, min(jq.candidate_text) as sample, min(jq.evidence) as evidence, count(distinct jq.job_id) as jobs, min(jq.job_id) as job_id')
            ->orderByDesc('jobs')->orderBy('wording')->get();

        return view('review.index', [
            'rows' => $rows,
            'levels' => DB::table('qualification_levels')->orderBy('rank')->pluck('name', 'key'),
            'fields' => DB::table('fields_of_study as f')->leftJoin('fields_of_study as p', 'p.id', '=', 'f.parent_id')
                ->orderByRaw('coalesce(p.name, f.name), f.parent_id is not null, f.name')->get(['f.id', 'f.name', 'p.name as parent']),
            'decided' => DB::table('job_qualifications')->where('source', 'person')->orWhere('reviewed', true)->count(),
        ]);
    }

    public function store(Request $request, RequirementApplier $applier): RedirectResponse
    {
        $d = $request->validate([
            'wording' => ['required', 'string', 'max:255'],
            'level' => ['nullable', 'string', 'max:20'],
            'action' => ['required', Rule::in(['map', 'remove', 'keep'])],
            'field_id' => ['nullable', 'integer', Rule::exists('fields_of_study', 'id')],
        ]);

        $rows = $this->waiting()->whereRaw('lower(jq.candidate_text) = ?', [mb_strtolower($d['wording'])])
            ->when($d['level'] ?? null, fn ($q, $l) => $q->where('jq.education_level', $l), fn ($q) => $q->whereNull('jq.education_level'));

        // Only wording that is actually waiting can be decided, so nothing arbitrary is written into the taxonomy.
        $ids = (clone $rows)->pluck('jq.id');
        abort_if($ids->isEmpty(), 422, 'That wording is not waiting for a decision.');
        $jobIds = DB::table('job_qualifications')->whereIn('id', $ids)->pluck('job_id')->unique();

        DB::transaction(function () use ($d, $ids, $applier) {
            if ($d['action'] === 'remove') {
                $this->change($ids, ['removed' => true, 'reviewed' => true]);

                return;
            }

            if ($d['action'] === 'keep') {
                $this->change($ids, ['reviewed' => true]);

                return;
            }

            abort_if(empty($d['level']) || empty($d['field_id']), 422, 'Choose a field to map this wording to.');
            $entry = $applier->levelFieldEntry($d['level'], (int) $d['field_id']);
            abort_if($entry === null, 422, 'That level is not known.');

            $this->teachField((int) $d['field_id'], $d['wording']);

            $this->change($ids, [
                'field_id' => (int) $d['field_id'], 'candidate_text' => null, 'directory_qualification_id' => $entry,
                'source' => 'person', 'reviewed' => true, 'confidence' => 100,
            ]);
        });

        // a job with nothing left to look at is no longer waiting for review
        foreach ($jobIds as $jobId) {
            if (! DB::table('job_qualifications')->where('job_id', $jobId)->where('removed', false)->where('reviewed', false)->whereNotNull('candidate_text')->exists()) {
                DB::table('jobs')->where('id', $jobId)->where('analysis_status', 'needs_review')->update(['analysis_status' => 'done']);
            }
        }

        $n = $jobIds->count();

        return redirect()->route('review.index')->with('status', "Saved for {$n} ".($n === 1 ? 'vacancy' : 'vacancies').'. '.($d['action'] === 'map' ? 'The same wording is understood from now on.' : ''));
    }

    /** Updates the rows and writes down what changed in each. */
    private function change($ids, array $after): void
    {
        foreach (DB::table('job_qualifications')->whereIn('id', $ids)->get() as $row) {
            ChangeLog::diff('job_qualifications', (int) $row->id, $row, $after, 'review_screen');
        }

        DB::table('job_qualifications')->whereIn('id', $ids)->update($after);
    }

    /** Qualification items nobody has decided on that carry wording we could not place, on vacancies that count. */
    private function waiting()
    {
        return DB::table('job_qualifications as jq')->join('jobs', 'jobs.id', '=', 'jq.job_id')
            ->whereNotNull('jq.candidate_text')->where('jq.removed', false)->where('jq.reviewed', false)->where('jq.source', '!=', 'person')
            ->whereNotIn('jobs.status', ['duplicate', 'rejected']);
    }

    /** The wording becomes an alias of the field, once, so the next ad matches without a person. */
    private function teachField(int $fieldId, string $wording): void
    {
        $aliases = json_decode((string) DB::table('fields_of_study')->where('id', $fieldId)->value('aliases'), true) ?: [];
        $known = array_map('mb_strtolower', $aliases);

        if (! in_array(mb_strtolower($wording), $known, true)) {
            $old = $aliases;
            $aliases[] = $wording;
            DB::table('fields_of_study')->where('id', $fieldId)->update(['aliases' => json_encode($aliases, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]);
            ChangeLog::record('fields_of_study', $fieldId, 'aliases', $old, $aliases, 'review_screen');
            app(\App\Taxonomy\TaxonomyIndex::class)->flush();   // a worker that is already running must see the new alias
        }
    }
}
