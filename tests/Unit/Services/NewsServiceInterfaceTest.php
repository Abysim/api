<?php

namespace Tests\Unit\Services;

use App\Services\NewsServiceInterface;
use PHPUnit\Framework\TestCase;

class NewsServiceInterfaceTest extends TestCase
{
    public function test_default_lang_is_uk(): void
    {
        $this->assertSame('uk', NewsServiceInterface::DEFAULT_LANG);
    }

    public function test_exclude_countries_contains_ru(): void
    {
        $this->assertSame(['RU'], NewsServiceInterface::EXCLUDE_COUNTRIES);
    }

    public function test_exclude_domains_has_four_entries(): void
    {
        $this->assertCount(4, NewsServiceInterface::EXCLUDE_DOMAINS);
    }

    public function test_exclude_domains_contains_expected_values(): void
    {
        $expected = [
            'champion.com.ua',
            'sport.ua',
            'his.edu.vn',
            'newssniffer.co.uk',
        ];

        $this->assertSame($expected, NewsServiceInterface::EXCLUDE_DOMAINS);
    }
}
