<?php

namespace App\Filament\Widgets;

use App\Models\RawJobPost;
use App\Models\Source;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SourceHealthStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $failing = Source::query()->where('consecutive_failures', '>', 0)->count();
        $overdue = Source::query()
            ->where('is_active', true)
            ->whereNotNull('next_collection_at')
            ->where('next_collection_at', '<', now()->subHours(2))
            ->count();

        return [
            Stat::make('Active sources', Source::query()->where('is_active', true)->count())
                ->description(Source::query()->where('access_status', 'pending')->count().' awaiting an access decision')
                ->color('success'),
            Stat::make('Failing sources', $failing)
                ->description($overdue > 0 ? "{$overdue} overdue for collection" : 'Failed on their last run')
                ->color($failing > 0 ? 'danger' : 'gray'),
            Stat::make('Ads collected', RawJobPost::query()->count())
                ->description(RawJobPost::query()->where('first_seen_at', '>=', now()->subDay())->count().' in the last 24 hours')
                ->color('info'),
        ];
    }
}
