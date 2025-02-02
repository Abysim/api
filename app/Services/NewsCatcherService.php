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
        $result = $this->request->get(self::URL . 'search', [
            'q' => $query,
            'lang' => self::LANG,
            'not_sources' => implode(',', self::EXCLUDE_DOMAINS),
            'ranked_only' => 'False',
            'sort_by' => 'date',
            'page_size' => 100,
            'page' => 1,
            'from' => now()->subDays(2)->format('Y/m/d'),
        ]);

        if ($result->status() >= 400) {
            Log::error('NewsCatcher error: ' . $result->body());
            return [];
        }

        sleep(1);

        return array_reverse($result->json()['articles'] ?? []);
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
