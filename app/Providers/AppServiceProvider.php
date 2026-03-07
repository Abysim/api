<?php

namespace App\Providers;

use App\Services\News\FreeNewsService;
use App\Services\NewsCatcher3Service;
use App\Services\NewsServiceInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // NewsCatcherService (v2) is deprecated — kept for reference only.
        // Default driver is NewsCatcher3Service; set NEWS_DRIVER=free for FreeNewsService.
        $this->app->bind(NewsServiceInterface::class, function ($app) {
            return match (config('services.news.driver')) {
                'free' => $app->make(FreeNewsService::class),
                default => $app->make(NewsCatcher3Service::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
