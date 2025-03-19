<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NewsCatcher3Service extends NewsCatcherService implements NewsServiceInterface
{
    private const URL = 'https://v3-api.newscatcherapi.com/api/';

    private PendingRequest $request;

    public function __construct()
    {
        parent::__construct();
        $this->request = Http::withHeader('x-api-token', config('services.newscatcher3.key'));
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
                'from_' => now()->subDays((empty($lang) || $lang == self::LANG) ? 2 : 1)->format('Y/m/d'),
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
}
