<?php

namespace Tests\Unit\Services\News;

use App\Services\News\GoogleNewsUrlDecoder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class GoogleNewsUrlDecoderTest extends TestCase
{
    private GoogleNewsUrlDecoder $decoder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->decoder = new GoogleNewsUrlDecoder();
        Log::shouldReceive('info', 'warning', 'error', 'debug')->andReturnNull()->byDefault();
    }

    // -------------------------------------------------------------------------
    // Non-Google URLs (pass-through)
    // -------------------------------------------------------------------------

    public function test_decode_returns_non_google_url_as_is(): void
    {
        $url = 'https://www.bbc.com/news/world-12345';
        $this->assertSame($url, $this->decoder->decode($url));
    }

    public function test_decode_returns_any_non_google_url_unchanged(): void
    {
        $url = 'https://reuters.com/article/some-news';
        $this->assertSame($url, $this->decoder->decode($url));
    }

    // -------------------------------------------------------------------------
    // Legacy base64 decoding (CBMi prefix)
    // -------------------------------------------------------------------------

    public function test_decode_extracts_url_from_legacy_cbmi_encoded_article(): void
    {
        // CBMiG2h0dHBzOi8vZXhhbXBsZS5jb20vYXJ0aWNsZdIBAA encodes https://example.com/article
        $googleUrl = 'https://news.google.com/rss/articles/CBMiG2h0dHBzOi8vZXhhbXBsZS5jb20vYXJ0aWNsZdIBAA';
        $result = $this->decoder->decode($googleUrl);
        $this->assertSame('https://example.com/article', $result);
    }

    public function test_decode_legacy_works_with_articles_path(): void
    {
        $googleUrl = 'https://news.google.com/articles/CBMiG2h0dHBzOi8vZXhhbXBsZS5jb20vYXJ0aWNsZdIBAA';
        $result = $this->decoder->decode($googleUrl);
        $this->assertSame('https://example.com/article', $result);
    }

    public function test_decode_legacy_handles_long_urls(): void
    {
        $articleUrl = 'https://www.washingtonpost.com/world/2026/03/07/some-very-long-article-slug-here/';
        $base64 = $this->encodeLegacy($articleUrl);

        $googleUrl = 'https://news.google.com/rss/articles/' . $base64;
        $result = $this->decoder->decode($googleUrl);
        $this->assertSame($articleUrl, $result);
    }

    public function test_decode_legacy_handles_url_safe_base64_characters(): void
    {
        $articleUrl = 'https://example.com/path?q=test&lang=en';
        $base64 = $this->encodeLegacy($articleUrl);

        $googleUrl = 'https://news.google.com/rss/articles/' . $base64;
        $result = $this->decoder->decode($googleUrl);
        $this->assertSame($articleUrl, $result);
    }

    // -------------------------------------------------------------------------
    // batchexecute API decoding (newer URLs — two-step process)
    // -------------------------------------------------------------------------

    public function test_decode_uses_batchexecute_for_non_legacy_urls(): void
    {
        $articleId = 'AU_yqLM4DFG5xHkNu8s8hn0Z_RMiE';

        Http::fake([
            'news.google.com/articles/*' => Http::response(
                $this->articlePageHtml($articleId, '1772841455', 'AZ5r3eRs7jRnDewJ'),
                200
            ),
            'news.google.com/_/*' => Http::response(
                $this->batchexecuteResponse('https://www.nytimes.com/2026/03/07/real-article.html'),
                200
            ),
        ]);

        $googleUrl = 'https://news.google.com/rss/articles/' . $articleId;
        $result = $this->decoder->decode($googleUrl);

        $this->assertSame('https://www.nytimes.com/2026/03/07/real-article.html', $result);
    }

    public function test_decode_batchexecute_sends_post_with_signature_and_timestamp(): void
    {
        $articleId = 'AU_yqLM4TestArticle';

        Http::fake([
            'news.google.com/articles/*' => Http::response(
                $this->articlePageHtml($articleId, '1772841455', 'TestSignature123'),
                200
            ),
            'news.google.com/_/*' => Http::response(
                $this->batchexecuteResponse('https://example.com/decoded'),
                200
            ),
        ]);

        $this->decoder->decode('https://news.google.com/rss/articles/' . $articleId);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'batchexecute')
                && $request->method() === 'POST'
                && str_contains($request->body(), 'f.req=')
                && str_contains(urldecode($request->body()), '1772841455')
                && str_contains(urldecode($request->body()), 'TestSignature123');
        });
    }

    public function test_decode_batchexecute_handles_escaped_quotes_in_response(): void
    {
        $articleId = 'AU_yqLSomeArticle';

        Http::fake([
            'news.google.com/articles/*' => Http::response(
                $this->articlePageHtml($articleId, '1234567890', 'SigABC'),
                200
            ),
            'news.google.com/_/*' => Http::response(
                $this->batchexecuteResponseEscaped('https://www.reuters.com/world/escaped-article'),
                200
            ),
        ]);

        $googleUrl = 'https://news.google.com/rss/articles/' . $articleId;
        $result = $this->decoder->decode($googleUrl);

        $this->assertSame('https://www.reuters.com/world/escaped-article', $result);
    }

    public function test_decode_falls_back_to_rss_articles_path_when_articles_fails(): void
    {
        $articleId = 'AU_yqLFallbackTest';

        Http::fake([
            'news.google.com/articles/*' => Http::response('Not Found', 404),
            'news.google.com/rss/articles/*' => Http::response(
                $this->articlePageHtml($articleId, '9999999999', 'FallbackSig'),
                200
            ),
            'news.google.com/_/*' => Http::response(
                $this->batchexecuteResponse('https://example.com/fallback-decoded'),
                200
            ),
        ]);

        $googleUrl = 'https://news.google.com/rss/articles/' . $articleId;
        $result = $this->decoder->decode($googleUrl);

        $this->assertSame('https://example.com/fallback-decoded', $result);
    }

    // -------------------------------------------------------------------------
    // Error handling
    // -------------------------------------------------------------------------

    public function test_decode_returns_null_for_google_url_without_article_id(): void
    {
        $result = $this->decoder->decode('https://news.google.com/');
        $this->assertNull($result);
    }

    public function test_decode_returns_null_when_article_page_returns_no_params(): void
    {
        Http::fake([
            'news.google.com/*' => Http::response('<html><body>No params here</body></html>', 200),
            'consent.google.com/*' => Http::response('', 200),
        ]);

        $googleUrl = 'https://news.google.com/rss/articles/AU_yqLNoParams';
        $result = $this->decoder->decode($googleUrl);

        $this->assertNull($result);
    }

    public function test_decode_returns_null_when_article_page_returns_http_error(): void
    {
        Http::fake([
            'news.google.com/*' => Http::response('Server Error', 500),
            'consent.google.com/*' => Http::response('', 200),
        ]);

        $googleUrl = 'https://news.google.com/rss/articles/AU_yqLServerError';
        $result = $this->decoder->decode($googleUrl);

        $this->assertNull($result);
    }

    public function test_decode_returns_null_when_batchexecute_returns_no_url(): void
    {
        Http::fake([
            'news.google.com/articles/*' => Http::response(
                $this->articlePageHtml('AU_yqLBadResp', '123', 'Sig'),
                200
            ),
            'news.google.com/_/*' => Http::response('garbage response', 200),
        ]);

        $googleUrl = 'https://news.google.com/rss/articles/AU_yqLBadResp';
        $result = $this->decoder->decode($googleUrl);

        $this->assertNull($result);
    }

    public function test_decode_returns_null_when_batchexecute_returns_rate_limit(): void
    {
        Http::fake([
            'news.google.com/articles/*' => Http::response(
                $this->articlePageHtml('AU_yqLRateLimit', '123', 'Sig'),
                200
            ),
            'news.google.com/_/*' => Http::response('Too Many Requests', 429),
        ]);

        $googleUrl = 'https://news.google.com/rss/articles/AU_yqLRateLimit';
        $result = $this->decoder->decode($googleUrl);

        $this->assertNull($result);
    }

    public function test_decode_returns_null_for_malformed_base64(): void
    {
        Http::fake([
            'news.google.com/*' => Http::response('not found', 404),
            'consent.google.com/*' => Http::response('', 200),
        ]);

        $googleUrl = 'https://news.google.com/rss/articles/!!!invalid!!!';
        $result = $this->decoder->decode($googleUrl);
        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // Consent cookie caching and refresh
    // -------------------------------------------------------------------------

    public function test_get_consent_cookie_returns_cached_value(): void
    {
        Cache::put('google_news_consent_cookie', 'SOCS=cached_value_123', now()->addDay());

        $articleId = 'AU_yqLCachedCookie';

        Http::fake([
            'news.google.com/articles/*' => Http::response(
                $this->articlePageHtml($articleId, '111', 'Sig1'),
                200
            ),
            'news.google.com/_/*' => Http::response(
                $this->batchexecuteResponse('https://example.com/cached-test'),
                200
            ),
        ]);

        $this->decoder->decode('https://news.google.com/rss/articles/' . $articleId);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'news.google.com/articles/')
                && $request->hasHeader('Cookie', 'SOCS=cached_value_123');
        });
    }

    public function test_get_consent_cookie_returns_fallback_when_cache_empty(): void
    {
        Cache::forget('google_news_consent_cookie');

        $articleId = 'AU_yqLFallbackCookie';
        $fallback = 'SOCS=CAISNQgDEitib3FfaWRlbnRpdHlmcm9udGVuZHVpc2VydmVyXzIwMjMwODI5LjA3X3AxGgJlbiACGgYIgJr2pwY';

        Http::fake([
            'news.google.com/articles/*' => Http::response(
                $this->articlePageHtml($articleId, '222', 'Sig2'),
                200
            ),
            'news.google.com/_/*' => Http::response(
                $this->batchexecuteResponse('https://example.com/fallback-cookie-test'),
                200
            ),
        ]);

        $this->decoder->decode('https://news.google.com/rss/articles/' . $articleId);

        Http::assertSent(function ($request) use ($fallback) {
            return str_contains($request->url(), 'news.google.com/articles/')
                && $request->hasHeader('Cookie', $fallback);
        });
    }

    public function test_refresh_consent_cookie_extracts_socs_and_caches_it(): void
    {
        Cache::forget('google_news_consent_cookie');

        $articleId = 'AU_yqLRefreshTest';

        Http::fake([
            // First attempt: no params (triggers refresh)
            'news.google.com/articles/*' => Http::sequence()
                ->push('<html>consent page</html>', 200)
                ->push($this->articlePageHtml($articleId, '333', 'Sig3'), 200),
            'news.google.com/rss/articles/*' => Http::sequence()
                ->push('<html>consent page</html>', 200)
                ->push($this->articlePageHtml($articleId, '333', 'Sig3'), 200),
            // Consent refresh returns SOCS cookie
            'consent.google.com/*' => Http::response('', 302, [
                'Set-Cookie' => 'SOCS=freshvalue123; expires=Thu, 01-Jan-2099 00:00:00 GMT; path=/; domain=.google.com',
            ]),
            'news.google.com/_/*' => Http::response(
                $this->batchexecuteResponse('https://example.com/refreshed'),
                200
            ),
        ]);

        $result = $this->decoder->decode('https://news.google.com/rss/articles/' . $articleId);

        $this->assertSame('https://example.com/refreshed', $result);
        $this->assertSame('SOCS=freshvalue123', Cache::get('google_news_consent_cookie'));
    }

    public function test_refresh_does_not_retry_more_than_once(): void
    {
        Http::fake([
            'news.google.com/*' => Http::response('<html>consent page</html>', 200),
            // Consent refresh returns a cookie, but retry still fails
            'consent.google.com/*' => Http::response('', 302, [
                'Set-Cookie' => 'SOCS=refreshed_but_useless; path=/',
            ]),
        ]);

        $result = $this->decoder->decode('https://news.google.com/rss/articles/AU_yqLNoRetryLoop');
        $this->assertNull($result);

        // Should have called consent.google.com exactly once (no infinite loop)
        Http::assertSentCount(5);
        // 1: articles/ (fail) 2: rss/articles/ (fail) 3: consent refresh
        // 4: articles/ retry (fail) 5: rss/articles/ retry (fail)
    }

    public function test_refresh_failure_falls_back_to_hardcoded_cookie(): void
    {
        Cache::forget('google_news_consent_cookie');

        Http::fake([
            'news.google.com/*' => Http::response('<html>consent page</html>', 200),
            // Consent refresh fails (no SOCS in response)
            'consent.google.com/*' => Http::response('error', 500),
        ]);

        $result = $this->decoder->decode('https://news.google.com/rss/articles/AU_yqLRefreshFail');
        $this->assertNull($result);
        $this->assertNull(Cache::get('google_news_consent_cookie'));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function encodeLegacy(string $url): string
    {
        $prefix = "\x08\x13\x22";
        $suffix = "\xd2\x01\x00";
        $data = $prefix . chr(strlen($url)) . $url . $suffix;

        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function articlePageHtml(string $articleId, string $timestamp, string $signature): string
    {
        return '<html><body><c-wiz><div data-n-a-id="' . $articleId
            . '" data-n-a-ts="' . $timestamp
            . '" data-n-a-sg="' . $signature
            . '"></div></c-wiz></body></html>';
    }

    private function batchexecuteResponse(string $url): string
    {
        return ')]}\'' . "\n\n" .
            '[[["wrb.fr","Fbv4je","[\"garturlres\",\"' . $url . '\",1]",null,null,null,"generic"]]]';
    }

    private function batchexecuteResponseEscaped(string $url): string
    {
        return ')]}\'' . "\n\n" .
            '[[["wrb.fr","Fbv4je","[\\"garturlres\\",\\"' . $url . '\\",1]",null,null,null,"generic"]]]';
    }
}
