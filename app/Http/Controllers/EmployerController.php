<?php

namespace App\Http\Controllers;

use App\Taxonomy\TaxonomyIndex;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Which industry an employer is in. Said once here, it applies to every vacancy of that employer, and it is what the
 * industry figures on a qualification profile are counted from.
 *
 * A suggestion from the employer's name (for example "... Mining Ltd") is only ever pre-selected for a person to
 * confirm; nothing is assigned without a decision.
 */
class EmployerController extends Controller
{
    public function index(Request $request, TaxonomyIndex $taxonomy): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'show' => ['nullable', 'in:all,unassigned'],
        ]);
        $term = trim((string) ($filters['q'] ?? ''));
        $show = $filters['show'] ?? 'unassigned';

        $employers = DB::table('employers')
            ->leftJoin('jobs', fn ($j) => $j->on('jobs.employer_id', '=', 'employers.id')->whereNotIn('jobs.status', ['duplicate', 'rejected']))
            ->when($term !== '', fn ($q) => $q->where('employers.name', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%'))
            ->when($show === 'unassigned', fn ($q) => $q->whereNull('employers.industry_id'))
            ->groupBy('employers.id', 'employers.name', 'employers.industry_id')
            ->selectRaw('employers.id, employers.name, employers.industry_id, count(jobs.id) as vacancies')
            ->orderByDesc('vacancies')->orderBy('employers.name')->paginate(40)->withQueryString();

        $suggestions = [];
        foreach ($employers as $employer) {
            if ($employer->industry_id === null) {
                $suggestions[$employer->id] = $taxonomy->mentions('industry', $employer->name)[0]->id ?? null;
            }
        }

        return view('employers.index', [
            'employers' => $employers,
            'industries' => DB::table('industries')->orderBy('code')->pluck('name', 'id'),
            'suggestions' => $suggestions,
            'filters' => ['q' => $term, 'show' => $show],
            'assigned' => DB::table('employers')->whereNotNull('industry_id')->count(),
            'total' => DB::table('employers')->count(),
        ]);
    }

    public function update(Request $request, int $employer): RedirectResponse
    {
        abort_unless(DB::table('employers')->where('id', $employer)->exists(), 404);

        $d = $request->validate(['industry_id' => ['nullable', 'integer', Rule::exists('industries', 'id')]]);

        DB::table('employers')->where('id', $employer)->update(['industry_id' => $d['industry_id'] ?? null, 'updated_at' => now()]);

        return back()->with('status', ($d['industry_id'] ?? null) ? 'Saved. It applies to all of this employer\'s vacancies and is counted in the next statistics rebuild.' : 'The industry was cleared.');
    }
}
