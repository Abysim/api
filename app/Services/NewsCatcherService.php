<?php

namespace App\Services;

use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NewsCatcherService implements NewsServiceInterface
{
    private const URL = 'https://api.newscatcherapi.com/v2/';

    private const SEARCH_QUERY_LIMIT = 1000;

    private const LANG = 'uk';

    private const EXCLUDE_COUNTRIES = ['RU', 'KZ'];

    const EXCLUDE_DOMAINS = [
        'champion.com.ua',
        'sport.ua',
    ];

    private PendingRequest $request;

    public function __construct()
    {
        $this->request = Http::withHeader('x-api-key', config('services.newscatcher.key'));
    }

    public function getNews($query): array
    {
        $result = [];
        for ($page = 1; $page <= 10; $page++) {
            Log::info('NewsCatcher search: page: ' . $page . '; query: ' . $query);

            $response = $this->request->get(self::URL . 'search', [
                'q' => $query,
                'lang' => self::LANG,
                'not_countries' => implode(',', self::EXCLUDE_COUNTRIES),
                'not_sources' => implode(',', self::EXCLUDE_DOMAINS),
                'ranked_only' => 'False',
                'sort_by' => 'date',
                'page_size' => 100,
                'page' => $page,
                'from' => now()->subDays(2)->format('Y/m/d'),
            ]);

            if ($response->status() >= 400) {
                Log::error('NewsCatcher error: ' . $response->body());

                return $result;
            }

            sleep(1);

            $result = array_merge($result, array_reverse($response->json()['articles'] ?? []));

            if ($page >= $response->json()['total_pages'] ?? 0) {
                break;
            }
        }

        return $result;
    }

    public function getSearchQueryLimit(): int
    {
        return self::SEARCH_QUERY_LIMIT;
    }

    public function generateSearchQuery($words, $excludeWords): string
    {
        $query = '(' . implode(' OR ', $words) . ')';
        foreach ($excludeWords as $exclude) {
            $query .= ' !' . $exclude;
        }

        return $query;
    }

    public function getName(): string
    {
        return 'NewsCatcher';
    }
}
