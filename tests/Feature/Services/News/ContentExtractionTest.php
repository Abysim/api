<?php

namespace Tests\Feature\Services\News;

use App\Services\News\FreeNewsService;
use App\Services\News\GdeltSource;
use App\Services\News\GoogleNewsSource;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Integration test: fetches real articles from GDELT (direct URLs), extracts
 * full text via Readability, and validates extraction quality.
 *
 * Tests content extraction from both GDELT (direct URLs) and Google News
 * (decoded via GoogleNewsUrlDecoder) articles.
 *
 * Run:  SKIP_LIVE_API_TESTS=false p vendor/bin/phpunit tests/Feature/Services/News/ContentExtractionTest.php --no-coverage
 */
class ContentExtractionTest extends TestCase
{
    private FreeNewsService $service;
    private \ReflectionMethod $extractContent;

    /** Articles fetched once from GDELT, shared across all tests */
    private static array $gdeltArticles = [];
    /** Enriched articles (after content extraction), cached to avoid re-fetching */
    private static array $enrichedArticles = [];
    private static bool $gdeltFetched = false;

    /** Common junk patterns that should NOT appear in clean article text */
    private const JUNK_PATTERNS = [
        'subscribe to our newsletter',
        'sign up for',
        'advertisement',
        'skip to content',
        'skip to main',
        'privacy policy',
        'terms of service',
        'all rights reserved',
        'powered by wordpress',
        'leave a comment',
        'you may also like',
        'share this article',
        'follow us on',
        'download our app',
    ];

    /** Navigation/UI patterns that indicate extraction grabbed non-article content */
    private const NAV_PATTERNS = [
        'main menu',
        'footer navigation',
        'breadcrumb',
        'toggle navigation',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('SKIP_LIVE_API_TESTS') !== 'false') {
            $this->markTestSkipped('Live API tests disabled. Set SKIP_LIVE_API_TESTS=false to run.');
        }

        $this->service = $this->app->make(FreeNewsService::class);

