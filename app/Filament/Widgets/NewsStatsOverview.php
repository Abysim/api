<?php

namespace App\Filament\Widgets;

use App\Enums\NewsStatus;
use App\Models\News;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class NewsStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected static ?string $pollingInterval = null;

    protected function getHeading(): ?string
    {
        return 'News Articles';
    }

    protected function getStats(): array
    {
        $statusCounts = News::selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $total = $statusCounts->sum();
        $todayCount = News::whereDate('created_at', today())->count();

        $sc = fn (NewsStatus $s) => $statusCounts->get($s->value, 0);

        $rejected = collect(NewsStatus::rejectedLabels());
        $rejectedTotal = $rejected->sum(fn ($s) => $sc($s));
        $rejectedDesc = $rejected->map(fn ($s, $label) => "{$label}: {$sc($s)}")->join(' | ');

        return [
            Stat::make('Total', number_format($total))
                ->icon('heroicon-o-newspaper'),
            Stat::make('Today', $todayCount)
                ->icon('heroicon-o-plus-circle')
                ->color('info'),
            Stat::make('Created', $sc(NewsStatus::CREATED))
                ->color('gray'),
            Stat::make('Being Processed', $sc(NewsStatus::BEING_PROCESSED))
                ->color('info'),
            Stat::make('Pending Review', $sc(NewsStatus::PENDING_REVIEW))
                ->color('warning'),
            Stat::make('Approved', $sc(NewsStatus::APPROVED))
                ->color('primary'),
            Stat::make('Published', $sc(NewsStatus::PUBLISHED))
                ->color('success'),
            Stat::make('Failed', $sc(NewsStatus::FAILED))
                ->color('danger'),
            Stat::make('Rejected', $rejectedTotal)
                ->description($rejectedDesc)
                ->color('danger'),
        ];
    }
}
