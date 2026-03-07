<?php

namespace Tests\Unit\Providers;

use App\Services\News\FreeNewsService;
use App\Services\NewsCatcher3Service;
use App\Services\NewsServiceInterface;
use Tests\TestCase;

class AppServiceProviderTest extends TestCase
{
    public function test_resolves_free_news_service_when_driver_is_free(): void
    {
        config(['services.news.driver' => 'free']);

        $service = $this->app->make(NewsServiceInterface::class);

        $this->assertInstanceOf(FreeNewsService::class, $service);
    }

    public function test_resolves_newscatcher3_service_when_driver_is_newscatcher3(): void
    {
        config(['services.news.driver' => 'newscatcher3']);

        $service = $this->app->make(NewsServiceInterface::class);

        $this->assertInstanceOf(NewsCatcher3Service::class, $service);
    }

    public function test_resolves_newscatcher3_service_by_default(): void
    {
        config(['services.news.driver' => null]);

        $service = $this->app->make(NewsServiceInterface::class);

        $this->assertInstanceOf(NewsCatcher3Service::class, $service);
    }
}
