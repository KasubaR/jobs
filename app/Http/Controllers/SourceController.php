<?php

namespace App\Http\Controllers;

use App\Collectors\CollectorRegistry;
use App\Http\Requests\SourceRequest;
use App\Models\Source;
use App\Models\SourceRun;
use App\Services\SourceManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SourceController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'access' => ['nullable', 'in:pending,approved,restricted'],
            'active' => ['nullable', 'in:0,1'],
            'failing' => ['nullable', 'in:1'],
        ]);

        $sources = Source::query()
            ->addSelect(['last_run_status' => SourceRun::select('status')->whereColumn('source_id', 'sources.id')->latest('id')->limit(1)])
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where('name', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->when($filters['access'] ?? null, fn ($q, $v) => $q->where('access_status', $v))
            ->when(isset($filters['active']), fn ($q) => $q->where('is_active', $filters['active'] === '1'))
            ->when($filters['failing'] ?? null, fn ($q) => $q->where('consecutive_failures', '>', 0))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('sources.index', ['sources' => $sources, 'filters' => $filters]);
    }

    public function create(): View
    {
        return view('sources.form', ['source' => new Source(['collection_method' => 'pending_review', 'collection_frequency' => 'daily', 'min_interval_seconds' => 1, 'retain_raw' => true]), 'collectors' => $this->collectors()]);
    }

    public function store(SourceRequest $request): RedirectResponse
    {
        // A new source always starts pending and switched off, whatever the form sends.
        $source = Source::create($request->sourceData() + ['access_status' => 'pending', 'is_active' => false]);

        return redirect()->route('sources.show', $source)->with('status', 'Source added. It is pending and switched off until you decide access.');
    }

    public function show(Request $request, Source $source, SourceManager $manager): View
    {
        $tab = in_array($request->query('tab'), ['runs', 'errors', 'access'], true) ? $request->query('tab') : 'overview';

        $data = ['source' => $source, 'tab' => $tab, 'problems' => $manager->activationProblems($source), 'adsCount' => $source->rawPosts()->count(), 'waitingCount' => $source->rawPosts()->pending()->count()];

        if ($tab === 'runs') {
            $data['runs'] = $source->runs()->latest('id')->paginate(15)->withQueryString();
        } elseif ($tab === 'errors') {
            $data['runs'] = $source->runs()->whereIn('status', ['failed', 'partial'])->latest('id')->paginate(15)->withQueryString();
        } elseif ($tab === 'access') {
            $data['logs'] = $source->accessLogs()->with('user')->latest('id')->paginate(15)->withQueryString();
        }

        return view('sources.show', $data);
    }

    public function edit(Source $source): View
    {
        return view('sources.form', ['source' => $source, 'collectors' => $this->collectors()]);
    }

    public function update(SourceRequest $request, Source $source): RedirectResponse
    {
        $source->update($request->sourceData());

        return redirect()->route('sources.show', $source)->with('status', 'Source saved.');
    }

    public function destroy(Source $source, SourceManager $manager): RedirectResponse
    {
        if (! $manager->canBeDeleted($source)) {
            return back()->with('error', 'This source has stored ads and cannot be deleted. Deactivate it instead.');
        }

        $source->delete();

        return redirect()->route('sources.index')->with('status', 'Source deleted.');
    }

    private function collectors(): array
    {
        return array_keys(app(CollectorRegistry::class)->all());
    }
}
