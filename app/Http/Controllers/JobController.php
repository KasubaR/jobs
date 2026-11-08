<?php

namespace App\Http\Controllers;

use App\Duplicates\DuplicateDetector;
use App\Models\Job;
use App\Models\Source;
use App\Processing\JobStatusService;
use App\Standardization\DefaultTerms;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The clean dataset: every job with its standard values beside the original text, and the decisions a person can make. */
class JobController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(Job::STATUSES)],
            'source' => ['nullable', 'integer'],
            'review' => ['nullable', 'in:needs_review'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $jobs = Job::query()
            ->with('rawPost.source')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['review'] ?? null, fn ($q, $v) => $q->where('review_status', $v))
            ->when($filters['source'] ?? null, fn ($q, $v) => $q->whereIn('raw_job_post_id', DB::table('raw_job_posts')->where('source_id', $v)->select('id')))
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('title', 'like', '%'.addcslashes($v, '%_\\').'%')->orWhere('employer_text', 'like', '%'.addcslashes($v, '%_\\').'%')))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('jobs.index', [
            'jobs' => $jobs,
            'filters' => $filters,
            'sources' => Source::orderBy('name')->pluck('name', 'id'),
            'counts' => Job::query()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status'),
            'needsReview' => Job::where('review_status', 'needs_review')->count(),
            'places' => $this->placeNames($jobs->pluck('location_id')->filter()->unique()->all()),
        ]);
    }

    public function show(Job $job): View
    {
        $job->load('rawPost.source', 'duplicateOf.rawPost.source');

        $pairs = DB::table('job_duplicate_candidates')->where(fn ($q) => $q->where('job_a_id', $job->id)->orWhere('job_b_id', $job->id))->orderByDesc('score')->get();
        $otherIds = $pairs->map(fn ($p) => $p->job_a_id === $job->id ? $p->job_b_id : $p->job_a_id)->all();

        return view('jobs.show', [
            'job' => $job,
            'employer' => $job->employer_id ? DB::table('employers')->where('id', $job->employer_id)->value('name') : null,
            'place' => $this->placeNames([$job->location_id])[$job->location_id] ?? null,
            'copies' => Job::with('rawPost.source')->where('duplicate_of_job_id', $job->id)->get(),
            'pairs' => $pairs,
            'others' => Job::with('rawPost.source')->whereIn('id', $otherIds)->get()->keyBy('id'),
            'history' => DB::table('job_status_changes')->where('job_id', $job->id)->orderByDesc('id')->get(),
            'logs' => DB::table('extraction_logs')->where('job_id', $job->id)->orderByDesc('id')->limit(5)->get(),
            'waiting' => DB::table('unrecognized_values')->where('job_id', $job->id)->get(),
            'types' => DefaultTerms::EMPLOYMENT_TYPES,
        ]);
    }

    /** Publish or reject by hand. The reason is kept in the history. */
    public function decide(Request $request, Job $job, JobStatusService $status): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['published', 'rejected'])], 'reason' => ['nullable', 'string', 'max:255']]);

        if ($job->status === 'duplicate') {
            return back()->with('error', 'This job is marked as a copy of another. Say it is a different vacancy first, then decide.');
        }

        $status->decide($job, $data['status'], trim((string) ($data['reason'] ?? '')) ?: null);

        return back()->with('status', $data['status'] === 'published' ? 'Published.' : 'Rejected. It is kept, with your reason, and is not matched with other ads.');
    }

    /** "I have looked at this ad and what was read is right." Moves a thin ad out of "new". */
    public function approve(Job $job, JobStatusService $status): RedirectResponse
    {
        $job->update(['review_status' => 'reviewed']);
        $status->evaluate($job->refresh());

        return back()->with('status', 'Marked as reviewed.');
    }

    /** "This is not a copy." */
    public function separate(Job $job, DuplicateDetector $detector): RedirectResponse
    {
        $detector->separate($job);

        return back()->with('status', 'Done. It stands alone again and will not be matched with that ad again.');
    }

    /** @param list<int|null> $ids @return array<int, string> id => "Kitwe, Copperbelt" */
    private function placeNames(array $ids): array
    {
        $ids = array_filter($ids);
        if (! $ids) {
            return [];
        }

        $rows = DB::table('locations as l')->leftJoin('locations as p', 'p.id', '=', 'l.parent_id')->whereIn('l.id', $ids)->get(['l.id', 'l.name', 'p.name as province']);

        return $rows->mapWithKeys(fn ($r) => [$r->id => $r->province ? "{$r->name}, {$r->province}" : $r->name])->all();
    }
}
