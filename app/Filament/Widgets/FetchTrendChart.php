<?php

namespace App\Filament\Widgets;

use App\Models\DailyStat;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class FetchTrendChart extends ChartWidget
{
    protected static ?string $heading = 'Content Fetching Daily Trend';

    protected static ?int $sort = 6;

    protected static ?string $pollingInterval = null;

    protected static ?string $maxHeight = '300px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $days = collect(range(13, 0))->map(fn ($i) => now()->subDays($i)->format('Y-m-d'));
        $labels = $days->map(fn ($d) => Carbon::parse($d)->format('M d'));

        $stats = DailyStat::whereIn('date', $days)->get()->keyBy('date');

        return [
            'datasets' => [
                ['label' => 'Raw HTTP', 'data' => $days->map(fn ($d) => $stats[$d]->fetch_raw_http ?? 0)->values(), 'backgroundColor' => '#3b82f6'],
                ['label' => 'Jina Reader', 'data' => $days->map(fn ($d) => $stats[$d]->fetch_jina ?? 0)->values(), 'backgroundColor' => '#8b5cf6'],
                ['label' => 'Diffbot', 'data' => $days->map(fn ($d) => $stats[$d]->fetch_diffbot ?? 0)->values(), 'backgroundColor' => '#f97316'],
                ['label' => 'ScraperAPI', 'data' => $days->map(fn ($d) => $stats[$d]->fetch_scraperapi ?? 0)->values(), 'backgroundColor' => '#ef4444'],
            ],
            'labels' => $labels->values(),
        ];
    }
}
