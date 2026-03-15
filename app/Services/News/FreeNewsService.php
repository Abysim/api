<?php

namespace App\Services\News;

use App\Services\NewsServiceInterface;
use fivefilters\Readability\Configuration;
use fivefilters\Readability\ParseException;
use fivefilters\Readability\Readability;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

class FreeNewsService implements NewsServiceInterface
{
    use GeneratesSearchQuery;

    private const SEARCH_QUERY_LIMIT = 400;

    private const LANG = NewsServiceInterface::DEFAULT_LANG;

    private GoogleNewsSource $googleSource;
    private GdeltSource $gdeltSource;
    private GoogleNewsUrlDecoder $urlDecoder;
    private array $dnsCache = [];
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

            if (in_array($article['clean_url'] ?? '', NewsServiceInterface::EXCLUDE_DOMAINS, true)) {
                $this->markUrlSeen($article);
                continue;
            }

            if (!empty($keywords) && !$this->titleMatchesKeywords($article['title'], $keywords)) {
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

        // Discard articles older than 1 month before fetching (Google News can resurface old content)
        $cutoff = now()->subMonth()->format('Y-m-d H:i:s');
        $beforeAge = count($filtered);
        $filtered = array_values(array_filter($filtered, fn($a) => $a['published_date'] >= $cutoff));
        $ageFiltered = $beforeAge - count($filtered);
        if ($ageFiltered > 0) {
            Log::info("FreeNews: {$ageFiltered} articles filtered (older than 1 month)");
        }

        $maxEnrich = (int) config('services.news.max_enrich', 30);
        $filtered = array_slice($filtered, 0, $maxEnrich);

        $result = [];
        $titleOnlySkipped = 0;
        foreach ($filtered as $article) {
            $enriched = $this->extractContent($article);

            // Mark URL as seen only if extraction succeeded (content != title fallback)
            if ($this->urlSeenMarker !== null && $enriched['content'] !== $article['title']) {
                ($this->urlSeenMarker)($article['link']);
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

            // --- Fallback chain: fetch content ---
            $content = null;
            $author = null;
            $image = null;

            // Step 1: Raw HTTP + Readability
            try {
                $res = Http::timeout(4)
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
                    $res = Http::timeout(10)
                        ->withHeaders(['X-Target-Selector' => 'article,.entry-content,.post-content,main,[role="main"]'])
                        ->get($jinaUrl);
                    if ($res->status() >= 400) {
                        throw new \Exception('Jina HTTP ' . $res->status());
                    }
                    $jina = $this->parseJinaResponse($res->body());
                    if ($jina['content'] !== null) {
                        $content = $jina['content'];
                        $image = $jina['image'];
                        Log::info('FreeNews: Jina Reader success for ' . $url);
                        Sleep::for(2)->seconds();
                    } else {
                        Log::info('FreeNews: Jina returned too little content for ' . $url);
                    }
                } catch (\Throwable $e) {
                    Log::info('FreeNews: Jina failed for ' . $url . ': ' . $e->getMessage());
                }
            }

            // Step 3: Scrape.do
            if ($content === null) {
                $sdKey = config('scraper.scrapedo_key');
                if (!empty($sdKey)) {
                    try {
                        $res = Http::timeout(15)->get(config('scraper.scrapedo_url'), [
                            'token' => $sdKey,
                            'url' => $url,
                        ]);
                        if ($res->status() >= 400) {
                            throw new \Exception('Scrape.do HTTP ' . $res->status());
                        }
                        $sdHtml = $res->body();
                        if (!empty($sdHtml)) {
                            Log::info('FreeNews: Scrape.do success for ' . $url);
                            $extracted = $this->extractFromHtml($sdHtml, $url);
                            if ($extracted !== null) {
                                $content = $extracted['content'];
                                $author = $extracted['author'];
                                $image = $extracted['image'];
                            }
                        }
                    } catch (\Throwable $e) {
                        Log::info('FreeNews: Scrape.do failed for ' . $url . ': ' . $e->getMessage());
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
                return $this->buildArticleArray($article, $article['title'], $url);
            }

            return $this->buildArticleArray($article, $content, $url, $author, $image);
        } catch (\Throwable $e) {
            Log::warning('FreeNews: content extraction failed for ' . $url . ' (original: ' . $originalUrl . '): ' . $e->getMessage());
            return $this->buildArticleArray($article, $article['title'], $originalUrl);
        }
    }

    private function parseJinaResponse(string $response): array
    {
        $content = $response;

        // Strip Jina metadata headers (Title:, URL Source:, Published Time:, Markdown Content:)
        if (str_contains($content, "\n\n")) {
            $parts = explode("\n\n", $content, 2);
            // Check if first part looks like Jina metadata headers
            if (preg_match('/^(Title:|URL Source:|Published Time:|Markdown Content:)/m', $parts[0])) {
                $content = $parts[1] ?? '';
            }
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
