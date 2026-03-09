<?php

namespace App\Services\News;

use App\Helpers\FileHelper;
use App\Services\NewsServiceInterface;
use fivefilters\Readability\Configuration;
use fivefilters\Readability\ParseException;
use fivefilters\Readability\Readability;
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
        $effectiveLang = $lang ?: self::LANG;
        $excludeCountries = NewsServiceInterface::EXCLUDE_COUNTRIES;
        if (empty($lang) || $lang === self::LANG) {
            $excludeCountries[] = 'KZ';
        }

        // === PHASE 1: Fetch metadata from both sources ===
        // Convert exclusion syntax once: ! (used by generateSearchQuery) -> - (used by APIs)
        $apiQuery = str_replace(' !', ' -', $query);
        $articles = [];

        try {
            $googleQuery = $this->googleSource->buildQuery($apiQuery, $effectiveLang, NewsServiceInterface::EXCLUDE_DOMAINS);
            $articles = array_merge($articles, $this->googleSource->fetch($googleQuery, $effectiveLang));
        } catch (\Throwable $e) {
            Log::error('GoogleNewsSource error: ' . $e->getMessage());
        }

        try {
            $gdeltQuery = $this->gdeltSource->buildQuery($apiQuery, $effectiveLang, $excludeCountries, NewsServiceInterface::EXCLUDE_DOMAINS);
            $articles = array_merge($articles, $this->gdeltSource->fetch($gdeltQuery, $effectiveLang));
        } catch (\Throwable $e) {
            Log::error('GdeltSource error: ' . $e->getMessage());
        }

        if (empty($articles)) {
            Log::warning('FreeNews: all sources failed or returned no results for query: ' . $query);
        }

        Log::info('FreeNews: fetched ' . count($articles) . ' article metadata for ' . $effectiveLang);

        // === PHASE 2: Title pre-filter ===
        $keywords = $this->extractKeywords($query);
        $excludeWords = $this->extractExcludeWords($query);
        $filtered = [];
        $seenTitles = [];
        $urlCacheHits = 0;

        foreach ($articles as $article) {
            if (empty($article['title'])) {
                continue;
            }

            // 2.0 URL cache: skip articles whose link was already processed in a recent run
            if ($this->urlSeenChecker !== null && !empty($article['link']) && ($this->urlSeenChecker)($article['link'])) {
                $urlCacheHits++;
                continue;
            }

            // 2a. Domain filter: skip articles from excluded domains
            if (in_array($article['clean_url'] ?? '', NewsServiceInterface::EXCLUDE_DOMAINS, true)) {
                $this->markUrlSeen($article);
                continue;
            }

            // 2b. Keyword relevance: title must contain at least one search keyword
            if (!empty($keywords) && !$this->titleMatchesKeywords($article['title'], $keywords)) {
                $this->markUrlSeen($article);
                continue;
            }

            // 2c. Exclude word filter: reject titles containing exclusion terms
            if (!empty($excludeWords) && $this->titleMatchesExcludeWords($article['title'], $excludeWords)) {
                $this->markUrlSeen($article);
                continue;
            }

            // 2d. Batch dedup: skip exact duplicates via hashmap, then fuzzy via similar_text
            // Note: duplicates are deliberately NOT marked as URL-seen. The "other" variant
            // (which was kept) may fail extraction, and we want the duplicate URL available
            // for retry on the next run rather than permanently cached as processed.
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

        // === PHASE 2.5: Cross-run dedup against DB (if filter provided) ===
        if ($this->titleDedupFilter !== null) {
            $beforeCount = count($filtered);
            $filtered = ($this->titleDedupFilter)($filtered);
            $skipped = $beforeCount - count($filtered);
            if ($skipped > 0) {
                Log::info("FreeNews: DB title dedup removed {$skipped} of {$beforeCount} articles");
            }
        }

        // === PHASE 3: Content extraction ===
        $maxEnrich = (int) config('services.news.max_enrich', 30);
        $filtered = array_slice($filtered, 0, $maxEnrich);

        $result = [];
        $googleDecoded = 0;
        $googleFailed = 0;
        foreach ($filtered as $article) {
            $isGoogleUrl = str_contains($article['link'], 'news.google.com');
            $enriched = $this->extractContent($article);

            // Cache the original URL if content extraction produced real content
            // (not just the title fallback). Failed extractions remain uncached for retry.
            // Edge case: if Readability extracts content that exactly equals the title
            // (extremely unlikely given the 50-char minimum), the article will be retried
            // next run — this is the safe-side failure mode.
            if ($this->urlSeenMarker !== null && $enriched['content'] !== $article['title']) {
                ($this->urlSeenMarker)($article['link']);
            }

            if ($isGoogleUrl) {
                if (!str_contains($enriched['link'], 'news.google.com')) {
                    $googleDecoded++;
                } else {
                    $googleFailed++;
                }
            }
            $result[] = $enriched;
            Sleep::for(1)->second(); // polite crawling delay
        }

        if ($googleDecoded + $googleFailed > 0) {
            $total = $googleDecoded + $googleFailed;
            $rate = $googleDecoded / $total;
            if ($rate < 0.5) {
                Log::warning("FreeNews: Google News decode rate: {$googleDecoded}/{$total} succeeded — decode rate below 50%, protocol may have changed");
            } else {
                Log::info("FreeNews: Google News decode rate: {$googleDecoded}/{$total} succeeded");
            }
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

    /**
     * Extract positive keywords from the query string.
     * Input: "(lion OR lions OR lioness*) !horoscop*"
     * Output: ['lion', 'lions', 'lioness']
     */
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

    /**
     * Extract exclusion words from the query string.
     * Input: "(lion OR lions) !horoscop* !"sea lion" !football*"
     * Output: ['horoscop*', 'sea lion', 'football*']
     */
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

    /**
     * Check if a title contains any of the exclusion words.
     * Supports trailing wildcard (*) for prefix matching.
     */
    private function titleMatchesExcludeWords(string $title, array $excludeWords): bool
    {
        foreach ($excludeWords as $word) {
            if (str_ends_with($word, '*')) {
                $prefix = substr($word, 0, -1);
                if (mb_stripos($title, $prefix) !== false) {
                    return true;
                }
            } else {
                if (mb_stripos($title, $word) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Validate URL targets a public IP (not internal/reserved).
     *
     * Note: TOCTOU/DNS-rebinding risk accepted. The DNS lookup here and the
     * actual HTTP fetch in FileHelper::getUrl() resolve independently, so a
     * malicious domain could theoretically return a public IP here then rebind
     * to 127.0.0.1. This is acceptable because all URLs originate from trusted
     * sources (Google News RSS feed, GDELT API) — not direct user input.
     */
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

    /**
     * Fetch article page and extract full content using Readability.
     * Uses FileHelper::getUrl() which provides ScraperAPI fallback.
     */
    private function extractContent(array $article): array
    {
        $originalUrl = $article['link'];
        $url = $originalUrl;

        try {
            $html = null;

            if (!$this->isUrlSafe($url)) {
                Log::warning('FreeNews: unsafe URL rejected: ' . $url);
                return $this->buildArticleArray($article, $article['title'], $url);
            }

            // Step 1: Decode Google News URLs to real article URLs
            if (str_contains($url, 'news.google.com')) {
                $decoded = $this->urlDecoder->decode($url);
                if ($decoded !== null && !str_contains($decoded, 'news.google.com')) {
                    if (!$this->isUrlSafe($decoded)) {
                        Log::warning('FreeNews: decoded Google URL unsafe: ' . $decoded);
                        return $this->buildArticleArray($article, $article['title'], $originalUrl);
                    }
                    $url = $decoded;
                } else {
                    Log::info('FreeNews: could not decode Google News URL: ' . $url);
                    return $this->buildArticleArray($article, $article['title'], $originalUrl);
                }
            }

            // Step 2: Fetch HTML using FileHelper (direct HTTP + ScraperAPI fallback)
            $html = FileHelper::getUrl($url);

            if (empty($html)) {
                Log::warning('FreeNews: empty HTML for ' . $url);
                return $this->buildArticleArray($article, $article['title'], $url);
            }

            // Cap HTML size at 2MB to prevent OOM from oversized responses
            if (strlen($html) > 2 * 1024 * 1024) {
                $html = substr($html, 0, 2 * 1024 * 1024);
            }

            // Step 3: Extract content using Readability
            $config = new Configuration();
            $readability = new Readability($config);
            $readability->parse($html);

            $content = $readability->getContent();
            if ($content === null) {
                Log::warning('FreeNews: readability returned null content for ' . $url);
                return $this->buildArticleArray($article, $article['title'], $url);
            }
            $content = strip_tags($content);
            $content = trim(preg_replace('/\s+/', ' ', $content));

            $author = $readability->getAuthor();
            $image = $readability->getImage();

            if (empty($content) || mb_strlen($content) < 50) {
                Log::warning('FreeNews: readability extracted too little for ' . $url);
                return $this->buildArticleArray($article, $article['title'], $url);
            }

            return $this->buildArticleArray($article, $content, $url, $author, $image);
        } catch (ParseException $e) {
            Log::warning('FreeNews: readability parse failed for ' . $url . ' (original: ' . $originalUrl . '): ' . $e->getMessage());
            return $this->buildArticleArray($article, $article['title'], $originalUrl);
        } catch (\Throwable $e) {
            Log::warning('FreeNews: content extraction failed for ' . $url . ' (original: ' . $originalUrl . '): ' . $e->getMessage());
            return $this->buildArticleArray($article, $article['title'], $originalUrl);
        }
    }

    private function buildArticleArray(
        array $metadata,
        string $content,
        string $link,
        ?string $author = null,
        ?string $image = null
    ): array {
        $hash = substr(hash('sha256', $link . '|' . $metadata['title']), 0, 32);

        return [
            'title' => $metadata['title'],
            'link' => $link,
            'published_date' => $metadata['published_date'],
            'author' => $author,
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
}
