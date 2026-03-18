<?php

namespace App\Filament\Widgets;

use App\Enums\FlickrPhotoStatus;
use App\Models\FlickrPhoto;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FlickrStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected static ?string $pollingInterval = null;

    protected function getHeading(): ?string
    {
        return 'Flickr Photos';
    }

    protected function getStats(): array
    {
        $statusCounts = FlickrPhoto::selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $total = $statusCounts->sum();
        $todayCount = FlickrPhoto::whereDate('created_at', today())->count();

        $sc = fn (FlickrPhotoStatus $s) => $statusCounts->get($s->value, 0);

        $rejected = collect(FlickrPhotoStatus::rejectedLabels());
        $rejectedTotal = $rejected->sum(fn ($s) => $sc($s));
        $rejectedDesc = $rejected->map(fn ($s, $label) => "{$label}: {$sc($s)}")->join(' | ');

        return [
            Stat::make('Total', number_format($total))
                ->icon('heroicon-o-photo'),
            Stat::make('Today', $todayCount)
                ->icon('heroicon-o-plus-circle')
                ->color('info'),
            Stat::make('Created', $sc(FlickrPhotoStatus::CREATED))
                ->color('gray'),
            Stat::make('Pending Review', $sc(FlickrPhotoStatus::PENDING_REVIEW))
                ->color('warning'),
            Stat::make('Approved', $sc(FlickrPhotoStatus::APPROVED))
                ->color('primary'),
            Stat::make('Published', $sc(FlickrPhotoStatus::PUBLISHED))
                ->color('success'),
            Stat::make('Rejected', $rejectedTotal)
                ->description($rejectedDesc)
                ->color('danger'),
        ];
    }
}
