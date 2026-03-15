<?php

namespace Tests\Feature\Services\News;

use App\Services\News\GoogleNewsSource;
use App\Services\News\GoogleNewsUrlDecoder;
use Tests\TestCase;

/**
 * Live integration test for Google News URL decoding.
 *
 * Run: SKIP_LIVE_API_TESTS=false p vendor/bin/phpunit tests/Feature/Services/News/GoogleNewsDecoderLiveTest.php --no-coverage
 */
class GoogleNewsDecoderLiveTest extends TestCase
{
    private GoogleNewsUrlDecoder $decoder;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('SKIP_LIVE_API_TESTS') !== 'false') {
            $this->markTestSkipped('Live API tests disabled. Set SKIP_LIVE_API_TESTS=false to run.');
        }

        $this->decoder = new GoogleNewsUrlDecoder();
    }

    public function test_decode_real_google_news_url_returns_article_url(): void
    {
        // Fetch a real Google News RSS to get fresh URLs
        $source = new GoogleNewsSource();
        $query = $source->buildQuery('(lion OR tiger OR leopard OR cheetah)', 'en');
        $articles = $source->fetch($query, 'en');

        if (empty($articles)) {
            $this->markTestSkipped('Google News returned no articles');
        }

        // Decode just 3 URLs with delays between to avoid rate limiting
        $decoded = 0;
        $failed = 0;

        foreach (array_slice($articles, 0, 3) as $i => $article) {
            if ($i > 0) {
                sleep(2); // Avoid Google rate limiting
            }

            $url = $article['link'];
            $this->assertStringContainsString('news.google.com', $url);

            $result = $this->decoder->decode($url);

            if ($result !== null && !str_contains($result, 'news.google.com')) {
                $decoded++;
                echo "\n  DECODED: " . mb_substr($article['title'], 0, 50)
                    . "\n    -> " . $result;
                $this->assertStringStartsWith('https://', $result);
            } else {
                $failed++;
                echo "\n  FAILED: " . mb_substr($article['title'], 0, 50)
                    . " (result: " . ($result ?? 'null') . ")";
            }
        }

        echo "\n  Results: {$decoded} decoded, {$failed} failed out of " . min(3, count($articles)) . "\n";

        $this->assertGreaterThanOrEqual(1, $decoded,
            'At least 1 Google News URL should be decoded to a real article URL');
    }

    public function test_decoded_url_points_to_real_article(): void
    {
        $source = new GoogleNewsSource();
        $query = $source->buildQuery('(lion OR tiger OR wildlife)', 'en');
        $articles = $source->fetch($query, 'en');

        if (empty($articles)) {
            $this->markTestSkipped('Google News returned no articles');
        }

        $article = $articles[0];
        $result = $this->decoder->decode($article['link']);

        if ($result === null || str_contains($result, 'news.google.com')) {
            $this->markTestSkipped('Could not decode Google News URL (may be rate limited)');
        }

        // Verify the decoded URL is reachable (allow 401/403 for paywalled sites)
        $response = \Illuminate\Support\Facades\Http::timeout(10)->get($result);
        $this->assertLessThan(500, $response->status(),
            "Decoded URL should not return server error: {$result}");
    }
}
