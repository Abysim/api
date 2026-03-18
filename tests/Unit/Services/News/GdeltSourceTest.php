<?php

namespace Tests\Unit\Services\News;

use App\Services\News\GdeltSource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class GdeltSourceTest extends TestCase
{
    private GdeltSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = new GdeltSource();
        Log::shouldReceive('info', 'warning', 'error')->andReturnNull()->byDefault();
    }

    // buildQuery

    public function test_buildQuery_appends_sourcelang_mapped_to_gdelt_format(): void
    {
        $result = $this->source->buildQuery('ukraine war', 'en', [], []);

        $this->assertStringContainsString(' sourcelang:english', $result);
    }

    public function test_buildQuery_maps_uk_to_ukrainian(): void
    {
        $result = $this->source->buildQuery('ukraine war', 'uk', [], []);

        $this->assertStringContainsString(' sourcelang:ukrainian', $result);
    }

    public function test_buildQuery_maps_iso_country_codes_to_fips_and_appends_exclusion(): void
    {
        $result = $this->source->buildQuery('ukraine war', 'en', ['RU', 'BY'], []);

        $this->assertStringContainsString(' -sourcecountry:RS', $result);
        $this->assertStringContainsString(' -sourcecountry:BO', $result);
    }

    public function test_buildQuery_passes_unmapped_country_code_as_is(): void
    {
        $result = $this->source->buildQuery('ukraine war', 'en', ['KZ'], []);

        $this->assertStringContainsString(' -sourcecountry:KZ', $result);
    }

    public function test_buildQuery_appends_domain_exclusion_for_each_domain(): void
    {
        $result = $this->source->buildQuery('ukraine war', 'en', [], ['rt.com', 'sputnik.com']);

        $this->assertStringContainsString(' -domain:rt.com', $result);
        $this->assertStringContainsString(' -domain:sputnik.com', $result);
    }

    public function test_buildQuery_passes_through_negation_syntax_unchanged(): void
    {
        // Negation conversion (! -> -) is now done by FreeNewsService before calling buildQuery
        $result = $this->source->buildQuery('ukraine -russia -propaganda', 'en', [], []);

        $this->assertStringContainsString(' -russia', $result);
        $this->assertStringContainsString(' -propaganda', $result);
    }

    public function test_buildQuery_with_no_exclusions_returns_base_query_plus_mapped_lang(): void
    {
        $result = $this->source->buildQuery('climate change', 'uk', [], []);

        $this->assertSame('climate change sourcelang:ukrainian', $result);
    }

    public function test_buildQuery_passes_unmapped_lang_as_is(): void
    {
        $result = $this->source->buildQuery('news', 'fr', [], []);

        $this->assertStringContainsString(' sourcelang:fr', $result);
    }

    // fetch – happy path

    public function test_fetch_parses_articles_with_all_required_keys(): void
    {
        Http::fake([
            '*' => Http::response($this->gdeltPayload(), 200),
        ]);

        $articles = $this->source->fetch('ukraine war sourcelang:en', 'en');

        $this->assertCount(2, $articles);

        $first = $articles[0];
        $this->assertArrayHasKey('title', $first);
        $this->assertArrayHasKey('link', $first);
        $this->assertArrayHasKey('published_date', $first);
        $this->assertArrayHasKey('name_source', $first);
        $this->assertArrayHasKey('clean_url', $first);
        $this->assertArrayHasKey('language', $first);
        $this->assertArrayHasKey('media', $first);

        $this->assertSame('https://www.bbc.com/news/world-europe-12345678', $first['link']);
        $this->assertSame('bbc.com', $first['name_source']);
        $this->assertSame('bbc.com', $first['clean_url']);
        $this->assertSame('2026-03-07 12:00:00', $first['published_date']);
        $this->assertSame('https://ichef.bbci.co.uk/news/1024/branded_news/image.jpg', $first['media']);
        $this->assertSame('en', $first['language']);
        $this->assertSame('en', $articles[1]['language']);
    }

    // fetch – error cases

    public function test_fetch_returns_empty_array_on_http_400(): void
    {
        Http::fake([
            '*' => Http::response('Bad Request', 400),
        ]);

        $articles = $this->source->fetch('ukraine war', 'en');

        $this->assertSame([], $articles);
    }

    public function test_fetch_returns_empty_array_on_http_404(): void
    {
        Http::fake([
            '*' => Http::response('Not Found', 404),
        ]);

        $articles = $this->source->fetch('ukraine war', 'en');

        $this->assertSame([], $articles);
    }

    public function test_fetch_returns_empty_array_when_json_has_no_articles_key(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'ok'], 200),
        ]);

        $articles = $this->source->fetch('ukraine war', 'en');

        $this->assertSame([], $articles);
    }

    public function test_fetch_returns_empty_array_when_articles_is_empty(): void
    {
        Http::fake([
            '*' => Http::response(['articles' => []], 200),
        ]);

        $articles = $this->source->fetch('ukraine war', 'en');

        $this->assertSame([], $articles);
    }

    // fetch – rate limit detection (HTTP 200 with text body)

    public function test_fetch_retries_on_rate_limit_and_returns_data_on_second_attempt(): void
    {
        Sleep::fake();

        Http::fake([
            '*' => Http::sequence()
                ->push('Please limit requests to one every 5 seconds', 200)
                ->push($this->gdeltPayload(), 200),
        ]);

        $articles = $this->source->fetch('ukraine war', 'en');

        $this->assertCount(2, $articles);
        Sleep::assertSleptTimes(1);
    }

    public function test_fetch_returns_empty_on_persistent_rate_limit(): void
    {
        Sleep::fake();

        Http::fake([
            '*' => Http::sequence()
                ->push('Please limit requests to one every 5 seconds', 200)
                ->push('Please limit requests to one every 5 seconds', 200),
        ]);

        $articles = $this->source->fetch('ukraine war', 'en');

        $this->assertSame([], $articles);
    }

    // fetch – retry on HTTP 429

    public function test_fetch_retries_on_http_429_and_returns_data_on_second_attempt(): void
    {
        Sleep::fake();

        Http::fake([
            '*' => Http::sequence()
                ->push('Rate limited', 429)
                ->push($this->gdeltPayload(), 200),
        ]);

        $articles = $this->source->fetch('ukraine war', 'en');

        $this->assertCount(2, $articles);
        Sleep::assertSleptTimes(1);
    }

    public function test_fetch_returns_empty_on_persistent_http_429(): void
    {
        Sleep::fake();

        Http::fake([
            '*' => Http::sequence()
                ->push('Rate limited', 429)
                ->push('Rate limited', 429),
        ]);

        $articles = $this->source->fetch('ukraine war', 'en');

        $this->assertSame([], $articles);
    }

    // fetch – retry on HTTP 500

    public function test_fetch_retries_once_on_http_500_and_returns_data_on_second_attempt(): void
    {
        Sleep::fake();

        Http::fake([
            '*' => Http::sequence()
                ->push($this->gdeltPayload(), 500)
                ->push($this->gdeltPayload(), 200),
        ]);

        $articles = $this->source->fetch('ukraine war', 'en');

        $this->assertCount(2, $articles);
    }

    public function test_fetch_returns_empty_array_when_both_attempts_return_500(): void
    {
        Sleep::fake();

        Http::fake([
            '*' => Http::sequence()
                ->push('Server Error', 500)
                ->push('Server Error', 500),
        ]);

        $articles = $this->source->fetch('ukraine war', 'en');

        $this->assertSame([], $articles);
    }

    // fetch – time window

    public function test_fetch_uses_50h_window_for_uk_language(): void
    {
        Http::fake([
            '*' => Http::response(['articles' => []], 200),
        ]);

        $this->source->fetch('ukraine war', 'uk');

        $request = Http::recorded()[0][0];
        $startDatetime = $request->data()['startdatetime'];

        $start = \Carbon\Carbon::createFromFormat('YmdHis', $startDatetime);
        $diffHours = now()->diffInHours($start, false);

        // Should be approximately -50h (we allow ±1h for test execution time)
        $this->assertLessThanOrEqual(-49, $diffHours);
        $this->assertGreaterThanOrEqual(-51, $diffHours);
    }

    public function test_fetch_uses_26h_window_for_en_language(): void
    {
        Http::fake([
            '*' => Http::response(['articles' => []], 200),
        ]);

        $this->source->fetch('ukraine war', 'en');

        $request = Http::recorded()[0][0];
        $startDatetime = $request->data()['startdatetime'];

        $start = \Carbon\Carbon::createFromFormat('YmdHis', $startDatetime);
        $diffHours = now()->diffInHours($start, false);

        // Should be approximately -26h (we allow ±1h for test execution time)
        $this->assertLessThanOrEqual(-25, $diffHours);
        $this->assertGreaterThanOrEqual(-27, $diffHours);
    }

    // fetch – maxrecords

    public function test_fetch_uses_maxrecords_250(): void
    {
        Http::fake([
            '*' => Http::response(['articles' => []], 200),
        ]);

        $this->source->fetch('ukraine war', 'en');

        $request = Http::recorded()[0][0];
        $this->assertSame(250, $request->data()['maxrecords']);
    }

    // wasRateLimited

    public function test_wasRateLimited_returns_false_after_successful_fetch(): void
    {
        Http::fake(['*' => Http::response($this->gdeltPayload(), 200)]);

        $this->source->fetch('ukraine war', 'en');

        $this->assertFalse($this->source->wasRateLimited());
    }

    public function test_wasRateLimited_returns_true_after_http_429(): void
    {
        Sleep::fake();
        Http::fake([
            '*' => Http::sequence()
                ->push('Rate limited', 429)
                ->push('Rate limited', 429),
        ]);

        $this->source->fetch('ukraine war', 'en');

        $this->assertTrue($this->source->wasRateLimited());
    }

    public function test_wasRateLimited_returns_true_after_text_rate_limit(): void
    {
        Sleep::fake();
        Http::fake([
            '*' => Http::sequence()
                ->push('Please limit requests to one every 5 seconds', 200)
                ->push('Please limit requests to one every 5 seconds', 200),
        ]);

        $this->source->fetch('ukraine war', 'en');

        $this->assertTrue($this->source->wasRateLimited());
    }

    public function test_wasRateLimited_resets_to_false_on_next_successful_fetch(): void
    {
        Sleep::fake();

        // First fetch: rate limited
        Http::fake([
            '*' => Http::sequence()
                ->push('Rate limited', 429)
                ->push('Rate limited', 429),
        ]);
        $this->source->fetch('ukraine war', 'en');
        $this->assertTrue($this->source->wasRateLimited());

        // Second fetch: success
        Http::fake(['*' => Http::response($this->gdeltPayload(), 200)]);
        $this->source->fetch('ukraine war', 'en');
        $this->assertFalse($this->source->wasRateLimited());
    }

    // Helpers

    private function gdeltPayload(): array
    {
        return [
            'articles' => [
                [
                    'title' => 'Ukraine War: Latest Updates',
                    'url' => 'https://www.bbc.com/news/world-europe-12345678',
                    'seendate' => '20260307T120000Z',
                    'domain' => 'bbc.com',
                    'socialimage' => 'https://ichef.bbci.co.uk/news/1024/branded_news/image.jpg',
                    'language' => 'English',
                    'sourcecountry' => 'United Kingdom',
                ],
                [
                    'title' => 'Ceasefire Talks Continue',
                    'url' => 'https://www.reuters.com/world/europe/ceasefire-talks-87654321',
                    'seendate' => '20260307T080000Z',
                    'domain' => 'reuters.com',
                    'socialimage' => 'https://cloudfront-us-east-2.images.arcpublishing.com/reuters/image.jpg',
                    'language' => 'English',
                    'sourcecountry' => 'United States',
                ],
            ],
        ];
    }
}
