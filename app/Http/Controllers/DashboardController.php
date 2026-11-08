<?php

namespace App\Http\Controllers;

use App\Models\RawJobPost;
use App\Models\Source;
use App\Models\SourceRun;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $sources = Source::query();
        $totalSources = (clone $sources)->count();
        $approved = (clone $sources)->where('access_status', 'approved')->count();

        $runs7 = SourceRun::where('started_at', '>=', now()->subDays(7));
        $runCount = (clone $runs7)->count();
        $runOk = (clone $runs7)->whereIn('status', ['success', 'partial'])->count();

        // ads first seen and runs started per day for the last 14 days (this week and the one before, for comparison)
        $from = now()->subDays(13)->startOfDay();
        $adsByDay = RawJobPost::where('first_seen_at', '>=', $from)
            ->selectRaw('DATE(first_seen_at) as d, count(*) as c')->groupBy('d')->pluck('c', 'd');
        $runsByDay = SourceRun::where('started_at', '>=', $from)
            ->selectRaw('DATE(started_at) as d, count(*) as c')->groupBy('d')->pluck('c', 'd');
        $series = collect(range(13, 0))->map(function (int $ago) use ($adsByDay, $runsByDay) {
            $day = now()->subDays($ago)->startOfDay();
            $key = $day->format('Y-m-d');

            return [
                'label' => $day->format('D j M'),
                'ads' => (int) ($adsByDay[$key] ?? 0),
                'runs' => (int) ($runsByDay[$key] ?? 0),
            ];
        });
        $days = $series->slice(7)->values();
        $previous = $series->take(7);
        $trend = fn (string $k) => [
            'now' => $days->sum($k),
            'before' => $previous->sum($k),
            'spark' => $series->pluck($k)->all(),
        ];

        return view('dashboard', [
            'activeSources' => (clone $sources)->where('is_active', true)->count(),
            'adsTotal' => RawJobPost::count(),
            'adsToday' => RawJobPost::where('first_seen_at', '>=', now()->subDay())->count(),
            'failing' => (clone $sources)->where('consecutive_failures', '>', 0)->count(),
            'totalSources' => $totalSources,
            'approved' => $approved,
            'runCount' => $runCount,
            'runOk' => $runOk,
            'rawKept' => RawJobPost::whereNotNull('raw_content')->count(),
            'waiting' => RawJobPost::pending()->count(),
            'processFailed' => RawJobPost::where('processing_status', 'failed')->count(),
            // Raw text is cleared at the retention limit. Ads that still wait for processing then lose it unprocessed.
            'atRisk' => RawJobPost::pending()
                ->whereNotNull('raw_content')
                ->where('first_seen_at', '<', now()->subDays(max(1, (int) config('job_intel.raw_retention_days') - 30)))
                ->count(),
            'accessCounts' => Source::selectRaw('access_status, count(*) as c')->groupBy('access_status')->pluck('c', 'access_status'),
            'days' => $days,
            'adsTrend' => $trend('ads'),
            'runsTrend' => $trend('runs'),
            'maxAds' => max(1, $days->max('ads')),
            'recentRuns' => SourceRun::with('source')->latest('id')->limit(5)->get(),
            'topSources' => Source::withCount('rawPosts')->whereHas('rawPosts')->orderByDesc('raw_posts_count')->limit(3)->get(),
            'pending' => (clone $sources)->where('access_status', 'pending')->count(),
            'noCollector' => (clone $sources)->whereNull('collector')->where('access_status', '!=', 'restricted')->count(),
        ]);
    }
}
