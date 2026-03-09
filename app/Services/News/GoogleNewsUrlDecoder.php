<?php

namespace App\Services\News;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleNewsUrlDecoder
{
    private const BATCHEXECUTE_URL = 'https://news.google.com/_/DotsSplashUi/data/batchexecute';

    private const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    private const CACHE_KEY = 'google_news_consent_cookie';

    private const CACHE_TTL_DAYS = 30;

    /**
     * Hardcoded fallback SOCS cookie for when cache is empty and refresh fails.
     * This value bypasses the EU consent redirect on news.google.com.
     */
    private const FALLBACK_CONSENT_COOKIE = 'SOCS=CAISNQgDEitib3FfaWRlbnRpdHlmcm9udGVuZHVpc2VydmVyXzIwMjMwODI5LjA3X3AxGgJlbiACGgYIgJr2pwY';

    /** Prevents infinite retry when cookie refresh is attempted */
    private bool $cookieRefreshAttempted = false;

    /**
     * Decode a Google News URL to the real article URL.
     * Returns the decoded URL, or null if decoding fails.
     * Non-Google-News URLs are returned as-is.
     */
    public function decode(string $url): ?string
    {
        $this->cookieRefreshAttempted = false;
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';

        if (!str_contains($host, 'news.google.com')) {
            return $url;
        }

        $path = $parsed['path'] ?? '';
        $segments = explode('/', trim($path, '/'));

        // URL format: /rss/articles/{base64} or /articles/{base64}
        $articleId = null;
        $segmentCount = count($segments);
        for ($i = 0; $i < $segmentCount - 1; $i++) {
            if ($segments[$i] === 'articles' && isset($segments[$i + 1])) {
                $articleId = $segments[$i + 1];
                break;
            }
        }

        if (empty($articleId)) {
            Log::warning('GoogleNewsUrlDecoder: no article ID found in URL: ' . $url);
            return null;
        }

        // Try legacy base64 decode first (CBMi-prefixed URLs)
        $legacyUrl = $this->decodeLegacy($articleId);
        if ($legacyUrl !== null) {
            return $legacyUrl;
        }

        // Fall back to batchexecute API for newer URLs
        return $this->decodeBatchExecute($articleId);
    }

    /**
     * Get the consent cookie from cache, falling back to hardcoded value.
     */
    private function getConsentCookie(): string
    {
        return Cache::get(self::CACHE_KEY, self::FALLBACK_CONSENT_COOKIE);
    }

