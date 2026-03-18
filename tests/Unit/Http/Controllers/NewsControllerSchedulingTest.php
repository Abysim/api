<?php

namespace Tests\Unit\Http\Controllers;

use App\Http\Controllers\NewsController;
use App\Services\NewsServiceInterface;
use Carbon\Carbon;
use Illuminate\Support\Sleep;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class NewsControllerSchedulingTest extends TestCase
{
    private NewsServiceInterface $service;
    private NewsController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        $this->service = Mockery::mock(NewsServiceInterface::class);
        $this->controller = new NewsController($this->service);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    private function callShouldLoadNews(bool $isFreeDriver): bool
    {
        $method = new ReflectionMethod(NewsController::class, 'shouldLoadNews');

        return $method->invoke($this->controller, $isFreeDriver);
    }

    private function callAutoSelectLanguage(bool $isFreeDriver): string
    {
        $method = new ReflectionMethod(NewsController::class, 'autoSelectLanguage');

        return $method->invoke($this->controller, $isFreeDriver);
    }

    public static function freeDriverShouldLoadNewsProvider(): array
    {
        return [
            'hour 0'  => [0],
            'hour 6'  => [6],
            'hour 12' => [12],
            'hour 18' => [18],
            'hour 23' => [23],
        ];
    }

    #[DataProvider('freeDriverShouldLoadNewsProvider')]
    public function test_free_driver_should_load_news(int $hour): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 9, $hour, 0, 0));
        $this->assertTrue($this->callShouldLoadNews(true));
    }

    public static function newscatcher3ShouldLoadNewsProvider(): array
    {
        return [
            'hour 18 (18%3==0)' => [18, true],
            'hour 19 (19%3==1)' => [19, true],
            'hour 20 (20%3==2)' => [20, false],
            'hour 21 (21%3==0)' => [21, true],
            'hour 8'            => [8,  false],
            'hour 16'           => [16, false],
            'hour 23'           => [23, false],
        ];
    }

    #[DataProvider('newscatcher3ShouldLoadNewsProvider')]
    public function test_newscatcher3_should_load_news(int $hour, bool $expected): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 9, $hour, 0, 0));
        $this->assertSame($expected, $this->callShouldLoadNews(false));
    }

    public static function freeDriverAutoSelectLanguageProvider(): array
    {
        return [
            'hour 0'  => [0,  'uk'],
            'hour 1'  => [1,  'en'],
            'hour 10' => [10, 'uk'],
            'hour 15' => [15, 'en'],
            'hour 22' => [22, 'uk'],
            'hour 23' => [23, 'en'],
        ];
    }

    #[DataProvider('freeDriverAutoSelectLanguageProvider')]
    public function test_free_driver_auto_select_language(int $hour, string $expected): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 9, $hour, 0, 0));
        $this->assertSame($expected, $this->callAutoSelectLanguage(true));
    }

    public static function newscatcher3AutoSelectLanguageProvider(): array
    {
        return [
            'hour 18 (18%3==0)' => [18, 'uk'],
            'hour 19 (19%3==1)' => [19, 'en'],
            'hour 20 (20%3==2)' => [20, 'uk'],
            'hour 21 (21%3==0)' => [21, 'uk'],
        ];
    }

    #[DataProvider('newscatcher3AutoSelectLanguageProvider')]
    public function test_newscatcher3_auto_select_language(int $hour, string $expected): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 9, $hour, 0, 0));
        $this->assertSame($expected, $this->callAutoSelectLanguage(false));
    }
}
