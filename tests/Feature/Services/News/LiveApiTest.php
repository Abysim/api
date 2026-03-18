<?php

namespace Tests\Feature\Services\News;

use App\Services\News\GdeltSource;
use App\Services\News\GoogleNewsSource;
use Tests\TestCase;

/**
 * Live API integration tests — hit real endpoints to verify response format.
 *
 * Run manually:   p vendor/bin/phpunit tests/Feature/Services/News/LiveApiTest.php
 * Skip in CI:     these tests are grouped under "live-api" and skipped by default
 */
class LiveApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('SKIP_LIVE_API_TESTS') !== 'false') {
            $this->markTestSkipped('Live API tests are disabled. Set SKIP_LIVE_API_TESTS=false in .env to run.');
        }
    }

    // ==================== Google News RSS ====================

    public function test_google_news_rss_returns_valid_xml_with_articles(): void
    {
        $source = new GoogleNewsSource();
        $query = $source->buildQuery('(lion OR tiger)', 'en');
        $articles = $source->fetch($query, 'en');

        $this->assertIsArray($articles);
        $this->assertNotEmpty($articles, 'Google News returned no articles — API may be rate-limiting or query too narrow');

        $article = $articles[0];
        $this->assertArrayHasKey('title', $article);
        $this->assertArrayHasKey('link', $article);
        $this->assertArrayHasKey('published_date', $article);
        $this->assertArrayHasKey('name_source', $article);
        $this->assertArrayHasKey('clean_url', $article);
        $this->assertArrayHasKey('language', $article);
        $this->assertArrayHasKey('media', $article);

        // Validate data types and format
        $this->assertNotEmpty($article['title'], 'Title should not be empty');
        $this->assertNotEmpty($article['link'], 'Link should not be empty');
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $article['published_date'],
            'published_date should be Y-m-d H:i:s format'
        );
        $this->assertStringStartsWith('http', $article['link'], 'Link should be a URL');
        $this->assertSame('en', $article['language']);
        $this->assertNull($article['media'], 'Google News does not provide media');
    }

    public function test_google_news_rss_returns_valid_articles_for_uk_language(): void
    {
        $source = new GoogleNewsSource();
        $query = $source->buildQuery('(лев OR тигр)', 'uk');
        $articles = $source->fetch($query, 'uk');

        $this->assertIsArray($articles);
        // UK language may return fewer results, so we don't assert notEmpty
        if (!empty($articles)) {
            $this->assertSame('uk', $articles[0]['language']);
            $this->assertNotEmpty($articles[0]['title']);
        }
    }

    // ==================== GDELT API ====================

    public function test_gdelt_api_returns_valid_json_with_articles(): void
    {
        $source = new GdeltSource();
        // Use a broad query to maximize chances of getting results in 26h window
        $query = $source->buildQuery('(climate OR election OR war)', 'en', ['RU'], []);
        $articles = $source->fetch($query, 'en');

        $this->assertIsArray($articles);

        if (empty($articles)) {
            $this->markTestSkipped('GDELT returned no articles — API may be temporarily unavailable');
        }

        $article = $articles[0];
        $this->assertArrayHasKey('title', $article);
        $this->assertArrayHasKey('link', $article);
        $this->assertArrayHasKey('published_date', $article);
        $this->assertArrayHasKey('name_source', $article);
        $this->assertArrayHasKey('clean_url', $article);
        $this->assertArrayHasKey('language', $article);
        $this->assertArrayHasKey('media', $article);

        // Validate data types and format
        $this->assertNotEmpty($article['title'], 'Title should not be empty');
        $this->assertNotEmpty($article['link'], 'Link should not be empty');
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $article['published_date'],
            'published_date should be Y-m-d H:i:s format'
        );
        $this->assertStringStartsWith('http', $article['link'], 'Link should be a URL');
        $this->assertSame('en', $article['language']);
        $this->assertNotEmpty($article['name_source'], 'name_source (domain) should not be empty');
    }

    public function test_gdelt_api_returns_valid_articles_for_uk_language(): void
    {
        $source = new GdeltSource();
        $query = $source->buildQuery('(війна OR політика OR клімат)', 'uk', ['RU'], []);
        $articles = $source->fetch($query, 'uk');

        $this->assertIsArray($articles);
        if (!empty($articles)) {
            $this->assertSame('uk', $articles[0]['language']);
            $this->assertNotEmpty($articles[0]['title']);
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
                $articles[0]['published_date']
            );
        }
    }

    public function test_gdelt_api_respects_domain_exclusions(): void
    {
        $source = new GdeltSource();
        $excludeDomains = ['cnn.com'];
        $query = $source->buildQuery('(climate OR election OR war)', 'en', [], $excludeDomains);
        $articles = $source->fetch($query, 'en');

        $this->assertIsArray($articles);
        foreach ($articles as $article) {
            $this->assertStringNotContainsString(
                'cnn.com',
                $article['clean_url'],
                'Excluded domain should not appear in results'
            );
        }
    }

    // ==================== Cross-source format compatibility ====================

    public function test_both_sources_return_same_article_structure(): void
    {
        $expectedKeys = ['title', 'link', 'published_date', 'name_source', 'clean_url', 'language', 'media'];

        $google = new GoogleNewsSource();
        $googleArticles = $google->fetch($google->buildQuery('(climate OR election)', 'en'), 'en');

        $gdelt = new GdeltSource();
        $gdeltArticles = $gdelt->fetch($gdelt->buildQuery('(climate OR election)', 'en', [], []), 'en');

        if (!empty($googleArticles)) {
            $this->assertSame($expectedKeys, array_keys($googleArticles[0]),
                'Google article keys should match expected structure');
        }

        if (!empty($gdeltArticles)) {
            $this->assertSame($expectedKeys, array_keys($gdeltArticles[0]),
                'GDELT article keys should match expected structure');
        }

        // At least one source should return data
        $this->assertTrue(
            !empty($googleArticles) || !empty($gdeltArticles),
            'At least one source should return articles for a common query'
        );
    }
}
