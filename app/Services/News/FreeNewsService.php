<?php

namespace App\Services\News;

use App\Models\DailyStat;
use App\Services\NewsServiceInterface;
use fivefilters\Readability\Configuration;
use fivefilters\Readability\ParseException;
use fivefilters\Readability\Readability;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

class FreeNewsService implements NewsServiceInterface
{
    use GeneratesSearchQuery;

    private const SEARCH_QUERY_LIMIT = 400;

    /** Domain name prefixes blocked across all TLDs (e.g. 'ubuy' blocks ubuy.gy, ubuy.bf, etc.) */
    private const KEYWORD_BLOCKED_PREFIXES = ['ubuy', 'espn'];

    private const LANG = NewsServiceInterface::DEFAULT_LANG;

    private const DOMAIN_FAIL_THRESHOLD = 3;

    private GoogleNewsSource $googleSource;
    private GdeltSource $gdeltSource;
    private GoogleNewsUrlDecoder $urlDecoder;
    private array $dnsCache = [];
    private array $blockedDomains = [];
    private array $blockedUrlPatterns = [];
    private array $domainFailCounts = [];
    private ?array $cachedExcludedDomains = null;
    private int $lastDecodeTotal = 0;
    private int $lastDecodeSuccess = 0;
    private bool $gdeltRateLimited = false;

    /** @var callable(array): array|null */
    private $titleDedupFilter = null;

    /** @var (callable(string): bool)|null */
    private $urlSeenChecker = null;

    /** @var (callable(string): void)|null */
    private $urlSeenMarker = null;

    public function setTitleDedupFilter(?callable $filter): void
    {
        $this->titleDedupFilter = $filter;
    }

    public function setUrlSeenCache(?callable $checker, ?callable $marker): void
    {
        $this->urlSeenChecker = $checker;
        $this->urlSeenMarker = $marker;
    }

    private function markUrlSeen(array $article): void
    {
        if ($this->urlSeenMarker !== null && !empty($article['link'])) {
            ($this->urlSeenMarker)($article['link']);
        }
    }

    public function __construct(
        GoogleNewsSource $googleSource,
        GdeltSource $gdeltSource,
        GoogleNewsUrlDecoder $urlDecoder
    ) {
        $this->googleSource = $googleSource;
        $this->gdeltSource = $gdeltSource;
        $this->urlDecoder = $urlDecoder;

        $path = resource_path('json/news/blocked_domains.json');
        if (file_exists($path)) {
            $this->blockedDomains = json_decode(file_get_contents($path), true) ?: [];
        }

        $urlPatternsPath = resource_path('json/news/blocked_url_patterns.json');
        if (file_exists($urlPatternsPath)) {
            $this->blockedUrlPatterns = json_decode(file_get_contents($urlPatternsPath), true) ?: [];
        }
    }

