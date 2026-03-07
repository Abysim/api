<?php

namespace App\Services\News;

use App\Services\NewsServiceInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

class GdeltSource
{
    private const BASE_URL = 'https://api.gdeltproject.org/api/v2/doc/doc';

    private const GDELT_LANG_MAP = [
        'uk' => 'ukrainian',
        'en' => 'english',
    ];

    /** GDELT uses FIPS 10-4 country codes, not ISO 3166-1 alpha-2 */
    private const GDELT_COUNTRY_MAP = [
        'RU' => 'RS',
        'BY' => 'BO',
        'CN' => 'CH',
        'KZ' => 'KZ',
    ];

    public function buildQuery(string $baseQuery, string $lang, array $excludeCountries, array $excludeDomains): string
    {
        $gdeltLang = self::GDELT_LANG_MAP[$lang] ?? $lang;
        $query = $baseQuery . ' sourcelang:' . $gdeltLang;

        foreach ($excludeCountries as $country) {
            $fips = self::GDELT_COUNTRY_MAP[$country] ?? $country;
            $query .= ' -sourcecountry:' . $fips;
        }

        foreach ($excludeDomains as $domain) {
            $query .= ' -domain:' . $domain;
        }

        return $query;
    }

    public function fetch(string $query, string $lang): array
    {
        $startDate = ($lang === NewsServiceInterface::DEFAULT_LANG || empty($lang))
            ? now()->subHours(NewsServiceInterface::LOOKBACK_HOURS_DEFAULT_LANG)
            : now()->subHours(NewsServiceInterface::LOOKBACK_HOURS_OTHER_LANG);

        $params = [
            'query' => $query,
            'mode' => 'artlist',
            'maxrecords' => 75,
            'format' => 'json',
            'startdatetime' => $startDate->format('YmdHis'),
            'enddatetime' => now()->format('YmdHis'),
        ];

        Log::info('GdeltSource: fetching ' . $lang . ': ' . $query);

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $response = Http::timeout(30)->get(self::BASE_URL, $params);

                if ($response->status() === 429 && $attempt === 1) {
                    Log::warning('GdeltSource: rate limited (HTTP 429), retrying...');
                    Sleep::for(6)->seconds();
                    continue;
                }

                if ($response->status() >= 500 && $attempt === 1) {
                    Log::warning('GdeltSource: server error ' . $response->status() . ', retrying...');
                    Sleep::for(2)->seconds();
                    continue;
                }

                if ($response->status() >= 400) {
                    Log::error('GdeltSource error: HTTP ' . $response->status());
                    return [];
                }

                $data = $response->json();

                // GDELT returns HTTP 200 with plain text body on rate limit
                if (!is_array($data)) {
                    $body = $response->body();
                    if (str_contains($body, 'limit requests')) {
                        Log::warning('GdeltSource: rate limited by GDELT API' . ($attempt === 1 ? ', retrying...' : ''));
                        if ($attempt === 1) {
                            Sleep::for(6)->seconds();
                            continue;
                        }
                        return [];
                    }
                    Log::info('GdeltSource: no articles returned');
                    return [];
                }

                if (empty($data['articles'])) {
                    Log::info('GdeltSource: no articles returned');
                    return [];
                }

                $articles = [];
                foreach ($data['articles'] as $article) {
                    try {
                        $articles[] = [
                            'title' => $article['title'] ?? '',
                            'link' => $article['url'] ?? '',
                            'published_date' => $this->parseDate($article['seendate'] ?? ''),
                            'name_source' => $article['domain'] ?? parse_url($article['url'] ?? '', PHP_URL_HOST) ?? '',
                            'clean_url' => $article['domain'] ?? parse_url($article['url'] ?? '', PHP_URL_HOST) ?? '',
                            'language' => $lang,
                            'media' => $article['socialimage'] ?? null,
                        ];
                    } catch (\Throwable $e) {
                        Log::warning('GdeltSource: skipping article: ' . $e->getMessage());
                    }
                }

                Log::info('GdeltSource: parsed ' . count($articles) . ' articles');

                return $articles;
            } catch (\Throwable $e) {
                if ($attempt === 1) {
                    Log::warning('GdeltSource fetch error (attempt 1): ' . $e->getMessage());
                    Sleep::for(2)->seconds();
                    continue;
                }
                Log::error('GdeltSource fetch error (attempt 2): ' . $e->getMessage());
                return [];
            }
        }

        return [];
    }

    private function parseDate(string $dateStr): string
    {
        try {
            if (preg_match('/^\d{8}T\d{6}Z$/', $dateStr)) {
                return Carbon::createFromFormat('Ymd\THis\Z', $dateStr)->format('Y-m-d H:i:s');
            }
            return Carbon::parse($dateStr)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            Log::warning('GdeltSource: unparseable date "' . $dateStr . '", falling back to now()');
            return now()->format('Y-m-d H:i:s');
        }
    }
}
