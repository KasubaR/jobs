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

        // ads first seen per day for the last 7 days
        $days = collect(range(6, 0))->map(function (int $ago) {
            $day = now()->subDays($ago)->startOfDay();

            return [
                'label' => $day->format('D j M'),
                'ads' => RawJobPost::whereBetween('first_seen_at', [$day, $day->copy()->endOfDay()])->count(),
                'runs' => SourceRun::whereBetween('started_at', [$day, $day->copy()->endOfDay()])->count(),
            ];
        });

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
            'days' => $days,
            'maxAds' => max(1, $days->max('ads')),
            'recentRuns' => SourceRun::with('source')->latest('id')->limit(5)->get(),
            'topSources' => Source::withCount('rawPosts')->whereHas('rawPosts')->orderByDesc('raw_posts_count')->limit(3)->get(),
            'pending' => (clone $sources)->where('access_status', 'pending')->count(),
            'noCollector' => (clone $sources)->whereNull('collector')->where('access_status', '!=', 'restricted')->count(),
        ]);
    }
}
