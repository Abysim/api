<?php

namespace Tests\Unit\Services\News;

use App\Services\News\GoogleNewsSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleNewsSourceTest extends TestCase
{
    private GoogleNewsSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = new GoogleNewsSource();
    }

    // -------------------------------------------------------------------------
    // buildQuery
    // -------------------------------------------------------------------------

    public function test_build_query_appends_50h_window_for_uk_language(): void
    {
        $result = $this->source->buildQuery('Київ новини', 'uk');

        $this->assertStringEndsWith(' when:50h', $result);
    }

    public function test_build_query_appends_26h_window_for_en_language(): void
    {
        $result = $this->source->buildQuery('Ukraine news', 'en');

        $this->assertStringEndsWith(' when:26h', $result);
    }

    public function test_build_query_appends_26h_window_for_unknown_language(): void
    {
        $result = $this->source->buildQuery('nachrichten', 'de');

        $this->assertStringEndsWith(' when:26h', $result);
    }

    public function test_build_query_passes_through_negation_syntax_unchanged(): void
    {
        // Negation conversion (! -> -) is now done by FreeNewsService before calling buildQuery
        $result = $this->source->buildQuery('Ukraine -Russia news', 'en');

        $this->assertStringContainsString(' -Russia', $result);
    }

    public function test_build_query_preserves_base_query_text(): void
    {
        $result = $this->source->buildQuery('Ukraine news', 'en');

        $this->assertStringStartsWith('Ukraine news', $result);
    }

    // -------------------------------------------------------------------------
    // buildQuery – domain exclusions
    // -------------------------------------------------------------------------

    public function test_build_query_appends_site_exclusions_when_domains_provided(): void
    {
        $result = $this->source->buildQuery('lion when:26h', 'en', ['champion.com.ua', 'sport.ua']);

        $this->assertStringContainsString(' -site:champion.com.ua', $result);
        $this->assertStringContainsString(' -site:sport.ua', $result);
    }

    public function test_build_query_does_not_append_site_exclusions_for_empty_array(): void
    {
        $result = $this->source->buildQuery('lion', 'en', []);

        $this->assertStringNotContainsString('-site:', $result);
    }

    public function test_build_query_without_domains_parameter_behaves_identically(): void
    {
        $withEmpty = $this->source->buildQuery('lion', 'en', []);
        $without = $this->source->buildQuery('lion', 'en');

        $this->assertSame($withEmpty, $without);
    }

    // -------------------------------------------------------------------------
    // fetch – happy path
    // -------------------------------------------------------------------------

    public function test_fetch_returns_correct_article_structure_for_valid_rss(): void
    {
        Http::fake([
            'news.google.com/*' => Http::response($this->validRssXml(), 200),
        ]);

        $articles = $this->source->fetch('Ukraine news when:26h', 'en');

        $this->assertCount(2, $articles);
    }

    public function test_fetch_maps_title_correctly(): void
    {
        Http::fake([
            'news.google.com/*' => Http::response($this->validRssXml(), 200),
        ]);

        $articles = $this->source->fetch('Ukraine news when:26h', 'en');

        $this->assertSame('Article One Title', $articles[0]['title']);
        $this->assertSame('Article Two Title', $articles[1]['title']);
    }

    public function test_fetch_maps_link_correctly(): void
    {
        Http::fake([
            'news.google.com/*' => Http::response($this->validRssXml(), 200),
        ]);

        $articles = $this->source->fetch('Ukraine news when:26h', 'en');

        $this->assertSame('https://example.com/article-one', $articles[0]['link']);
        $this->assertSame('https://example.com/article-two', $articles[1]['link']);
    }

    public function test_fetch_maps_published_date_as_formatted_datetime(): void
    {
        Http::fake([
            'news.google.com/*' => Http::response($this->validRssXml(), 200),
        ]);

        $articles = $this->source->fetch('Ukraine news when:26h', 'en');

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $articles[0]['published_date']);
        $this->assertSame('2024-01-15 10:00:00', $articles[0]['published_date']);
    }

    public function test_fetch_maps_name_source_correctly(): void
    {
        Http::fake([
            'news.google.com/*' => Http::response($this->validRssXml(), 200),
        ]);

        $articles = $this->source->fetch('Ukraine news when:26h', 'en');

        $this->assertSame('BBC News', $articles[0]['name_source']);
        $this->assertSame('Reuters', $articles[1]['name_source']);
    }

    public function test_fetch_maps_clean_url_as_hostname_from_source_url(): void
    {
        Http::fake([
            'news.google.com/*' => Http::response($this->validRssXml(), 200),
        ]);

        $articles = $this->source->fetch('Ukraine news when:26h', 'en');

        $this->assertSame('www.bbc.com', $articles[0]['clean_url']);
        $this->assertSame('www.reuters.com', $articles[1]['clean_url']);
    }

    public function test_fetch_sets_language_from_parameter(): void
    {
        Http::fake([
            'news.google.com/*' => Http::response($this->validRssXml(), 200),
        ]);

        $articles = $this->source->fetch('Ukraine news when:26h', 'en');

        $this->assertSame('en', $articles[0]['language']);
        $this->assertSame('en', $articles[1]['language']);
    }

    public function test_fetch_sets_media_to_null_for_all_articles(): void
    {
        Http::fake([
            'news.google.com/*' => Http::response($this->validRssXml(), 200),
        ]);

        $articles = $this->source->fetch('Ukraine news when:26h', 'en');

        $this->assertNull($articles[0]['media']);
        $this->assertNull($articles[1]['media']);
    }

    public function test_fetch_returns_all_required_keys_for_each_article(): void
    {
        Http::fake([
            'news.google.com/*' => Http::response($this->validRssXml(), 200),
        ]);

        $articles = $this->source->fetch('Ukraine news when:26h', 'en');

        $requiredKeys = ['title', 'link', 'published_date', 'name_source', 'clean_url', 'language', 'media'];
        foreach ($articles as $article) {
            foreach ($requiredKeys as $key) {
                $this->assertArrayHasKey($key, $article, "Article is missing key: {$key}");
            }
        }
    }

    // -------------------------------------------------------------------------
    // fetch – language-specific URL params
    // -------------------------------------------------------------------------

    public function test_fetch_uses_uk_locale_params_for_uk_language(): void
    {
        Http::fake([
            'news.google.com/*' => Http::response($this->validRssXml(), 200),
        ]);

        $this->source->fetch('Київ when:50h', 'uk');

        Http::assertSent(function ($request) {
            $query = $request->url();
            return str_contains($query, 'hl=uk')
                && str_contains($query, 'gl=UA')
                && str_contains($query, 'ceid=UA%3Auk');
        });
    }

    public function test_fetch_uses_en_us_locale_params_for_en_language(): void
    {
        Http::fake([
            'news.google.com/*' => Http::response($this->validRssXml(), 200),
        ]);

        $this->source->fetch('Ukraine news when:26h', 'en');

        Http::assertSent(function ($request) {
            $query = $request->url();
            return str_contains($query, 'hl=en-US')
                && str_contains($query, 'gl=US')
                && str_contains($query, 'ceid=US%3Aen');
        });
    }

    public function test_fetch_falls_back_to_en_locale_params_for_unknown_language(): void
    {
        Http::fake([
            'news.google.com/*' => Http::response($this->validRssXml(), 200),
        ]);

        $this->source->fetch('nachrichten when:26h', 'de');

        Http::assertSent(function ($request) {
            $query = $request->url();
            return str_contains($query, 'hl=en-US')
                && str_contains($query, 'gl=US');
        });
    }

    // -------------------------------------------------------------------------
    // fetch – error responses
    // -------------------------------------------------------------------------

    public function test_fetch_returns_empty_array_on_http_429(): void
    {
        Http::fake([
            'news.google.com/*' => Http::response('Too Many Requests', 429),
        ]);

        $result = $this->source->fetch('Ukraine news when:26h', 'en');

        $this->assertSame([], $result);
    }

    public function test_fetch_returns_empty_array_on_http_500(): void
    {
        Http::fake([
            'news.google.com/*' => Http::response('Internal Server Error', 500),
        ]);

        $result = $this->source->fetch('Ukraine news when:26h', 'en');

        $this->assertSame([], $result);
    }

    public function test_fetch_returns_empty_array_on_http_404(): void
    {
        Http::fake([
            'news.google.com/*' => Http::response('Not Found', 404),
        ]);

        $result = $this->source->fetch('Ukraine news when:26h', 'en');

        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // fetch – malformed / empty XML
    // -------------------------------------------------------------------------

    public function test_fetch_returns_empty_array_on_malformed_xml(): void
    {
        Http::fake([
            'news.google.com/*' => Http::response('this is not xml at all <<<', 200),
        ]);

        $result = $this->source->fetch('Ukraine news when:26h', 'en');

        $this->assertSame([], $result);
    }

    public function test_fetch_returns_empty_array_on_invalid_xml_structure(): void
    {
        Http::fake([
            'news.google.com/*' => Http::response('<unclosed>', 200),
        ]);

        $result = $this->source->fetch('Ukraine news when:26h', 'en');

        $this->assertSame([], $result);
    }

    public function test_fetch_returns_empty_array_when_rss_has_no_items(): void
    {
        $emptyRss = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
    <channel>
        <title>Google News</title>
        <link>https://news.google.com</link>
        <description>Google News</description>
    </channel>
</rss>
XML;

        Http::fake([
            'news.google.com/*' => Http::response($emptyRss, 200),
        ]);

        $result = $this->source->fetch('Ukraine news when:26h', 'en');

        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function validRssXml(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/">
    <channel>
        <title>Google News - Ukraine news</title>
        <link>https://news.google.com/rss/search?q=Ukraine</link>
        <description>Google News</description>
        <item>
            <title>Article One Title</title>
            <link>https://example.com/article-one</link>
            <pubDate>Mon, 15 Jan 2024 10:00:00 GMT</pubDate>
            <source url="https://www.bbc.com/news">BBC News</source>
            <description>Description of article one.</description>
        </item>
        <item>
            <title>Article Two Title</title>
            <link>https://example.com/article-two</link>
            <pubDate>Mon, 15 Jan 2024 09:00:00 GMT</pubDate>
            <source url="https://www.reuters.com/news">Reuters</source>
            <description>Description of article two.</description>
        </item>
    </channel>
</rss>
XML;
    }
}
