<?php

namespace Tests\Unit\Http\Controllers;

use App\Http\Controllers\NewsController;
use App\Services\NewsServiceInterface;
use Carbon\Carbon;
use Illuminate\Support\Sleep;
use Mockery;
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

    // -------------------------------------------------------------------------
    // shouldLoadNews — free driver (always true)
    // -------------------------------------------------------------------------

    public function test_free_driver_should_load_at_hour_0(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 9, 0, 0, 0));
        $this->assertTrue($this->callShouldLoadNews(true));
    }

    public function test_free_driver_should_load_at_hour_6(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 9, 6, 0, 0));
        $this->assertTrue($this->callShouldLoadNews(true));
    }

    public function test_free_driver_should_load_at_hour_12(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 9, 12, 0, 0));
        $this->assertTrue($this->callShouldLoadNews(true));
    }

    public function test_free_driver_should_load_at_hour_18(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 9, 18, 0, 0));
        $this->assertTrue($this->callShouldLoadNews(true));
    }

    public function test_free_driver_should_load_at_hour_23(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 9, 23, 0, 0));
        $this->assertTrue($this->callShouldLoadNews(true));
    }

    // -------------------------------------------------------------------------
    // shouldLoadNews — newscatcher3 driver (evening window only)
    // -------------------------------------------------------------------------

    public function test_newscatcher3_should_load_at_18(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 9, 18, 0, 0)); // 18 % 3 == 0
        $this->assertTrue($this->callShouldLoadNews(false));
    }

    public function test_newscatcher3_should_load_at_19(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 9, 19, 0, 0)); // 19 % 3 == 1
        $this->assertTrue($this->callShouldLoadNews(false));
    }

    public function test_newscatcher3_should_not_load_at_20(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 9, 20, 0, 0)); // 20 % 3 == 2
        $this->assertFalse($this->callShouldLoadNews(false));
    }

    public function test_newscatcher3_should_load_at_21(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 9, 21, 0, 0)); // 21 % 3 == 0
        $this->assertTrue($this->callShouldLoadNews(false));
    }

    public function test_newscatcher3_should_not_load_at_08(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 9, 8, 0, 0));
        $this->assertFalse($this->callShouldLoadNews(false));
    }

    public function test_newscatcher3_should_not_load_at_16(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 9, 16, 0, 0));
        $this->assertFalse($this->callShouldLoadNews(false));
    }

    public function test_newscatcher3_should_not_load_at_23(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 9, 23, 0, 0));
        $this->assertFalse($this->callShouldLoadNews(false));
    }

    // -------------------------------------------------------------------------
    // autoSelectLanguage — free driver (hour % 2)
    // -------------------------------------------------------------------------

    public function test_free_driver_language_even_hour_0_returns_uk(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 9, 0, 0, 0));
        $this->assertSame('uk', $this->callAutoSelectLanguage(true));
    }

    public function test_free_driver_language_odd_hour_1_returns_en(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 9, 1, 0, 0));
        $this->assertSame('en', $this->callAutoSelectLanguage(true));
    }

    public function test_free_driver_language_even_hour_10_returns_uk(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 9, 10, 0, 0));
        $this->assertSame('uk', $this->callAutoSelectLanguage(true));
    }

    public function test_free_driver_language_odd_hour_15_returns_en(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 9, 15, 0, 0));
        $this->assertSame('en', $this->callAutoSelectLanguage(true));
    }

    public function test_free_driver_language_even_hour_22_returns_uk(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 9, 22, 0, 0));
        $this->assertSame('uk', $this->callAutoSelectLanguage(true));
    }

    public function test_free_driver_language_odd_hour_23_returns_en(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 9, 23, 0, 0));
        $this->assertSame('en', $this->callAutoSelectLanguage(true));
    }

    // -------------------------------------------------------------------------
    // autoSelectLanguage — newscatcher3 driver (hour % 3)
    // -------------------------------------------------------------------------

    public function test_newscatcher3_language_hour_18_returns_uk(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 9, 18, 0, 0)); // 18 % 3 == 0
        $this->assertSame('uk', $this->callAutoSelectLanguage(false));
    }

    public function test_newscatcher3_language_hour_19_returns_en(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 9, 19, 0, 0)); // 19 % 3 == 1
        $this->assertSame('en', $this->callAutoSelectLanguage(false));
    }

    public function test_newscatcher3_language_hour_20_returns_uk(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 9, 20, 0, 0)); // 20 % 3 == 2
        $this->assertSame('uk', $this->callAutoSelectLanguage(false));
    }

    public function test_newscatcher3_language_hour_21_returns_uk(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 9, 21, 0, 0)); // 21 % 3 == 0
        $this->assertSame('uk', $this->callAutoSelectLanguage(false));
    }
}
