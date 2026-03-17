<?php

namespace App\Filament\Widgets;

use App\Enums\FlickrPhotoStatus;
use App\Models\FlickrPhoto;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class FlickrTrendChart extends ChartWidget
{
    protected static ?string $heading = 'Flickr Photos Daily Trend';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 1;

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

        $autoValues = collect([
            FlickrPhotoStatus::REJECTED_BY_TAG, FlickrPhotoStatus::REJECTED_BY_CLASSIFICATION,
        ])->map->value;
        $manualValues = collect([
            FlickrPhotoStatus::REJECTED_MANUALLY, FlickrPhotoStatus::REMOVED_BY_AUTHOR,
        ])->map->value;

        // Single query: new + auto-rejected + manual-rejected (all use created_at).
        // Alias as 'day' (not 'date') to prevent Eloquent auto-casting to Carbon.
        $byCreated = FlickrPhoto::selectRaw(
            'DATE(created_at) as day, count(*) as total,'
            . ' SUM(status IN (' . $autoValues->join(',') . ')) as auto_rejected,'
            . ' SUM(status IN (' . $manualValues->join(',') . ')) as manual_rejected'
        )
            ->where('created_at', '>=', now()->subDays(14))
            ->groupByRaw('DATE(created_at)')
            ->get()->keyBy('day');

        $publishedCounts = FlickrPhoto::selectRaw('DATE(published_at) as day, count(*) as count')
            ->where('published_at', '>=', now()->subDays(14))
            ->whereNotNull('published_at')
            ->groupByRaw('DATE(published_at)')->pluck('count', 'day');

        return [
            'datasets' => [
                ['label' => 'New', 'data' => $days->map(fn ($d) => (int) ($byCreated[$d]->total ?? 0))->values(), 'backgroundColor' => '#3b82f6'],
                ['label' => 'Published', 'data' => $days->map(fn ($d) => $publishedCounts[$d] ?? 0)->values(), 'backgroundColor' => '#22c55e'],
                ['label' => 'Auto Rejected', 'data' => $days->map(fn ($d) => (int) ($byCreated[$d]->auto_rejected ?? 0))->values(), 'backgroundColor' => '#f97316'],
                ['label' => 'Manual Rejected', 'data' => $days->map(fn ($d) => (int) ($byCreated[$d]->manual_rejected ?? 0))->values(), 'backgroundColor' => '#ef4444'],
            ],
            'labels' => $labels->values(),
        ];
    }
}