        $this->extractContent = new \ReflectionMethod(FreeNewsService::class, 'extractContent');
        $this->extractContent->setAccessible(true);
    }

    // =========================================================================
    // Core extraction tests (GDELT — direct URLs)
    // =========================================================================

    public function test_extract_full_content_from_gdelt_articles(): void
    {
        $enriched = $this->getEnrichedArticles();

        if (empty($enriched)) {
            $this->markTestSkipped('GDELT returned no articles (rate limited or unavailable)');
        }

        $successful = 0;
        $fallback = 0;

        foreach ($enriched as $article) {
            $this->assertArrayHasKey('title', $article);
            $this->assertArrayHasKey('content', $article);
            $this->assertArrayHasKey('link', $article);

            if ($article['content'] !== $article['title'] && mb_strlen($article['content']) >= 100) {
                $successful++;
                echo "\n  OK: " . mb_substr($article['title'], 0, 70)
                    . " (" . mb_strlen($article['content']) . " chars)";
            } else {
                $fallback++;
                echo "\n  FALLBACK: " . mb_substr($article['title'], 0, 70);
            }
        }

        echo "\n  Results: {$successful} extracted, {$fallback} fallback out of " . count($enriched) . "\n";

        $this->assertGreaterThanOrEqual(
            (int) ceil(count($enriched) / 3),
            $successful,
            "Too few articles had content extracted ({$successful}/" . count($enriched) . ")"
        );
    }

    public function test_extracted_content_has_no_html_tags(): void
    {
        $enriched = $this->getEnrichedArticles();

        if (empty($enriched)) {
            $this->markTestSkipped('GDELT unavailable');
        }

        foreach ($enriched as $article) {
            $this->assertSame(
                $article['content'],
                strip_tags($article['content']),
                "Content for '{$article['title']}' should not contain HTML tags"
            );
        }
    }

    public function test_extracted_content_has_no_junk_or_navigation(): void
    {
        $enriched = $this->getEnrichedArticles();

        if (empty($enriched)) {
            $this->markTestSkipped('GDELT unavailable');
        }

        $junkFound = [];

        foreach ($enriched as $article) {
            $contentLower = mb_strtolower($article['content']);

            if ($article['content'] === $article['title']) {
                continue;
            }

            foreach (self::JUNK_PATTERNS as $pattern) {
                if (str_contains($contentLower, $pattern)) {
                    $junkFound[] = "JUNK '{$pattern}' in: " . mb_substr($article['title'], 0, 50);
                }
            }

            foreach (self::NAV_PATTERNS as $pattern) {
                if (str_contains($contentLower, $pattern)) {
                    $junkFound[] = "NAV '{$pattern}' in: " . mb_substr($article['title'], 0, 50);
                }
            }
        }

        if (!empty($junkFound)) {
            echo "\n  Junk found:\n  " . implode("\n  ", $junkFound) . "\n";
        }

        $this->assertLessThanOrEqual(5, count($junkFound),
            "Too many junk/nav patterns in extracted content:\n" . implode("\n", $junkFound));
    }

    public function test_extracted_content_is_substantial_with_multiple_sentences(): void
    {
        $enriched = $this->getEnrichedArticles();

        if (empty($enriched)) {
            $this->markTestSkipped('GDELT unavailable');
        }

        $substantialCount = 0;

        foreach ($enriched as $article) {
            if ($article['content'] === $article['title']) {
                continue;
            }

            $contentLength = mb_strlen($article['content']);

            if ($contentLength >= 200) {
                $substantialCount++;

                $sentenceEndings = preg_match_all('/[.!?]/', $article['content']);
                $this->assertGreaterThanOrEqual(2, $sentenceEndings,
                    "Article '{$article['title']}' ({$contentLength} chars) should have multiple sentences");
            }
        }

        $this->assertGreaterThanOrEqual(1, $substantialCount,
            'At least 1 article should have substantial content (>= 200 chars)');
    }

    public function test_extracted_content_relates_to_article_title(): void
    {
        $enriched = $this->getEnrichedArticles();

        if (empty($enriched)) {
            $this->markTestSkipped('GDELT unavailable');
        }

        $relevantCount = 0;

        foreach ($enriched as $article) {
            if ($article['content'] === $article['title']) {
                continue;
            }

            $titleWords = array_filter(
                preg_split('/[\s,\-:]+/', mb_strtolower($article['title'])),
                fn($w) => mb_strlen($w) >= 4
            );

            if (empty($titleWords)) {
                continue;
            }

            $contentLower = mb_strtolower($article['content']);
            $matchCount = 0;
            foreach ($titleWords as $word) {
                if (str_contains($contentLower, $word)) {
                    $matchCount++;
                }
            }

            if ($matchCount > 0) {
                $relevantCount++;
            }
        }

        $this->assertGreaterThanOrEqual(1, $relevantCount,
            'At least 1 article content should contain words from its title');
    }

    public function test_enriched_article_has_all_required_output_fields(): void
    {
        $enriched = $this->getEnrichedArticles();

        if (empty($enriched)) {
            $this->markTestSkipped('GDELT unavailable');
        }

        $requiredKeys = [
            'title', 'link', 'published_date', 'author', 'content',
            'summary', 'id', '_id', 'language', 'name_source',
            'rights', 'clean_url', 'domain_url', 'media',
        ];

        foreach ($enriched as $article) {
            foreach ($requiredKeys as $key) {
                $this->assertArrayHasKey($key, $article,
                    "Enriched article missing key '{$key}' for: {$article['title']}");
            }

            $this->assertLessThanOrEqual(503, mb_strlen($article['summary']),
                'Summary should be capped at ~500 chars');
            $this->assertSame($article['id'], $article['_id']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $article['id']);
        }
    }

    // =========================================================================
    // Google News content extraction (via URL decoder)
    // =========================================================================

    public function test_google_news_articles_get_content_extracted_via_decoder(): void
    {
        $source = new GoogleNewsSource();
        $query = $source->buildQuery('(lion OR tiger OR leopard OR cheetah)', 'en');
        $articles = $source->fetch($query, 'en');

        if (empty($articles)) {
            $this->markTestSkipped('Google News returned no articles for big cat query');
        }

        $article = $articles[0];
        $this->assertStringContainsString('news.google.com', $article['link']);

        $enriched = $this->extractContent->invoke($this->service, $article);

        $this->assertArrayHasKey('id', $enriched);
        $this->assertArrayHasKey('summary', $enriched);
        $this->assertArrayHasKey('published_date', $enriched);

        // The link should now be the decoded real article URL (not news.google.com)
        if (!str_contains($enriched['link'], 'news.google.com')) {
            echo "\n  DECODED: " . mb_substr($enriched['title'], 0, 60)
                . "\n    link: " . $enriched['link']
                . "\n    content length: " . mb_strlen($enriched['content']) . " chars";

            // Content should be more than just the title
            if ($enriched['content'] !== $enriched['title']) {
                $this->assertGreaterThan(100, mb_strlen($enriched['content']),
                    'Decoded Google News article should have substantial content');
            }
        } else {
            echo "\n  FALLBACK (decoder failed): " . mb_substr($enriched['title'], 0, 60);
        }
    }

    public function test_google_news_extracts_content_from_multiple_articles(): void
    {
        $source = new GoogleNewsSource();
        $query = $source->buildQuery('(lion OR tiger OR wildlife)', 'en');
        $articles = $source->fetch($query, 'en');

        if (count($articles) < 2) {
            $this->markTestSkipped('Google News returned too few articles');
        }

        $decoded = 0;
        $contentExtracted = 0;
        $tested = min(3, count($articles));

        foreach (array_slice($articles, 0, $tested) as $i => $article) {
            if ($i > 0) {
                sleep(2);
            }

            $enriched = $this->extractContent->invoke($this->service, $article);

            if (!str_contains($enriched['link'], 'news.google.com')) {
                $decoded++;
                if ($enriched['content'] !== $enriched['title'] && mb_strlen($enriched['content']) >= 100) {
                    $contentExtracted++;
                    echo "\n  EXTRACTED: " . mb_substr($enriched['title'], 0, 50)
                        . " (" . mb_strlen($enriched['content']) . " chars)";
                } else {
                    echo "\n  DECODED BUT NO CONTENT: " . mb_substr($enriched['title'], 0, 50);
                }
            } else {
                echo "\n  DECODE FAILED: " . mb_substr($enriched['title'], 0, 50);
            }
        }

        echo "\n  Results: {$decoded} decoded, {$contentExtracted} content extracted out of {$tested}\n";

        $this->assertGreaterThanOrEqual(1, $decoded,
            'At least 1 Google News article should be decoded to a real URL');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Fetch GDELT articles once and cache for all tests.
     * Single API call avoids rate limiting.
     */
    private function getGdeltArticles(): array
    {
        if (!self::$gdeltFetched) {
            self::$gdeltFetched = true;

            $source = new GdeltSource();
            $query = $source->buildQuery('(lion OR tiger OR leopard OR wildlife)', 'en', [], []);
            $articles = $source->fetch($query, 'en');

            // Filter to direct, non-Google URLs
            $articles = array_filter($articles, function ($article) {
                return !empty($article['link'])
                    && !str_contains($article['link'], 'google.com')
                    && !str_contains($article['link'], 'youtube.com');
            });

            self::$gdeltArticles = array_slice(array_values($articles), 0, 6);
        }

        return self::$gdeltArticles;
    }

    /**
     * Get enriched articles (with extracted content), cached after first extraction.
     */
    private function getEnrichedArticles(): array
    {
        if (empty(self::$enrichedArticles) && !empty($this->getGdeltArticles())) {
            foreach ($this->getGdeltArticles() as $i => $article) {
                if ($i > 0) {
                    sleep(2); // polite crawling delay
                }
                self::$enrichedArticles[] = $this->extractContent->invoke($this->service, $article);
            }
        }

        return self::$enrichedArticles;
    }
}