    public function getNews(string $query, ?string $lang = null): array
    {
        $this->resetMetrics();
        $effectiveLang = $lang ?: self::LANG;
        $excludeCountries = NewsServiceInterface::EXCLUDE_COUNTRIES;
        if (empty($lang) || $lang === self::LANG) {
            $excludeCountries[] = 'KZ';
        }

        // Convert exclusion syntax: ! (generateSearchQuery) -> - (APIs)
        $apiQuery = str_replace(' !', ' -', $query);
        $articles = [];

        try {
            $googleQuery = $this->googleSource->buildQuery($apiQuery, $effectiveLang, NewsServiceInterface::EXCLUDE_DOMAINS);
            $articles = array_merge($articles, $this->googleSource->fetch($googleQuery, $effectiveLang));
        } catch (\Throwable $e) {
            Log::error('GoogleNewsSource error: ' . $e->getMessage());
        }

        Sleep::for(2)->seconds(); // space out GDELT from Google News to reduce rate limits

        try {
            $gdeltQuery = $this->gdeltSource->buildQuery($apiQuery, $effectiveLang, $excludeCountries, NewsServiceInterface::EXCLUDE_DOMAINS);
            $articles = array_merge($articles, $this->gdeltSource->fetch($gdeltQuery, $effectiveLang));
            $this->gdeltRateLimited = $this->gdeltSource->wasRateLimited();
        } catch (\Throwable $e) {
            Log::error('GdeltSource error: ' . $e->getMessage());
        }

        if (empty($articles)) {
            Log::warning('FreeNews: all sources failed or returned no results for query: ' . $query);
        }

        Log::info('FreeNews: fetched ' . count($articles) . ' article metadata for ' . $effectiveLang);

        // Discard articles older than freshness window before any processing (Google News can resurface old content)
        $cutoff = now()->subDays(NewsServiceInterface::ARTICLE_FRESHNESS_DAYS)->format('Y-m-d H:i:s');
        $beforeAge = count($articles);
        $articles = array_values(array_filter($articles, fn($a) => $a['published_date'] >= $cutoff));
        $ageFiltered = $beforeAge - count($articles);
        if ($ageFiltered > 0) {
            Log::info("FreeNews: {$ageFiltered} articles filtered (older than " . NewsServiceInterface::ARTICLE_FRESHNESS_DAYS . " days)");
        }

        $keywords = $this->extractKeywords($query);
        $excludeWords = $this->extractExcludeWords($query);
        $filtered = [];
        $seenTitles = [];
        $urlCacheHits = 0;

        foreach ($articles as $article) {
            if (empty($article['title'])) {
                continue;
            }

            // Strip source suffix early so dedup comparisons use clean titles
            if (!empty($article['name_source'])) {
                $article['title'] = preg_replace(
                    '/ [-–—]\s*' . preg_quote($article['name_source'], '/') . '$/u',
                    '',
                    $article['title']
                );
            }

            if ($this->urlSeenChecker !== null && !empty($article['link']) && ($this->urlSeenChecker)($article['link'])) {
                $urlCacheHits++;
                continue;
            }

            if ($this->isDomainExcluded($article['clean_url'] ?? '', $article['link'] ?? '')) {
                $this->markUrlSeen($article);
                continue;
            }

            if ($matchedPattern = $this->isUrlPathExcluded($article['link'] ?? '')) {
                Log::info('FreeNews: URL path excluded [' . $matchedPattern . '] for ' . ($article['link'] ?? ''));
                $this->markUrlSeen($article);
                continue;
            }

            if (!empty($keywords) && !$this->titleMatchesKeywords($article['title'], $keywords)) {
                Log::info('FreeNews: keyword mismatch, marking seen: ' . $article['title']);
                $this->markUrlSeen($article);
                continue;
            }

            if (!empty($excludeWords) && $this->titleMatchesExcludeWords($article['title'], $excludeWords)) {
                $this->markUrlSeen($article);
                continue;
            }

            // Duplicates are NOT marked as URL-seen so the other variant can retry next run
            $normalizedTitle = self::normalizeTitle($article['title']);
            if (isset($seenTitles[$normalizedTitle])) {
                continue;
            }
            $dominated = false;
            foreach ($seenTitles as $seen => $_) {
                similar_text($normalizedTitle, $seen, $percent);
                if ($percent >= 70) {
                    $dominated = true;
                    break;
                }
            }
            if ($dominated) {
                continue;
            }

            $seenTitles[$normalizedTitle] = true;
            $filtered[] = $article;
        }

        Log::info('FreeNews: ' . count($filtered) . ' articles passed title pre-filter');

        if ($urlCacheHits > 0) {
            Log::info('FreeNews: ' . $urlCacheHits . ' articles skipped by URL cache');
        }

        if ($this->titleDedupFilter !== null) {
            $beforeCount = count($filtered);
            $filtered = ($this->titleDedupFilter)($filtered);
            $skipped = $beforeCount - count($filtered);
            if ($skipped > 0) {
                Log::info("FreeNews: DB title dedup removed {$skipped} of {$beforeCount} articles");
            }
        }

        $maxEnrich = (int) config('services.news.max_enrich', 30);
        $filtered = array_slice($filtered, 0, $maxEnrich);

        $result = [];
        $titleOnlySkipped = 0;
        foreach ($filtered as $article) {
            $enriched = $this->extractContent($article);

            // Mark decoded URL as seen only if extraction succeeded (content != title fallback)
            if ($enriched['content'] !== $article['title']) {
                $this->markUrlSeen($enriched);
            }

            // Skip articles where content extraction failed (content == title fallback)
            if ($enriched['content'] === $article['title']) {
                $titleOnlySkipped++;
                continue;
            }

            $result[] = $enriched;
            Sleep::for(1)->second(); // polite crawling delay
        }

        if ($this->lastDecodeTotal > 0) {
            $rate = $this->lastDecodeSuccess / $this->lastDecodeTotal;
            if ($rate < 0.5) {
                Log::warning("FreeNews: Google News decode rate: {$this->lastDecodeSuccess}/{$this->lastDecodeTotal} succeeded — decode rate below 50%, protocol may have changed");
            } else {
                Log::info("FreeNews: Google News decode rate: {$this->lastDecodeSuccess}/{$this->lastDecodeTotal} succeeded");
            }
        }

        if ($titleOnlySkipped > 0) {
            Log::info('FreeNews: ' . $titleOnlySkipped . ' articles skipped (title-only content)');
        }

        // Sort oldest first (matching NewsCatcher's array_reverse pattern)
        usort($result, fn($a, $b) => $a['published_date'] <=> $b['published_date']);

        return $result;
    }

