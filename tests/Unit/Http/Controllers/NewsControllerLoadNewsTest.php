<?php

namespace Tests\Unit\Http\Controllers;

use App\Http\Controllers\NewsController;
use App\Services\NewsServiceInterface;
use Illuminate\Support\Sleep;
use Mockery;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class NewsControllerLoadNewsTest extends TestCase
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
        Mockery::close();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // SEPARATE species: each with exclusions gets its own query
    // -------------------------------------------------------------------------

    public function test_separate_species_each_get_individual_query(): void
    {
        $this->setSpecies('en', [
            'lion' => ['words' => ['lion', 'lions'], 'exclude' => ['horoscope'], 'excludeCase' => []],
            'tiger' => ['words' => ['tiger', 'tigers'], 'exclude' => ['football'], 'excludeCase' => []],
        ]);

        $this->service->shouldReceive('generateSearchQuery')
            ->with(['lion', 'lions'], ['horoscope'])
            ->once()
            ->andReturn('(lion OR lions) !horoscope');

        $this->service->shouldReceive('generateSearchQuery')
            ->with(['tiger', 'tigers'], ['football'])
            ->once()
            ->andReturn('(tiger OR tigers) !football');

        $this->service->shouldReceive('getNews')
            ->with('(lion OR lions) !horoscope', 'en')
            ->once()
            ->andReturn([]);

        $this->service->shouldReceive('getNews')
            ->with('(tiger OR tigers) !football', 'en')
            ->once()
            ->andReturn([]);

        $this->callLoadNews('en');

        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
    }

    // -------------------------------------------------------------------------
    // BATCH species: species without exclusions are grouped together
    // -------------------------------------------------------------------------

    public function test_batch_species_without_exclusions_grouped_into_single_query(): void
    {
        $this->setSpecies('en', [
            'leopard' => ['words' => ['leopard'], 'exclude' => [], 'excludeCase' => []],
            'serval' => ['words' => ['serval'], 'exclude' => [], 'excludeCase' => []],
            'caracal' => ['words' => ['caracal'], 'exclude' => [], 'excludeCase' => []],
        ]);

        // generateSearchQuery is called for each candidate check + final fire
        $this->service->shouldReceive('generateSearchQuery')
            ->andReturnUsing(fn(array $words, array $exclude) => '(' . implode(' OR ', $words) . ')');

        $this->service->shouldReceive('getSearchQueryLimit')->andReturn(400);

        // Only ONE getNews call for all 3 species batched together
        $this->service->shouldReceive('getNews')
            ->once()
            ->andReturn([]);

        $this->callLoadNews('en');

        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
    }

    // -------------------------------------------------------------------------
    // BATCH overflow: splits when query exceeds limit
    // -------------------------------------------------------------------------

    public function test_batch_splits_when_query_exceeds_limit(): void
    {
        $this->setSpecies('en', [
            'species_a' => ['words' => ['word_a'], 'exclude' => [], 'excludeCase' => []],
            'species_b' => ['words' => ['word_b'], 'exclude' => [], 'excludeCase' => []],
        ]);

        // Limit of 10 means '(word_a OR word_b)' (18 chars) overflows
        $this->service->shouldReceive('getSearchQueryLimit')->andReturn(10);

        $this->service->shouldReceive('generateSearchQuery')
            ->andReturnUsing(fn(array $words, array $exclude) => '(' . implode(' OR ', $words) . ')');

        // Two getNews calls: species_a alone, then species_b alone
        $this->service->shouldReceive('getNews')->twice()->andReturn([]);

        $this->callLoadNews('en');

        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
    }

    // -------------------------------------------------------------------------
    // Hybrid: SEPARATE + BATCH combined
    // -------------------------------------------------------------------------

    public function test_hybrid_separate_and_batch_species_processed(): void
    {
        $this->setSpecies('en', [
            'lion' => ['words' => ['lion'], 'exclude' => ['horoscope'], 'excludeCase' => []],
            'leopard' => ['words' => ['leopard'], 'exclude' => [], 'excludeCase' => []],
            'serval' => ['words' => ['serval'], 'exclude' => [], 'excludeCase' => []],
        ]);

        $this->service->shouldReceive('generateSearchQuery')
            ->andReturnUsing(function (array $words, array $exclude) {
                $q = '(' . implode(' OR ', $words) . ')';
                foreach ($exclude as $e) {
                    $q .= ' !' . $e;
                }
                return $q;
            });

        $this->service->shouldReceive('getSearchQueryLimit')->andReturn(400);

        // Two getNews calls: 1 separate (lion) + 1 batch (leopard+serval)
        $this->service->shouldReceive('getNews')->twice()->andReturn([]);

        $this->callLoadNews('en');

        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
    }

    // -------------------------------------------------------------------------
    // Circuit breaker: stops after 3 consecutive empty results
    // -------------------------------------------------------------------------

    public function test_circuit_breaker_stops_after_three_consecutive_empty_results(): void
    {
        $this->setSpecies('en', [
            'sp1' => ['words' => ['sp1'], 'exclude' => ['ex1'], 'excludeCase' => []],
            'sp2' => ['words' => ['sp2'], 'exclude' => ['ex2'], 'excludeCase' => []],
            'sp3' => ['words' => ['sp3'], 'exclude' => ['ex3'], 'excludeCase' => []],
            'sp4' => ['words' => ['sp4'], 'exclude' => ['ex4'], 'excludeCase' => []],
        ]);

        $this->service->shouldReceive('generateSearchQuery')->andReturn('query');

        // Only 3 calls should happen — 4th species skipped by circuit breaker
        $this->service->shouldReceive('getNews')->times(3)->andReturn([]);

        $this->callLoadNews('en');

        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
    }

    // -------------------------------------------------------------------------
    // Circuit breaker resets on success
    // -------------------------------------------------------------------------

    public function test_circuit_breaker_resets_on_success(): void
    {
        $this->setSpecies('en', [
            'sp1' => ['words' => ['sp1'], 'exclude' => ['ex1'], 'excludeCase' => []],
            'sp2' => ['words' => ['sp2'], 'exclude' => ['ex2'], 'excludeCase' => []],
            'sp3' => ['words' => ['sp3'], 'exclude' => ['ex3'], 'excludeCase' => []],
            'sp4' => ['words' => ['sp4'], 'exclude' => ['ex4'], 'excludeCase' => []],
            'sp5' => ['words' => ['sp5'], 'exclude' => ['ex5'], 'excludeCase' => []],
        ]);

        $this->service->shouldReceive('generateSearchQuery')->andReturn('query');

        // 2 empty, 1 success (resets counter), 2 more empty — all 5 should be called
        $this->service->shouldReceive('getNews')
            ->times(5)
            ->andReturn([], [], [$this->makeArticle()], [], []);

        $this->callLoadNews('en');

        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
    }

    // -------------------------------------------------------------------------
    // All species processed (including last one — BUG 2 regression)
    // -------------------------------------------------------------------------

    public function test_last_batch_species_is_processed_not_skipped(): void
    {
        $this->setSpecies('en', [
            'ocelot' => ['words' => ['ocelot'], 'exclude' => [], 'excludeCase' => []],
            'serval' => ['words' => ['serval'], 'exclude' => [], 'excludeCase' => []],
        ]);

        $this->service->shouldReceive('getSearchQueryLimit')->andReturn(400);

        $getNewsCalls = [];
        $this->service->shouldReceive('generateSearchQuery')
            ->andReturnUsing(fn(array $words, array $exclude) => '(' . implode(' OR ', $words) . ')');

        $this->service->shouldReceive('getNews')
            ->once()
            ->andReturnUsing(function ($query) use (&$getNewsCalls) {
                $getNewsCalls[] = $query;
                return [];
            });

        $this->callLoadNews('en');

        // Both species must appear in the query
        $this->assertCount(1, $getNewsCalls);
        $this->assertStringContainsString('ocelot', $getNewsCalls[0]);
        $this->assertStringContainsString('serval', $getNewsCalls[0]);
    }

    // -------------------------------------------------------------------------
    // Inter-species delay
    // -------------------------------------------------------------------------

    public function test_inter_species_delay_is_applied_when_configured(): void
    {
        config(['services.news.inter_species_delay' => 2]);

        $this->setSpecies('en', [
            'lion' => ['words' => ['lion'], 'exclude' => ['horoscope'], 'excludeCase' => []],
            'tiger' => ['words' => ['tiger'], 'exclude' => ['football'], 'excludeCase' => []],
        ]);

        $this->service->shouldReceive('generateSearchQuery')->andReturn('query');
        $this->service->shouldReceive('getNews')->andReturn([]);

        $this->callLoadNews('en');

        Sleep::assertSleptTimes(2);
    }

    public function test_no_delay_when_inter_species_delay_is_zero(): void
    {
        config(['services.news.inter_species_delay' => 0]);

        $this->setSpecies('en', [
            'lion' => ['words' => ['lion'], 'exclude' => ['horoscope'], 'excludeCase' => []],
            'tiger' => ['words' => ['tiger'], 'exclude' => ['football'], 'excludeCase' => []],
        ]);

        $this->service->shouldReceive('generateSearchQuery')->andReturn('query');
        $this->service->shouldReceive('getNews')->andReturn([]);

        $this->callLoadNews('en');

        Sleep::assertSleptTimes(0);
    }

    // -------------------------------------------------------------------------
    // SEPARATE species: no cross-species exclusion bleed (BUG 5)
    // -------------------------------------------------------------------------

    public function test_separate_species_exclusions_do_not_bleed_across_queries(): void
    {
        $this->setSpecies('en', [
            'lion' => ['words' => ['lion'], 'exclude' => ['detroit', 'horoscope'], 'excludeCase' => []],
            'tiger' => ['words' => ['tiger'], 'exclude' => ['bengal'], 'excludeCase' => []],
        ]);

        $queryCaptures = [];
        $this->service->shouldReceive('generateSearchQuery')
            ->andReturnUsing(function (array $words, array $exclude) use (&$queryCaptures) {
                $q = '(' . implode(' OR ', $words) . ')';
                foreach ($exclude as $e) {
                    $q .= ' !' . $e;
                }
                $queryCaptures[] = ['words' => $words, 'exclude' => $exclude];
                return $q;
            });

        $this->service->shouldReceive('getNews')->andReturn([]);

        $this->callLoadNews('en');

        // Lion's exclusions should not appear in tiger's query
        $this->assertSame(['detroit', 'horoscope'], $queryCaptures[0]['exclude']);
        $this->assertSame(['bengal'], $queryCaptures[1]['exclude']);
        $this->assertNotContains('detroit', $queryCaptures[1]['exclude']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeArticle(): array
    {
        return [
            'title' => 'Test article',
            'link' => 'https://example.com/test',
            'published_date' => '2024-01-15 10:00:00',
            'author' => null,
            'content' => 'Test content for article.',
            'summary' => 'Test summary.',
            'id' => 'abc123',
            '_id' => 'abc123',
            'language' => 'en',
            'name_source' => 'Test Source',
            'rights' => null,
            'clean_url' => 'example.com',
            'domain_url' => 'example.com',
            'media' => null,
        ];
    }

    private function setSpecies(string $lang, array $speciesData): void
    {
        $prop = new ReflectionProperty(NewsController::class, 'species');
        $prop->setAccessible(true);
        $prop->setValue($this->controller, [$lang => $speciesData]);
    }

    private function callLoadNews(string $lang): array
    {
        $method = new ReflectionMethod(NewsController::class, 'loadNews');
        $method->setAccessible(true);

        return $method->invoke($this->controller, $lang);
    }
}
