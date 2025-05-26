<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NewsCatcher3Service extends NewsCatcherService implements NewsServiceInterface
{
    private const NEWS_TYPES = [
        'News and Blogs',
        'Educational News',
        'Press Releases',
        'Entertainment and Media News',
        'Health and Medical News',
        'Government and Municipal News',
        'News Aggregators',
        'Local News and Community Events',
        'Blogs and Magazines',
        'Non-Profit and Organization News',
        'Event News',
        'General News Outlets',
        'Travel and Lifestyle',
        'Specific News Type',
        'Pure News Outlet',
        'Other'
    ];
    private const URL = 'https://v3-api.newscatcherapi.com/api/';

    private PendingRequest $request;

    public function __construct()
    {
        parent::__construct();
        $this->request = Http::withHeader('x-api-token', config('services.newscatcher3.key'))->timeout(300);
    }

    public function getNews(string $query, ?string $lang = null): array
    {
        $excludeCountries = self::EXCLUDE_COUNTRIES;
        if (empty($lang) || $lang == self::LANG) {
            $excludeCountries[] = 'KZ';
        }

        $result = [];
        for ($page = 1; $page <= 10; $page++) {
            Log::info('NewsCatcher3 search ' . $lang . ': page: ' . $page . '; query: ' . $query);

            $response = $this->request->get(self::URL . 'search', [
                'q' => $query,
                'lang' => $lang ?? self::LANG,
                'not_countries' => implode(',', $excludeCountries),
                'not_sources' => implode(',', self::EXCLUDE_DOMAINS),
                'ranked_only' => 'False',
                'sort_by' => 'date',
                'page_size' => 1000,
                'page' => $page,
                'from_' => now()->subHours((empty($lang) || $lang == self::LANG) ? 50 : 26)->format('Y/m/d H:i:s'),
                'by_parse_date' => 'True',
                'news_type' => implode(',', self::NEWS_TYPES),
                'word_count_min' => 50,
            ]);

            if ($response->status() >= 400) {
                Log::error('NewsCatcher3 error: ' . $response->body());

                return $result;
            }

            sleep(1);

            $result = array_merge($result, array_reverse($response->json()['articles'] ?? []));

            if ($page >= $response->json()['total_pages'] ?? 0) {
                break;
            }

            unset($response);
            gc_collect_cycles();
        }

        return $result;
    }

    public function subscription(): string
    {
        $response = $this->request->get(self::URL . 'subscription');
        if ($response->status() >= 400) {
            Log::error('NewsCatcher3 subscription error: ' . $response->body());
        }

        return $response->body();
    }
}
