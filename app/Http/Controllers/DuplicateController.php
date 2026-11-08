<?php

namespace App\Http\Controllers;

use App\Duplicates\DuplicateDetector;
use App\Models\Job;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Pairs of ads that look like the same vacancy but were not certain enough to merge by themselves. */
class DuplicateController extends Controller
{
    public function index(DuplicateDetector $detector): View
    {
        $pairs = DB::table('job_duplicate_candidates')->where('outcome', 'possible')->orderByDesc('score')->orderBy('id')->limit(50)->get();
        $auto = DB::table('job_duplicate_candidates')->where('outcome', 'auto')->orderByDesc('decided_at')->limit(15)->get();

        $ids = $pairs->concat($auto)->flatMap(fn ($p) => [$p->job_a_id, $p->job_b_id])->unique()->all();
        $jobs = Job::with('rawPost.source')->whereIn('id', $ids)->get()->keyBy('id');

        return view('duplicates.index', [
            'pairs' => $pairs->map(function ($p) use ($jobs, $detector) {
                $p->breakdown = json_decode($p->breakdown ?? '[]', true);
                $a = $jobs[$p->job_a_id] ?? null;
                $b = $jobs[$p->job_b_id] ?? null;
                $p->suggested = $a && $b ? $detector->preferred($a, $b)->id : null;

                return $p;
            }),
            'auto' => $auto,
            'jobs' => $jobs,
        ]);
    }

    public function confirm(Request $request, int $pair, DuplicateDetector $detector): RedirectResponse
    {
        $row = $this->pair($pair);
        $data = $request->validate(['main' => ['required', 'in:'.$row->job_a_id.','.$row->job_b_id]]);

        $detector->confirm($row, (int) $data['main']);

        return redirect()->route('duplicates.index')->with('status', 'Marked as the same vacancy. The other ad now points to the main one and keeps its own source and link.');
    }

    public function reject(int $pair, DuplicateDetector $detector): RedirectResponse
    {
        $detector->reject($this->pair($pair));

        return redirect()->route('duplicates.index')->with('status', 'Marked as different vacancies. They will not be proposed again.');
    }

    private function pair(int $id): object
    {
        return DB::table('job_duplicate_candidates')->where('id', $id)->whereIn('outcome', ['possible', 'auto'])->first() ?? abort(404);
    }
}
