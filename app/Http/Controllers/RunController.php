<?php

namespace App\Http\Controllers;

use App\Models\Source;
use App\Models\SourceRun;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RunController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:success,partial,failed,running'],
            'source' => ['nullable', 'integer'],
        ]);

        $runs = SourceRun::query()
            ->with('source')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['source'] ?? null, fn ($q, $v) => $q->where('source_id', $v))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('runs.index', ['runs' => $runs, 'filters' => $filters, 'sources' => Source::orderBy('name')->pluck('name', 'id')]);
    }

    public function show(SourceRun $run): View
    {
        return view('runs.show', ['run' => $run->load('source'), 'problems' => $run->errors()->orderBy('id')->get()]);
    }
}