    public function getSearchQueryLimit(): int
    {
        return self::SEARCH_QUERY_LIMIT;
    }

    public function getName(): string
    {
        return 'FreeNews';
    }

    /** Input: "(lion OR lions OR lioness*) !horoscop*" → ['lion', 'lions', 'lioness'] */
    private function extractKeywords(string $query): array
    {
        // Assumes generateSearchQuery() produces a single parenthesized group
        if (preg_match('/\((.+?)\)/', $query, $m)) {
            $inner = $m[1];
            return array_filter(
                array_map(fn($w) => trim(trim($w), '*"'), preg_split('/\s+OR\s+/i', $inner))
            );
        }

        return [];
    }

    private function titleMatchesKeywords(string $title, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (mb_stripos($title, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /** Input: "(lion OR lions) !horoscop* !"sea lion" !football*" → ['horoscop*', 'sea lion', 'football*'] */
    private function extractExcludeWords(string $query): array
    {
        preg_match_all('/!("([^"]+)"|(\S+))/', $query, $matches);

        $excludeWords = [];
        foreach ($matches[0] as $i => $fullMatch) {
            if (!empty($matches[2][$i])) {
                $excludeWords[] = $matches[2][$i];
            } else {
                $excludeWords[] = $matches[3][$i];
            }
        }

        return $excludeWords;
    }

    private function titleMatchesExcludeWords(string $title, array $excludeWords): bool
    {
        foreach ($excludeWords as $word) {
            if (mb_stripos($title, rtrim($word, '*')) !== false) {
                return true;
            }
        }

        return false;
    }

    /** Check if domain (or link host) matches any excluded domain, including subdomains and keyword prefixes. */
    private function isDomainExcluded(string $cleanUrl, string $link): bool
    {
        $domains = array_filter([$cleanUrl, parse_url($link, PHP_URL_HOST) ?: '']);

        foreach ($domains as $domain) {
            $domain = strtolower($domain);
            foreach ($this->allExcludedDomains() as $excluded) {
                if ($domain === $excluded || str_ends_with($domain, '.' . $excluded)) {
                    return true;
                }
            }
            foreach (self::KEYWORD_BLOCKED_PREFIXES as $prefix) {
                if (str_starts_with($domain, $prefix . '.') || str_contains($domain, '.' . $prefix . '.')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if URL path contains any blocked pattern (e.g. CMS exploit paths).
     * Returns the matched pattern string, or null if no match.
     * Path is URL-decoded and lowercased before matching; patterns are already lowercase.
     */
    private function isUrlPathExcluded(string $url): ?string
    {
        $path = strtolower(rawurldecode(parse_url($url, PHP_URL_PATH) ?: ''));
        foreach ($this->blockedUrlPatterns as $pattern) {
            if (str_contains($path, $pattern)) {
                return $pattern;
            }
        }
        return null;
    }

    private function allExcludedDomains(): array
    {
        return $this->cachedExcludedDomains ??= array_merge(
            NewsServiceInterface::EXCLUDE_DOMAINS,
            $this->blockedDomains
        );
    }

    /** Validate URL targets a public IP. TOCTOU risk accepted: URLs are from trusted RSS/API sources. */
    private function isUrlSafe(string $url): bool
    {
        $parsed = parse_url($url);
        if (!$parsed || !in_array($parsed['scheme'] ?? '', ['http', 'https'], true)) {
            return false;
        }
        $host = $parsed['host'] ?? '';
        if (empty($host)) {
            return false;
        }

        if (isset($this->dnsCache[$host])) {
            return $this->dnsCache[$host];
        }

        // Check IPv4 (A records)
        $ip = gethostbyname($host);
        if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->dnsCache[$host] = false; // DNS resolution failed; fail closed
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return $this->dnsCache[$host] = false;
        }

        // Check IPv6 (AAAA records) — block private/reserved IPv6 addresses
        try {
            $records = @dns_get_record($host, DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    $ipv6 = $record['ipv6'] ?? null;
                    if ($ipv6 !== null && filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                        return $this->dnsCache[$host] = false;
                    }
                }
            }
        } catch (\Throwable) {
            // dns_get_record() failed; fall through — IPv4 check already passed
        }

        return $this->dnsCache[$host] = true;
    }

    public static function normalizeTitle(string $title): string
    {
        $title = mb_strtolower($title);
        $title = preg_replace('/[^\p{L}\p{N}\s]/u', '', $title);

        return trim(preg_replace('/\s+/', ' ', $title));
    }

    private function extractContent(array $article): array
    {
        $originalUrl = $article['link'];
        $url = $originalUrl;

        try {
            if (!$this->isUrlSafe($url)) {
                Log::warning('FreeNews: unsafe URL rejected: ' . $url);
                return $this->buildArticleArray($article, $article['title'], $url);
            }

            if (str_contains($url, 'news.google.com')) {
                $decoded = $this->urlDecoder->decode($url);
                if ($decoded !== null && !str_contains($decoded, 'news.google.com')
                    && (str_starts_with($decoded, 'http://') || str_starts_with($decoded, 'https://'))) {
                    $this->lastDecodeTotal++;
                    $this->lastDecodeSuccess++;
                    if (!$this->isUrlSafe($decoded)) {
                        Log::warning('FreeNews: decoded Google URL unsafe: ' . $decoded);
                        return $this->buildArticleArray($article, $article['title'], $originalUrl);
                    }
                    $url = $decoded;
                } else {
                    $this->lastDecodeTotal++;
                    Log::info('FreeNews: could not decode Google News URL: ' . $url);
                    return $this->buildArticleArray($article, $article['title'], $originalUrl);
                }
            }

            // Post-decode filtering: catch junk URLs hidden behind Google News redirects
            if ($this->isDomainExcluded('', $url)) {
                Log::info('FreeNews: resolved domain excluded: ' . $url);
                return $this->buildArticleArray($article, $article['title'], $originalUrl);
            }
            if ($matchedPattern = $this->isUrlPathExcluded($url)) {
                Log::info('FreeNews: resolved URL path excluded [' . $matchedPattern . '] for ' . $url);
                return $this->buildArticleArray($article, $article['title'], $originalUrl);
            }

            // --- Auto-skip domains that consistently fail all fetch methods ---
            $domainForTracking = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
            if ($domainForTracking && ($this->domainFailCounts[$domainForTracking] ?? 0) >= self::DOMAIN_FAIL_THRESHOLD) {
                Log::info('FreeNews: auto-skipped (domain failed ' . $this->domainFailCounts[$domainForTracking] . ' URLs): ' . $url);
                return $this->buildArticleArray($article, $article['title'], $url);
            }

            // --- Fallback chain: fetch content ---
            $content = null;
            $author = null;
            $image = null;

            // Step 1: Raw HTTP + Readability
            try {
                $res = Http::timeout(8)
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'])
                    ->get($url);
                if ($res->status() >= 400) {
                    throw new \Exception('HTTP ' . $res->status());
                }
                $rawHtml = $res->body();
                if (!empty($rawHtml)) {
                    Log::info('FreeNews: raw HTTP success for ' . $url);
                    $extracted = $this->extractFromHtml($rawHtml, $url);
                    if ($extracted !== null) {
                        $content = $extracted['content'];
                        $author = $extracted['author'];
                        $image = $extracted['image'];
                        Cache::increment(DailyStat::cacheKey('fetch_raw_http'));
                    } else {
                        Log::info('FreeNews: raw HTTP readability failed, trying fallback services for ' . $url);
                    }
                }
            } catch (\Throwable $e) {
                Log::info('FreeNews: raw HTTP failed for ' . $url . ': ' . $e->getMessage());
            }

            // Step 2: Jina Reader
            if ($content === null) {
                try {
                    $jinaUrl = config('scraper.jina_url', 'https://r.jina.ai/') . $url;
                    $res = Http::timeout(35)
                        ->withHeaders([
                            'X-Target-Selector' => 'article,.entry-content,.post-content,main,[role="main"]',
                            'X-Remove-Selector' => 'nav,footer,.sidebar,#ads,.cookie-banner,.related-articles',
                            'X-Wait-For-Selector' => 'article',
                            'X-No-Cache' => 'true',
                            'X-Timeout' => '30',
                        ])
                        ->get($jinaUrl);
                    if ($res->status() >= 400) {
                        throw new \Exception('Jina HTTP ' . $res->status());
                    }
                    $jina = $this->parseJinaResponse($res->body());
                    if ($jina['content'] !== null) {
                        $content = $jina['content'];
                        $image = $jina['image'];
                        Cache::increment(DailyStat::cacheKey('fetch_jina'));
                        Log::info('FreeNews: Jina Reader success for ' . $url);
                        Sleep::for(2)->seconds();
                    } else {
                        Log::info('FreeNews: Jina returned no usable content for ' . $url);
                    }
                } catch (\Throwable $e) {
                    Log::info('FreeNews: Jina failed for ' . $url . ': ' . $e->getMessage());
                }
            }

            // Step 3: Diffbot Article API (5 RPM free tier)
            if ($content === null) {
                $diffbotToken = config('scraper.diffbot_token');
                if (!empty($diffbotToken)) {
                    try {
                        $res = Http::timeout(15)->get(config('scraper.diffbot_url'), [
                            'token' => $diffbotToken,
                            'url' => $url,
                        ]);
                        if ($res->status() === 429) {
                            Log::info('FreeNews: Diffbot rate limited for ' . $url);
                        } elseif ($res->status() >= 400) {
                            throw new \Exception('Diffbot HTTP ' . $res->status());
                        } else {
                            $diffbot = $res->json();
                            $obj = $diffbot['objects'][0] ?? null;
                            if ($obj && !empty($obj['text']) && mb_strlen($obj['text']) >= 200) {
                                $content = trim(preg_replace('/\s+/', ' ', $obj['text']));
                                $author = $obj['author'] ?? null;
                                $image = $obj['images'][0]['url'] ?? null;
                                Cache::increment(DailyStat::cacheKey('fetch_diffbot'));
                                Log::info('FreeNews: Diffbot success for ' . $url);
                            } else {
                                Log::info('FreeNews: Diffbot returned no usable content for ' . $url);
                            }
                            Sleep::for(12)->seconds();
                        }
                    } catch (\Throwable $e) {
                        Log::info('FreeNews: Diffbot failed for ' . $url . ': ' . $e->getMessage());
                    }
                }
            }

            // Step 4: ScraperAPI (paid, last resort)
            if ($content === null) {
                $scraperKey = config('scraper.key');
                if (!empty($scraperKey)) {
                    try {
                        $res = Http::timeout(30)->get(config('scraper.url'), [
                            'api_key' => $scraperKey,
                            'url' => $url,
                            'country_code' => 'eu',
                            'device_type' => 'desktop',
                        ]);
                        if ($res->status() >= 400) {
                            throw new \Exception('ScraperAPI HTTP ' . $res->status());
                        }
                        $saHtml = $res->body();
                        if (!empty($saHtml)) {
                            Log::info('FreeNews: ScraperAPI success for ' . $url);
                            $extracted = $this->extractFromHtml($saHtml, $url);
                            if ($extracted !== null) {
                                $content = $extracted['content'];
                                $author = $extracted['author'];
                                $image = $extracted['image'];
                                Cache::increment(DailyStat::cacheKey('fetch_scraperapi'));
                            } else {
                                Log::info('FreeNews: ScraperAPI HTML fetched but extraction failed for ' . $url);
                            }
                        }
                    } catch (\Throwable $e) {
                        Log::warning('FreeNews: ScraperAPI (last resort) failed for ' . $url . ': ' . $e->getMessage());
                    }
                }
            }

            // All methods exhausted
            if ($content === null) {
                Log::warning('FreeNews: all fetch methods failed for ' . $url);
                if ($domainForTracking) {
                    $this->domainFailCounts[$domainForTracking] = ($this->domainFailCounts[$domainForTracking] ?? 0) + 1;
                    if ($this->domainFailCounts[$domainForTracking] === self::DOMAIN_FAIL_THRESHOLD) {
                        Log::warning('FreeNews: domain auto-blocked for this run (failed ' . self::DOMAIN_FAIL_THRESHOLD . ' URLs): ' . $domainForTracking);
                    }
                }
                return $this->buildArticleArray($article, $article['title'], $url);
            }

            return $this->buildArticleArray($article, $content, $url, $author, $image);
        } catch (\Throwable $e) {
            if (isset($domainForTracking) && $domainForTracking) {
                $this->domainFailCounts[$domainForTracking] = ($this->domainFailCounts[$domainForTracking] ?? 0) + 1;
            }
            Log::warning('FreeNews: content extraction failed for ' . $url . ' (original: ' . $originalUrl . '): ' . $e->getMessage());
            return $this->buildArticleArray($article, $article['title'], $originalUrl);
        }
    }

    private function parseJinaResponse(string $response): array
    {
        // Bail out early if the target URL returned an error
        if (str_contains($response, 'Warning: Target URL returned error')) {
            return ['content' => null, 'image' => null];
        }

        $content = $response;

        // Strip Jina metadata headers line by line (handles missing \n\n separators)
        $lines = explode("\n", $content);
        $contentStart = 0;
        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if (preg_match('/^(Title:|URL Source:|Published Time:|Markdown Content:|Warning:)/', $trimmed)) {
                $contentStart = $i + 1;
            } else {
                break;
            }
        }
        if ($contentStart > 0) {
            $content = implode("\n", array_slice($lines, $contentStart));
        }

        // Extract first image URL from markdown before stripping
        $image = null;
        if (preg_match('/!\[.*?\]\((https?:\/\/[^\)]+)\)/', $content, $m)) {
            $image = $m[1];
        }

        // Clean: strip markdown link/image syntax, any residual HTML tags, normalize whitespace
        $content = preg_replace('/!?\[([^\]]*)\]\([^\)]+\)/', '$1', $content);
        $content = strip_tags($content);
        $content = trim(preg_replace('/\s+/', ' ', $content));

        // Minimum content requirement
        if (mb_strlen($content) < 200) {
            return ['content' => null, 'image' => null];
        }

        if (!$this->isContentUsable($content)) {
            return ['content' => null, 'image' => null];
        }

        return ['content' => $content, 'image' => $image];
    }

    private function extractFromHtml(string $html, string $url): ?array
    {
        if (strlen($html) > 2 * 1024 * 1024) {
            $html = substr($html, 0, 2 * 1024 * 1024);
        }

        try {
            $config = new Configuration();
            $config->setSubstituteEntities(true);
            $readability = new Readability($config);
            $readability->parse($html);

            $content = $readability->getContent();
            if ($content === null) {
                return null;
            }
            $content = strip_tags($content);
            $content = trim(preg_replace('/\s+/', ' ', $content));

            if (empty($content) || mb_strlen($content) < 200) {
                return null;
            }

            if (!$this->isContentUsable($content)) {
                Log::info('FreeNews: extracted content is junk for ' . $url);
                return null;
            }

            return [
                'content' => $content,
                'author' => $readability->getAuthor(),
                'image' => $readability->getImage(),
            ];
        } catch (ParseException $e) {
            Log::warning('FreeNews: readability parse failed for ' . $url . ': ' . $e->getMessage());
            return null;
        }
    }

    private function isContentUsable(string $content): bool
    {
        // Jina Reader metadata leaked into content
        if (preg_match('/^(URL Source:|Markdown Content:)/', $content)) {
            return false;
        }

        // Cookie consent / GDPR banners extracted as article content
        if (preg_match('/^Powered by\b.*?(GDPR|Cookie)/i', $content)) {
            return false;
        }
        if (preg_match('/^(We use cookies|This website uses cookies|This site uses cookies)/i', $content)) {
            return false;
        }

        // Paywall / login walls
        if (preg_match('/^(Subscribe to (read|continue)|Please sign in|Please log in|Create an account to)/i', $content)) {
            return false;
        }

        // JS-required / bot-detection pages
        if (preg_match('/^(Please enable JavaScript|Enable JavaScript|You need to enable JavaScript)/i', $content)) {
            return false;
        }

        // Cookie consent variants
        if (preg_match('/^(Before you continue|Accept cookies|Cookie Notice|By continuing you agree)/i', $content)) {
            return false;
        }

        return true;
    }

    private function buildArticleArray(
        array $metadata,
        string $content,
        string $link,
        ?string $author = null,
        ?string $image = null
    ): array {
        $title = $metadata['title'];
        if (!empty($metadata['name_source'])) {
            $title = preg_replace('/ [-–—]\s*' . preg_quote($metadata['name_source'], '/') . '$/u', '', $title);
        }

        $hash = substr(hash('sha256', $link . '|' . $metadata['title']), 0, 32);

        return [
            'title' => $title,
            'link' => $link,
            'published_date' => $metadata['published_date'],
            'author' => $author ?? '',
            'content' => $content,
            'summary' => Str::limit($content, 500),
            'id' => $hash,
            '_id' => $hash,
            'language' => $metadata['language'],
            'name_source' => $metadata['name_source'],
            'rights' => null,
            'clean_url' => $metadata['clean_url'] ?? '',
            'domain_url' => parse_url($link, PHP_URL_HOST),
            'media' => $image ?? $metadata['media'] ?? null,
        ];
    }

    private function resetMetrics(): void
    {
        $this->lastDecodeTotal = 0;
        $this->lastDecodeSuccess = 0;
        $this->gdeltRateLimited = false;
    }

    public function getLastDecodeTotal(): int
    {
        return $this->lastDecodeTotal;
    }

    public function getLastDecodeSuccess(): int
    {
        return $this->lastDecodeSuccess;
    }

    public function wasGdeltRateLimited(): bool
    {
        return $this->gdeltRateLimited;
    }
}
