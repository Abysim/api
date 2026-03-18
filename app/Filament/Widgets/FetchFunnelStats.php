<?php

namespace App\Filament\Widgets;

use App\Models\DailyStat;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FetchFunnelStats extends StatsOverviewWidget
{
    protected static ?int $sort = 5;

    protected static ?string $pollingInterval = null;

    protected function getHeading(): ?string
    {
        return 'Content Fetching Today';
    }

    protected function getStats(): array
    {
        $stat = DailyStat::where('date', now()->format('Y-m-d'))->first();

        return [
            Stat::make('Raw HTTP', $stat?->fetch_raw_http ?? 0)
                ->icon('heroicon-o-globe-alt'),
            Stat::make('Jina Reader', $stat?->fetch_jina ?? 0)
                ->icon('heroicon-o-document-text'),
            Stat::make('Diffbot', $stat?->fetch_diffbot ?? 0)
                ->icon('heroicon-o-cloud'),
            Stat::make('ScraperAPI', $stat?->fetch_scraperapi ?? 0)
                ->icon('heroicon-o-server'),
        ];
    }
}
