<?php

namespace App\Services\News;

use App\Services\NewsServiceInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleNewsSource
{
    private const BASE_URL = 'https://news.google.com/rss/search';

    private const LANG_MAP = [
        'uk' => ['hl' => 'uk', 'gl' => 'UA', 'ceid' => 'UA:uk'],
        'en' => ['hl' => 'en-US', 'gl' => 'US', 'ceid' => 'US:en'],
    ];

    public function buildQuery(string $baseQuery, string $lang, array $excludeDomains = []): string
    {
        $when = ($lang === NewsServiceInterface::DEFAULT_LANG || empty($lang))
            ? NewsServiceInterface::LOOKBACK_HOURS_DEFAULT_LANG . 'h'
            : NewsServiceInterface::LOOKBACK_HOURS_OTHER_LANG . 'h';

        $query = $baseQuery . ' when:' . $when;

        foreach ($excludeDomains as $domain) {
            $query .= ' -site:' . $domain;
        }

        return $query;
    }

    public function fetch(string $query, string $lang): array
    {
        $params = self::LANG_MAP[$lang] ?? self::LANG_MAP['en'];

        $url = self::BASE_URL . '?' . http_build_query([
            'q' => $query,
            'hl' => $params['hl'],
            'gl' => $params['gl'],
            'ceid' => $params['ceid'],
        ]);

        Log::info('GoogleNewsSource: fetching ' . $lang . ': ' . $query);

        try {
            $response = Http::timeout(30)->get($url);

            if ($response->status() === 429) {
                Log::warning('GoogleNewsSource: rate limited (429)');
                return [];
            }

            if ($response->status() >= 400) {
                Log::error('GoogleNewsSource error: HTTP ' . $response->status());
                return [];
            }

            // LIBXML_NONET prevents network entity loading; PHP 8.0+ disables external entities by default
            $xml = @simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
            if ($xml === false) {
                Log::error('GoogleNewsSource: failed to parse RSS XML');
                return [];
            }

            $articles = [];
            if (!isset($xml->channel->item)) {
                return [];
            }

            foreach ($xml->channel->item as $item) {
                try {
                    $articles[] = [
                        'title' => (string) $item->title,
                        'link' => (string) $item->link,
                        'published_date' => Carbon::parse((string) $item->pubDate)->format('Y-m-d H:i:s'),
                        'name_source' => $item->source ? (string) $item->source : '',
                        'clean_url' => ($item->source && $item->source['url']) ? (parse_url((string) $item->source['url'], PHP_URL_HOST) ?? '') : '',
                        'language' => $lang,
                        'media' => null,
                    ];
                } catch (\Throwable $e) {
                    Log::warning('GoogleNewsSource: skipping item: ' . $e->getMessage());
                }
            }

            Log::info('GoogleNewsSource: parsed ' . count($articles) . ' articles');

            return $articles;
        } catch (\Throwable $e) {
            Log::error('GoogleNewsSource fetch error: ' . $e->getMessage());
            return [];
        }
    }
}