    /**
     * Attempt to obtain a fresh SOCS consent cookie from Google.
     * POSTs to the consent acceptance endpoint and extracts the cookie from headers.
     */
    private function refreshConsentCookie(): ?string
    {
        try {
            $response = Http::asForm()
                ->withHeaders([
                    'User-Agent' => self::USER_AGENT,
                ])
                ->withOptions([
                    'allow_redirects' => false,
                ])
                ->timeout(10)
                ->post('https://consent.google.com/save', [
                    'gl' => 'US',
                    'm' => '0',
                    'pc' => 'n',
                    'x' => '6',
                    'hl' => 'en',
                    'src' => '2',
                    'continue' => 'https://news.google.com/',
                    'set_eom' => 'true',
                ]);

            $socs = $this->extractSocsCookie($response);
            if ($socs !== null) {
                Cache::put(self::CACHE_KEY, $socs, now()->addDays(self::CACHE_TTL_DAYS));
                Log::info('GoogleNewsUrlDecoder: consent cookie refreshed successfully');
                return $socs;
            }

            Log::warning('GoogleNewsUrlDecoder: consent refresh response had no SOCS cookie');
            return null;
        } catch (\Throwable $e) {
            Log::warning('GoogleNewsUrlDecoder: consent cookie refresh failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract the SOCS cookie value from HTTP response Set-Cookie headers.
     */
    private function extractSocsCookie($response): ?string
    {
        $cookies = $response->toPsrResponse()->getHeader('Set-Cookie');
        foreach ($cookies as $cookie) {
            if (preg_match('/^SOCS=([^;]+)/', $cookie, $match)) {
                return 'SOCS=' . $match[1];
            }
        }

        return null;
    }

    /**
     * Legacy decoding: base64-decode the article ID and extract the embedded URL.
     * Works for older Google News URLs where the protobuf contains the URL directly.
     */
    private function decodeLegacy(string $articleId): ?string
    {
        try {
            // Google uses URL-safe base64: replace - with + and _ with /
            $base64 = strtr($articleId, '-_', '+/');
            // Fix padding
            $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);

            $decoded = base64_decode($base64, true);
            if ($decoded === false || strlen($decoded) < 4) {
                return null;
            }

            // Protobuf prefix: [0x08, 0x13, 0x22]
            $prefix = "\x08\x13\x22";
            if (str_starts_with($decoded, $prefix)) {
                $decoded = substr($decoded, strlen($prefix));
            }

            // Protobuf suffix: [0xd2, 0x01, 0x00]
            $suffix = "\xd2\x01\x00";
            if (str_ends_with($decoded, $suffix)) {
                $decoded = substr($decoded, 0, -strlen($suffix));
            }

            if (strlen($decoded) < 2) {
                return null;
            }

            // Parse length-prefixed URL (protobuf varint encoding)
            $len = ord($decoded[0]);
            if ($len >= 0x80) {
                // Two-byte varint: low 7 bits of first byte + second byte shifted
                $len = ($len & 0x7F) | (ord($decoded[1]) << 7);
                $str = substr($decoded, 2, $len);
            } else {
                $str = substr($decoded, 1, $len);
            }

            // Verify we got a valid URL
            if (!empty($str) && (str_starts_with($str, 'http://') || str_starts_with($str, 'https://'))) {
                return $str;
            }

            return null;
        } catch (\Throwable $e) {
            Log::debug('GoogleNewsUrlDecoder: legacy decode failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Decode via Google's batchexecute API endpoint.
     * Step 1: Fetch article page to extract signature and timestamp.
     * Step 2: POST to batchexecute with those params to get the real URL.
     */
    private function decodeBatchExecute(string $articleId): ?string
    {
        try {
            // Step 1: Fetch the article page to get signature + timestamp
            $params = $this->fetchDecodingParams($articleId);
            if ($params === null) {
                return null;
            }

            // Step 2: Use params in batchexecute request
            $payload = $this->buildBatchExecutePayload(
                $params['articleId'],
                $params['timestamp'],
                $params['signature']
            );

            $response = Http::asForm()
                ->withHeaders([
                    'Referer' => 'https://news.google.com/',
                    'User-Agent' => self::USER_AGENT,
                ])
                ->timeout(10)
                ->post(self::BATCHEXECUTE_URL . '?rpcids=Fbv4je', [
                    'f.req' => $payload,
                ]);

            if ($response->failed()) {
                Log::warning('GoogleNewsUrlDecoder: batchexecute HTTP ' . $response->status());
                return null;
            }

            return $this->parseBatchExecuteResponse($response->body());
        } catch (\Throwable $e) {
            Log::warning('GoogleNewsUrlDecoder: batchexecute failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch the Google News article page and extract decoding parameters.
     * Tries /articles/ first, falls back to /rss/articles/.
     * If extraction fails, attempts a one-time cookie refresh and retries.
     */
    private function fetchDecodingParams(string $articleId): ?array
    {
        $cookie = $this->getConsentCookie();
        $params = '?hl=en-US&gl=US&ceid=US:en';
        $urls = [
            'https://news.google.com/articles/' . $articleId . $params,
            'https://news.google.com/rss/articles/' . $articleId . $params,
        ];

        foreach ($urls as $url) {
            try {
                $response = Http::withHeaders([
                    'User-Agent' => self::USER_AGENT,
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Cookie' => $cookie,
                ])->timeout(10)->get($url);

                if ($response->failed()) {
                    continue;
                }

                $html = $response->body();

                // Extract data-n-a-sg (signature) and data-n-a-ts (timestamp)
                if (preg_match('/data-n-a-sg="([^"]+)"/', $html, $sgMatch)
                    && preg_match('/data-n-a-ts="([^"]+)"/', $html, $tsMatch)) {

                    // Optionally extract data-n-a-id for the canonical article ID
                    $id = $articleId;
                    if (preg_match('/data-n-a-id="([^"]+)"/', $html, $idMatch)) {
                        $id = $idMatch[1];
                    }

                    return [
                        'articleId' => $id,
                        'signature' => $sgMatch[1],
                        'timestamp' => $tsMatch[1],
                    ];
                }
            } catch (\Throwable $e) {
                Log::debug('GoogleNewsUrlDecoder: failed to fetch params from ' . $url . ': ' . $e->getMessage());
            }
        }

        // Cookie may be expired — try refreshing once and retry
        if (!$this->cookieRefreshAttempted) {
            $this->cookieRefreshAttempted = true;
            $newCookie = $this->refreshConsentCookie();
            if ($newCookie !== null) {
                Log::info('GoogleNewsUrlDecoder: retrying fetchDecodingParams with refreshed cookie');
                return $this->fetchDecodingParams($articleId);
            }
        }

        Log::warning('GoogleNewsUrlDecoder: could not extract decoding params for ' . $articleId);
        return null;
    }

    private function buildBatchExecutePayload(string $articleId, string $timestamp, string $signature): string
    {
        // The inner payload is reverse-engineered from Google's DotsSplashUi batchexecute RPC.
        // Magic numbers: locale config, feature flags, and session identifiers that Google's
        // frontend embeds. These may need updating if Google changes their batchexecute protocol.
        // Source: https://github.com/nicehash/NiceHashQuickMiner (similar approach, public reference)
        $innerPayload = '["garturlreq",[["en-US","US",["FINANCE_TOP_INDICES","WEB_TEST_1_0_0"],null,null,1,1,"US:en",null,180,null,null,null,null,null,0,null,null,[1608992183,723341000]],"en-US","US",1,[2,3,4,8],1,0,"655000234",0,0,null,0],"'
            . $articleId . '",' . $timestamp . ',"' . $signature . '"]';

        return json_encode([[['Fbv4je', $innerPayload, null, 'generic']]]);
    }

    private function parseBatchExecuteResponse(string $body): ?string
    {
        // Response format: ["garturlres","<actual_url>", (or with escaped quotes)
        // Match the header variant and use the corresponding end delimiter.
        $header = '["garturlres","';
        $endDelimiter = '",';
        $pos = strpos($body, $header);

        if ($pos === false) {
            // Try escaped variant: [\"garturlres\",\"...\",
            $header = '[\"garturlres\",\"';
            $endDelimiter = '\",';
            $pos = strpos($body, $header);
        }

        if ($pos === false) {
            Log::error('GoogleNewsUrlDecoder: garturlres not found in response');
            return null;
        }

        $start = substr($body, $pos + strlen($header));
        $endQuote = strpos($start, $endDelimiter);

        if ($endQuote === false) {
            Log::error('GoogleNewsUrlDecoder: could not find end of URL in response');
            return null;
        }

        $url = substr($start, 0, $endQuote);

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return null;
    }
}
