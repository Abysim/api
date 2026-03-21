<?php

namespace App\Services;

interface NewsServiceInterface
{
    public const DEFAULT_LANG = 'uk';

    public const LOOKBACK_HOURS_DEFAULT_LANG = 50;

    public const LOOKBACK_HOURS_OTHER_LANG = 26;

    public const ARTICLE_FRESHNESS_DAYS = 7;

    public const EXCLUDE_COUNTRIES = ['RU'];

    public const EXCLUDE_DOMAINS = [
        'champion.com.ua',
        'sport.ua',
        'his.edu.vn',
        'newssniffer.co.uk',
        'facebook.com',
        'instagram.com',
        'linkedin.com',
        'reddit.com',
        'espn.com',
    ];

    public function getNews(string $query, ?string $lang = null): array;

    public function getSearchQueryLimit(): int;

    public function generateSearchQuery(array $words, array $excludeWords): string;

    public function getName(): string;
}
