<?php

namespace Tests\Unit\Services\News;

use App\Services\News\FreeNewsService;
use App\Services\News\GdeltSource;
use App\Services\News\GoogleNewsSource;
use App\Services\News\GoogleNewsUrlDecoder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class FreeNewsServiceTest extends TestCase
{
    private GoogleNewsSource $googleSource;
    private GdeltSource $gdeltSource;
    private GoogleNewsUrlDecoder $urlDecoder;
    private FreeNewsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->googleSource = Mockery::mock(GoogleNewsSource::class);
        $this->gdeltSource = Mockery::mock(GdeltSource::class);
        $this->urlDecoder = Mockery::mock(GoogleNewsUrlDecoder::class);
        $this->service = new FreeNewsService($this->googleSource, $this->gdeltSource, $this->urlDecoder);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // getName
    // -------------------------------------------------------------------------

    public function test_get_name_returns_free_news(): void
    {
        $this->assertSame('FreeNews', $this->service->getName());
    }

    // -------------------------------------------------------------------------
    // getSearchQueryLimit
    // -------------------------------------------------------------------------

    public function test_get_search_query_limit_returns_400(): void
    {
        $this->assertSame(400, $this->service->getSearchQueryLimit());
    }

    // -------------------------------------------------------------------------
    // generateSearchQuery
    // -------------------------------------------------------------------------

    public function test_generate_search_query_wraps_words_in_parentheses_with_or_operator(): void
    {
        $result = $this->service->generateSearchQuery(['lion', 'lions', 'lioness'], []);

        $this->assertSame('(lion OR lions OR lioness)', $result);
    }

    public function test_generate_search_query_appends_single_word_exclude_with_exclamation(): void
    {
        $result = $this->service->generateSearchQuery(['lion'], ['horoscope']);

        $this->assertSame('(lion) !horoscope', $result);
    }

    public function test_generate_search_query_appends_multiple_excludes(): void
    {
        $result = $this->service->generateSearchQuery(['lion', 'lions'], ['horoscope', 'astrology']);

        $this->assertSame('(lion OR lions) !horoscope !astrology', $result);
    }

    public function test_generate_search_query_quotes_multi_word_exclude(): void
    {
        $result = $this->service->generateSearchQuery(['lion'], ['sea lion']);

        $this->assertSame('(lion) !"sea lion"', $result);
    }

    public function test_generate_search_query_quotes_multi_word_excludes_and_leaves_single_word_unquoted(): void
    {
        $result = $this->service->generateSearchQuery(['lion'], ['horoscope', 'sea lion', 'circus act']);

        $this->assertSame('(lion) !horoscope !"sea lion" !"circus act"', $result);
    }

    public function test_generate_search_query_with_single_word_produces_correct_format(): void
    {
        $result = $this->service->generateSearchQuery(['ukraine'], []);

        $this->assertSame('(ukraine)', $result);
    }

    // -------------------------------------------------------------------------
    // extractKeywords (private — via ReflectionMethod)
    // -------------------------------------------------------------------------

    public function test_extract_keywords_parses_parenthesized_or_group_into_array(): void
    {
        $method = new ReflectionMethod(FreeNewsService::class, 'extractKeywords');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, '(lion OR lions OR lioness*) !horoscop*');

        $this->assertSame(['lion', 'lions', 'lioness'], array_values($result));
    }

    public function test_extract_keywords_strips_trailing_wildcard_from_keywords(): void
    {
        $method = new ReflectionMethod(FreeNewsService::class, 'extractKeywords');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, '(ukraine* OR kyiv*)');

        $this->assertSame(['ukraine', 'kyiv'], array_values($result));
    }

    public function test_extract_keywords_returns_empty_array_for_input_without_parentheses(): void
    {
        $method = new ReflectionMethod(FreeNewsService::class, 'extractKeywords');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, 'lion lions lioness');

        $this->assertSame([], $result);
    }

    public function test_extract_keywords_returns_empty_array_for_empty_string(): void
    {
        $method = new ReflectionMethod(FreeNewsService::class, 'extractKeywords');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, '');

        $this->assertSame([], $result);
    }

    public function test_extract_keywords_handles_single_keyword_in_parentheses(): void
    {
        $method = new ReflectionMethod(FreeNewsService::class, 'extractKeywords');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, '(lion) !horoscope');

        $this->assertSame(['lion'], array_values($result));
    }

    // -------------------------------------------------------------------------
    // titleMatchesKeywords (private — via ReflectionMethod)
    // -------------------------------------------------------------------------

    public function test_title_matches_keywords_returns_true_for_case_insensitive_match(): void
    {
        $method = new ReflectionMethod(FreeNewsService::class, 'titleMatchesKeywords');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, 'The Lion King Returns', ['lion', 'tigers']);

        $this->assertTrue($result);
    }

    public function test_title_matches_keywords_returns_true_when_keyword_is_uppercase_and_title_is_lowercase(): void
    {
        $method = new ReflectionMethod(FreeNewsService::class, 'titleMatchesKeywords');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, 'lion attacks reported', ['LION']);

        $this->assertTrue($result);
    }

    public function test_title_matches_keywords_returns_false_when_no_keyword_appears_in_title(): void
    {
        $method = new ReflectionMethod(FreeNewsService::class, 'titleMatchesKeywords');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, 'Weather forecast for Tuesday', ['lion', 'tigers', 'leopard']);

        $this->assertFalse($result);
    }

    public function test_title_matches_keywords_returns_false_for_empty_title(): void
    {
        $method = new ReflectionMethod(FreeNewsService::class, 'titleMatchesKeywords');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, '', ['lion']);

        $this->assertFalse($result);
    }

    public function test_title_matches_keywords_returns_true_on_partial_word_match(): void
    {
        $method = new ReflectionMethod(FreeNewsService::class, 'titleMatchesKeywords');
        $method->setAccessible(true);

        // 'lion' is a substring of 'lions'
        $result = $method->invoke($this->service, 'Three lions on the shirt', ['lion']);

        $this->assertTrue($result);
    }

    // -------------------------------------------------------------------------
    // extractExcludeWords (private — via ReflectionMethod)
    // -------------------------------------------------------------------------

    public function test_extract_exclude_words_parses_single_word_exclusion(): void
    {
        $method = new ReflectionMethod(FreeNewsService::class, 'extractExcludeWords');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, '(lion OR lions) !horoscope');

        $this->assertSame(['horoscope'], array_values($result));
    }

    public function test_extract_exclude_words_parses_wildcard_exclusion(): void
    {
        $method = new ReflectionMethod(FreeNewsService::class, 'extractExcludeWords');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, '(lion OR lions) !horoscop*');

        $this->assertSame(['horoscop*'], array_values($result));
    }

    public function test_extract_exclude_words_parses_quoted_multi_word_exclusion(): void
    {
        $method = new ReflectionMethod(FreeNewsService::class, 'extractExcludeWords');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, '(lion OR lions) !"sea lion"');

        $this->assertSame(['sea lion'], array_values($result));
    }

    public function test_extract_exclude_words_parses_mixed_exclusions(): void
    {
        $method = new ReflectionMethod(FreeNewsService::class, 'extractExcludeWords');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, '(lion OR lions) !horoscop* !"sea lion" !football*');

        $this->assertSame(['horoscop*', 'sea lion', 'football*'], array_values($result));
    }

    public function test_extract_exclude_words_returns_empty_for_no_exclusions(): void
    {
        $method = new ReflectionMethod(FreeNewsService::class, 'extractExcludeWords');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, '(lion OR lions)');

        $this->assertSame([], $result);
    }

    public function test_extract_exclude_words_returns_empty_for_empty_string(): void
    {
        $method = new ReflectionMethod(FreeNewsService::class, 'extractExcludeWords');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, '');

        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // titleMatchesExcludeWords (private — via ReflectionMethod)
    // -------------------------------------------------------------------------

    public function test_title_matches_exclude_words_rejects_matching_title(): void
    {
        $method = new ReflectionMethod(FreeNewsService::class, 'titleMatchesExcludeWords');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, 'Lions defeat Bears in NFL showdown', ['nfl*']);

        $this->assertTrue($result);
    }

    public function test_title_matches_exclude_words_handles_wildcard_prefix_match(): void
    {
        $method = new ReflectionMethod(FreeNewsService::class, 'titleMatchesExcludeWords');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, 'Your horoscope for today', ['horoscop*']);

        $this->assertTrue($result);
    }

    public function test_title_matches_exclude_words_passes_non_matching_title(): void
    {
        $method = new ReflectionMethod(FreeNewsService::class, 'titleMatchesExcludeWords');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, 'Lion spotted in Kenya wildlife reserve', ['horoscop*', 'nfl*']);

        $this->assertFalse($result);
    }

    public function test_title_matches_exclude_words_is_case_insensitive(): void
    {
        $method = new ReflectionMethod(FreeNewsService::class, 'titleMatchesExcludeWords');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, 'NFL Football Championship', ['football*']);

        $this->assertTrue($result);
    }

    public function test_title_matches_exclude_words_matches_multi_word_exclude(): void
    {
        $method = new ReflectionMethod(FreeNewsService::class, 'titleMatchesExcludeWords');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, 'The Lion King returns to Broadway', ['the lion king']);

        $this->assertTrue($result);
    }

    public function test_title_matches_exclude_words_returns_false_for_empty_exclude_list(): void
    {
        $method = new ReflectionMethod(FreeNewsService::class, 'titleMatchesExcludeWords');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, 'Lion spotted in the wild', []);

        $this->assertFalse($result);
    }

    // -------------------------------------------------------------------------
    // normalizeTitle (public static)
    // -------------------------------------------------------------------------

    public function test_normalize_title_converts_to_lowercase(): void
    {
        $this->assertSame('ukraine news', FreeNewsService::normalizeTitle('UKRAINE NEWS'));
    }

    public function test_normalize_title_strips_punctuation(): void
    {
        $this->assertSame('hello world how are you', FreeNewsService::normalizeTitle('Hello, World! How are you?'));
    }

    public function test_normalize_title_collapses_multiple_spaces_to_single_space(): void
    {
        $this->assertSame('lion attacks reported', FreeNewsService::normalizeTitle('lion   attacks   reported'));
    }

    public function test_normalize_title_trims_leading_and_trailing_whitespace(): void
    {
        $this->assertSame('lion news', FreeNewsService::normalizeTitle('  lion news  '));
    }

    public function test_normalize_title_removes_hyphens_and_special_characters(): void
    {
        $this->assertSame('breaking lionattack at zoo 2024', FreeNewsService::normalizeTitle('Breaking: Lion-attack at zoo (2024)'));
    }

    public function test_normalize_title_is_callable_as_public_static(): void
    {
        $result = FreeNewsService::normalizeTitle('HELLO, World!');

        $this->assertSame('hello world', $result);
    }

    // -------------------------------------------------------------------------
    // buildArticleArray (private — via ReflectionMethod)
    // -------------------------------------------------------------------------

    public function test_build_article_array_returns_all_14_required_keys(): void
    {
        $method = new ReflectionMethod(FreeNewsService::class, 'buildArticleArray');
        $method->setAccessible(true);

        $metadata = [
            'title' => 'Lion spotted downtown',
            'published_date' => '2024-01-15 10:00:00',
            'language' => 'en',
            'name_source' => 'BBC News',
            'clean_url' => 'bbc.com',
            'media' => 'https://example.com/image.jpg',
        ];

        $result = $method->invoke($this->service, $metadata, 'Full article content here.', 'https://bbc.com/news/lion');

        $expectedKeys = [
            'title', 'link', 'published_date', 'author', 'content',
            'summary', 'id', '_id', 'language', 'name_source',
            'rights', 'clean_url', 'domain_url', 'media',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result, "Article array is missing key: {$key}");
        }

        $this->assertCount(14, $result);
    }

    public function test_build_article_array_maps_fields_correctly(): void
    {
        $method = new ReflectionMethod(FreeNewsService::class, 'buildArticleArray');
        $method->setAccessible(true);

        $metadata = [
            'title' => 'Lion spotted downtown',
            'published_date' => '2024-01-15 10:00:00',
            'language' => 'en',
            'name_source' => 'BBC News',
            'clean_url' => 'bbc.com',
            'media' => null,
        ];

        $result = $method->invoke(
            $this->service,
            $metadata,
            'Full article content here.',
            'https://bbc.com/news/lion',
            'John Doe',
            'https://example.com/img.jpg'
        );

        $this->assertSame('Lion spotted downtown', $result['title']);
        $this->assertSame('https://bbc.com/news/lion', $result['link']);
        $this->assertSame('2024-01-15 10:00:00', $result['published_date']);
        $this->assertSame('John Doe', $result['author']);
        $this->assertSame('Full article content here.', $result['content']);
        $this->assertSame('en', $result['language']);
        $this->assertSame('BBC News', $result['name_source']);
        $this->assertNull($result['rights']);
        $this->assertSame('bbc.com', $result['clean_url']);
        $this->assertSame('bbc.com', $result['domain_url']);
        $this->assertSame('https://example.com/img.jpg', $result['media']);
    }

    public function test_build_article_array_id_and_underscore_id_are_equal(): void
    {
        $method = new ReflectionMethod(FreeNewsService::class, 'buildArticleArray');
        $method->setAccessible(true);

        $metadata = [
            'title' => 'Test title',
            'published_date' => '2024-01-15 10:00:00',
            'language' => 'en',
            'name_source' => 'Source',
            'clean_url' => 'example.com',
            'media' => null,
        ];

        $result = $method->invoke($this->service, $metadata, 'content', 'https://example.com/test');

        $this->assertSame($result['id'], $result['_id']);
        $this->assertSame(32, strlen($result['id']));
    }

    public function test_build_article_array_uses_metadata_media_when_image_param_is_null(): void
    {
        $method = new ReflectionMethod(FreeNewsService::class, 'buildArticleArray');
        $method->setAccessible(true);

        $metadata = [
            'title' => 'Test title',
            'published_date' => '2024-01-15 10:00:00',
            'language' => 'en',
            'name_source' => 'Source',
            'clean_url' => 'example.com',
            'media' => 'https://example.com/og-image.jpg',
        ];

        $result = $method->invoke($this->service, $metadata, 'content', 'https://example.com/test', null, null);

        $this->assertSame('https://example.com/og-image.jpg', $result['media']);
    }

    public function test_build_article_array_summary_is_truncated_to_500_chars(): void
    {
        $method = new ReflectionMethod(FreeNewsService::class, 'buildArticleArray');
        $method->setAccessible(true);

        $longContent = str_repeat('a', 600);
        $metadata = [
            'title' => 'Test title',
            'published_date' => '2024-01-15 10:00:00',
            'language' => 'en',
            'name_source' => 'Source',
            'clean_url' => 'example.com',
            'media' => null,
        ];

        $result = $method->invoke($this->service, $metadata, $longContent, 'https://example.com/test');

        $this->assertLessThanOrEqual(503, mb_strlen($result['summary'])); // 500 + '...'
        $this->assertLessThan(mb_strlen($longContent), mb_strlen($result['summary']));
    }

    // -------------------------------------------------------------------------
    // isUrlSafe (private — via ReflectionMethod)
    // -------------------------------------------------------------------------

    public function test_is_url_safe_rejects_non_http_scheme(): void
    {
        $method = new ReflectionMethod(FreeNewsService::class, 'isUrlSafe');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($this->service, 'ftp://example.com'));
        $this->assertFalse($method->invoke($this->service, 'file:///etc/passwd'));
        $this->assertFalse($method->invoke($this->service, 'javascript:alert(1)'));
    }

    public function test_is_url_safe_rejects_private_ipv4(): void
    {
        $method = new ReflectionMethod(FreeNewsService::class, 'isUrlSafe');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($this->service, 'http://127.0.0.1/test'));
        $this->assertFalse($method->invoke($this->service, 'http://192.168.1.1/test'));
        $this->assertFalse($method->invoke($this->service, 'http://10.0.0.1/test'));
    }

    public function test_is_url_safe_accepts_valid_public_url(): void
    {
        $method = new ReflectionMethod(FreeNewsService::class, 'isUrlSafe');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->service, 'https://example.com/article'));
    }

    public function test_is_url_safe_rejects_empty_host(): void
    {
        $method = new ReflectionMethod(FreeNewsService::class, 'isUrlSafe');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($this->service, 'http:///path'));
    }

    public function test_is_url_safe_blocks_private_ipv6_via_aaaa_records(): void
    {
        $method = new ReflectionMethod(FreeNewsService::class, 'isUrlSafe');
        $method->setAccessible(true);

        // Direct IPv6 loopback as host — gethostbyname won't resolve it,
        // but the method should still reject it (fail closed on DNS failure for non-IP hosts,
        // or blocked by filter_var for IP literals)
        $this->assertFalse($method->invoke($this->service, 'http://[::1]/test'));
    }

    // -------------------------------------------------------------------------
    // getNews — merges both sources
    // -------------------------------------------------------------------------

    public function test_get_news_calls_both_sources(): void
    {
        Sleep::fake();
        Http::fake(['*' => Http::response('', 200)]);
        config(['services.news.max_enrich' => 0]);

        $googleArticle = $this->makeArticle('Lion attacks in Africa', 'https://example.com/google', '2024-01-15 10:00:00');
        $gdeltArticle = $this->makeArticle('Lion sighted near town', 'https://example.com/gdelt', '2024-01-15 11:00:00');

        $this->googleSource->shouldReceive('buildQuery')->once()->andReturn('built-google-query');
        $this->googleSource->shouldReceive('fetch')->once()->andReturn([$googleArticle]);

        $this->gdeltSource->shouldReceive('buildQuery')->once()->andReturn('built-gdelt-query');
        $this->gdeltSource->shouldReceive('fetch')->once()->andReturn([$gdeltArticle]);

        $this->service->getNews('(lion OR lions)');

        // Mockery's shouldReceive()->once() expectations above serve as the assertions
        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_get_news_returns_articles_from_both_sources_merged(): void
    {
        Sleep::fake();
        config(['services.news.max_enrich' => 2]);

        $googleArticle = $this->makeArticle('Lion attacks in Africa', 'https://example.com/google', '2024-01-15 10:00:00');
        $gdeltArticle = $this->makeArticle('Lion sighted near town', 'https://example.com/gdelt', '2024-01-15 11:00:00');

        $this->googleSource->shouldReceive('buildQuery')->once()->andReturn('built-google-query');
        $this->googleSource->shouldReceive('fetch')->once()->andReturn([$googleArticle]);
        $this->gdeltSource->shouldReceive('buildQuery')->once()->andReturn('built-gdelt-query');
        $this->gdeltSource->shouldReceive('fetch')->once()->andReturn([$gdeltArticle]);

        Http::fake(['*' => Http::response('', 404)]);

        $result = $this->service->getNews('(lion OR lions)');

        $this->assertCount(2, $result);
        $titles = array_column($result, 'title');
        $this->assertContains('Lion attacks in Africa', $titles);
        $this->assertContains('Lion sighted near town', $titles);
    }

    public function test_get_news_filters_articles_whose_title_does_not_match_any_keyword(): void
    {
        Sleep::fake();
        config(['services.news.max_enrich' => 10]);

        $matchingArticle = $this->makeArticle('Lion attacks villager', 'https://example.com/1', '2024-01-15 10:00:00');
        $nonMatchingArticle = $this->makeArticle('Weather forecast for Tuesday', 'https://example.com/2', '2024-01-15 11:00:00');

        $this->googleSource->shouldReceive('buildQuery')->once()->andReturn('query');
        $this->googleSource->shouldReceive('fetch')->once()->andReturn([$matchingArticle, $nonMatchingArticle]);
        $this->gdeltSource->shouldReceive('buildQuery')->once()->andReturn('query');
        $this->gdeltSource->shouldReceive('fetch')->once()->andReturn([]);

        Http::fake(['*' => Http::response('', 404)]);

        $result = $this->service->getNews('(lion OR lions)');

        $this->assertCount(1, $result);
        $this->assertSame('Lion attacks villager', $result[0]['title']);
    }

    public function test_get_news_deduplicates_articles_with_highly_similar_titles(): void
    {
        Sleep::fake();
        config(['services.news.max_enrich' => 10]);

        // These two titles are nearly identical — above 70% similarity threshold
        $article1 = $this->makeArticle('Lion attacks villager in Kenya today', 'https://example.com/1', '2024-01-15 10:00:00');
        $article2 = $this->makeArticle('Lion attacks villager in Kenya today', 'https://example.com/2', '2024-01-15 11:00:00');

        $this->googleSource->shouldReceive('buildQuery')->once()->andReturn('query');
        $this->googleSource->shouldReceive('fetch')->once()->andReturn([$article1]);
        $this->gdeltSource->shouldReceive('buildQuery')->once()->andReturn('query');
        $this->gdeltSource->shouldReceive('fetch')->once()->andReturn([$article2]);

        Http::fake(['*' => Http::response('', 404)]);

        $result = $this->service->getNews('(lion OR lions)');

        $this->assertCount(1, $result);
    }

    public function test_get_news_keeps_articles_with_sufficiently_different_titles(): void
    {
        Sleep::fake();
        config(['services.news.max_enrich' => 10]);

        $article1 = $this->makeArticle('Lion attacks villager in Kenya', 'https://example.com/1', '2024-01-15 10:00:00');
        $article2 = $this->makeArticle('Lion population grows across Africa', 'https://example.com/2', '2024-01-15 11:00:00');

        $this->googleSource->shouldReceive('buildQuery')->once()->andReturn('query');
        $this->googleSource->shouldReceive('fetch')->once()->andReturn([$article1]);
        $this->gdeltSource->shouldReceive('buildQuery')->once()->andReturn('query');
        $this->gdeltSource->shouldReceive('fetch')->once()->andReturn([$article2]);

        Http::fake(['*' => Http::response('', 404)]);

        $result = $this->service->getNews('(lion OR lions)');

        $this->assertCount(2, $result);
    }

    public function test_get_news_returns_empty_array_when_both_sources_fail(): void
    {
        Sleep::fake();
        config(['services.news.max_enrich' => 10]);

        $this->googleSource->shouldReceive('buildQuery')->once()->andReturn('query');
        $this->googleSource->shouldReceive('fetch')->once()->andThrow(new \RuntimeException('network error'));
        $this->gdeltSource->shouldReceive('buildQuery')->once()->andReturn('query');
        $this->gdeltSource->shouldReceive('fetch')->once()->andThrow(new \RuntimeException('network error'));

        $result = $this->service->getNews('(lion OR lions)');

        $this->assertSame([], $result);
    }

    public function test_get_news_sorts_results_oldest_first(): void
    {
        Sleep::fake();
        config(['services.news.max_enrich' => 10]);

        $older = $this->makeArticle('Lion pride spotted near Nairobi wildlife reserve', 'https://example.com/older', '2024-01-15 08:00:00');
        $newer = $this->makeArticle('Authorities issue warning after lion escapes zoo enclosure', 'https://example.com/newer', '2024-01-15 12:00:00');

        // Return newer first to confirm sorting reverses the order
        $this->googleSource->shouldReceive('buildQuery')->once()->andReturn('query');
        $this->googleSource->shouldReceive('fetch')->once()->andReturn([$newer, $older]);
        $this->gdeltSource->shouldReceive('buildQuery')->once()->andReturn('query');
        $this->gdeltSource->shouldReceive('fetch')->once()->andReturn([]);

        Http::fake(['*' => Http::response('', 404)]);

        $result = $this->service->getNews('(lion OR lions)');

        $this->assertCount(2, $result);
        $this->assertSame('2024-01-15 08:00:00', $result[0]['published_date']);
        $this->assertSame('2024-01-15 12:00:00', $result[1]['published_date']);
    }

    public function test_get_news_filters_articles_from_excluded_domains(): void
    {
        Sleep::fake();
        config(['services.news.max_enrich' => 10]);

        $goodArticle = $this->makeArticle('Lion spotted in Kenya', 'https://bbc.com/news/1', '2024-01-15 10:00:00');
        $excludedArticle = $this->makeArticle('Lion in sport news', 'https://sport.ua/news/2', '2024-01-15 11:00:00');

        $this->googleSource->shouldReceive('buildQuery')->once()->andReturn('query');
        $this->googleSource->shouldReceive('fetch')->once()->andReturn([$goodArticle, $excludedArticle]);
        $this->gdeltSource->shouldReceive('buildQuery')->once()->andReturn('query');
        $this->gdeltSource->shouldReceive('fetch')->once()->andReturn([]);

        Http::fake(['*' => Http::response('', 404)]);

        $result = $this->service->getNews('(lion OR lions)');

        $this->assertCount(1, $result);
        $this->assertSame('Lion spotted in Kenya', $result[0]['title']);
    }

    public function test_get_news_exclude_words_filter_rejects_title_with_exclude_term(): void
    {
        Sleep::fake();
        config(['services.news.max_enrich' => 10]);

        $goodArticle = $this->makeArticle('Lion spotted in Kenya', 'https://example.com/1', '2024-01-15 10:00:00');
        $badArticle = $this->makeArticle('Lions horoscope reading for March', 'https://example.com/2', '2024-01-15 11:00:00');

        $this->googleSource->shouldReceive('buildQuery')->once()->andReturn('query');
        $this->googleSource->shouldReceive('fetch')->once()->andReturn([$goodArticle, $badArticle]);
        $this->gdeltSource->shouldReceive('buildQuery')->once()->andReturn('query');
        $this->gdeltSource->shouldReceive('fetch')->once()->andReturn([]);

        Http::fake(['*' => Http::response('', 404)]);

        $result = $this->service->getNews('(lion OR lions) !horoscop*');

        $this->assertCount(1, $result);
        $this->assertSame('Lion spotted in Kenya', $result[0]['title']);
    }

    public function test_get_news_exclude_words_filter_with_wildcard_rejects_matching_title(): void
    {
        Sleep::fake();
        config(['services.news.max_enrich' => 10]);

        $goodArticle = $this->makeArticle('Tiger seen in national park', 'https://example.com/1', '2024-01-15 10:00:00');
        $badArticle = $this->makeArticle('Tiger football championship game', 'https://example.com/2', '2024-01-15 11:00:00');

        $this->googleSource->shouldReceive('buildQuery')->once()->andReturn('query');
        $this->googleSource->shouldReceive('fetch')->once()->andReturn([$goodArticle, $badArticle]);
        $this->gdeltSource->shouldReceive('buildQuery')->once()->andReturn('query');
        $this->gdeltSource->shouldReceive('fetch')->once()->andReturn([]);

        Http::fake(['*' => Http::response('', 404)]);

        $result = $this->service->getNews('(tiger OR tigers) !football*');

        $this->assertCount(1, $result);
        $this->assertSame('Tiger seen in national park', $result[0]['title']);
    }

    public function test_get_news_skips_articles_with_empty_title(): void
    {
        Sleep::fake();
        config(['services.news.max_enrich' => 10]);

        $articleWithTitle = $this->makeArticle('Lion spotted', 'https://example.com/1', '2024-01-15 10:00:00');
        $articleNoTitle = $this->makeArticle('', 'https://example.com/2', '2024-01-15 10:00:00');

        $this->googleSource->shouldReceive('buildQuery')->once()->andReturn('query');
        $this->googleSource->shouldReceive('fetch')->once()->andReturn([$articleWithTitle, $articleNoTitle]);
        $this->gdeltSource->shouldReceive('buildQuery')->once()->andReturn('query');
        $this->gdeltSource->shouldReceive('fetch')->once()->andReturn([]);

        Http::fake(['*' => Http::response('', 404)]);

        $result = $this->service->getNews('(lion OR lions)');

        $this->assertCount(1, $result);
        $this->assertSame('Lion spotted', $result[0]['title']);
    }

    // -------------------------------------------------------------------------
    // setTitleDedupFilter — pre-extraction DB dedup
    // -------------------------------------------------------------------------

    public function test_get_news_with_title_dedup_filter_skips_extraction_for_filtered_articles(): void
    {
        Sleep::fake();
        config(['services.news.max_enrich' => 10]);

        // Titles must be distinct enough to pass the 70% batch dedup
        $articles = [
            $this->makeArticle('Lion pride spotted near Nairobi reserve', 'https://example.com/1', '2024-01-15 10:00:00'),
            $this->makeArticle('Lion cub rescued from poachers in Tanzania', 'https://example.com/2', '2024-01-15 11:00:00'),
            $this->makeArticle('Mountain lion attacks livestock in Colorado ranch', 'https://example.com/3', '2024-01-15 12:00:00'),
            $this->makeArticle('Lion conservation program launches in South Africa', 'https://example.com/4', '2024-01-15 13:00:00'),
            $this->makeArticle('Asiatic lion population grows in Gujarat sanctuary', 'https://example.com/5', '2024-01-15 14:00:00'),
        ];

        $this->googleSource->shouldReceive('buildQuery')->once()->andReturn('query');
        $this->googleSource->shouldReceive('fetch')->once()->andReturn($articles);
        $this->gdeltSource->shouldReceive('buildQuery')->once()->andReturn('query');
        $this->gdeltSource->shouldReceive('fetch')->once()->andReturn([]);

        // Filter removes articles 2 and 4 (simulating DB duplicates)
        $this->service->setTitleDedupFilter(function (array $articles): array {
            return array_values(array_filter($articles, function (array $a) {
                return !str_contains($a['title'], 'rescued from poachers') && !str_contains($a['title'], 'conservation program');
            }));
        });

        Http::fake(['*' => Http::response('', 404)]);

        $result = $this->service->getNews('(lion OR lions)');

        $this->assertCount(3, $result);
        $titles = array_column($result, 'title');
        $this->assertContains('Lion pride spotted near Nairobi reserve', $titles);
        $this->assertNotContains('Lion cub rescued from poachers in Tanzania', $titles);
        $this->assertContains('Mountain lion attacks livestock in Colorado ranch', $titles);
        $this->assertNotContains('Lion conservation program launches in South Africa', $titles);
        $this->assertContains('Asiatic lion population grows in Gujarat sanctuary', $titles);
    }

    public function test_get_news_without_title_dedup_filter_passes_all_articles_to_extraction(): void
    {
        Sleep::fake();
        config(['services.news.max_enrich' => 10]);

        // Titles must be distinct enough to pass the 70% batch dedup
        $articles = [
            $this->makeArticle('Lion pride spotted near Nairobi reserve', 'https://example.com/1', '2024-01-15 10:00:00'),
            $this->makeArticle('Mountain lion attacks livestock in Colorado ranch', 'https://example.com/2', '2024-01-15 11:00:00'),
            $this->makeArticle('Asiatic lion population grows in Gujarat sanctuary', 'https://example.com/3', '2024-01-15 12:00:00'),
        ];

        $this->googleSource->shouldReceive('buildQuery')->once()->andReturn('query');
        $this->googleSource->shouldReceive('fetch')->once()->andReturn($articles);
        $this->gdeltSource->shouldReceive('buildQuery')->once()->andReturn('query');
        $this->gdeltSource->shouldReceive('fetch')->once()->andReturn([]);

        // No filter set — all should proceed
        Http::fake(['*' => Http::response('', 404)]);

        $result = $this->service->getNews('(lion OR lions)');

        $this->assertCount(3, $result);
    }

    public function test_get_news_title_dedup_filter_receives_only_phase2_survivors(): void
    {
        Sleep::fake();
        config(['services.news.max_enrich' => 10]);

        $matchingArticle = $this->makeArticle('Lion spotted in Africa', 'https://example.com/1', '2024-01-15 10:00:00');
        $nonMatchingArticle = $this->makeArticle('Weather forecast for Tuesday', 'https://example.com/2', '2024-01-15 11:00:00');

        $this->googleSource->shouldReceive('buildQuery')->once()->andReturn('query');
        $this->googleSource->shouldReceive('fetch')->once()->andReturn([$matchingArticle, $nonMatchingArticle]);
        $this->gdeltSource->shouldReceive('buildQuery')->once()->andReturn('query');
        $this->gdeltSource->shouldReceive('fetch')->once()->andReturn([]);

        // Spy callable to capture what the filter receives
        $receivedArticles = null;
        $this->service->setTitleDedupFilter(function (array $articles) use (&$receivedArticles): array {
            $receivedArticles = $articles;
            return $articles; // pass-through
        });

        Http::fake(['*' => Http::response('', 404)]);

        $this->service->getNews('(lion OR lions)');

        // Filter should only receive the matching article (non-matching was removed in Phase 2)
        $this->assertNotNull($receivedArticles);
        $this->assertCount(1, $receivedArticles);
        $this->assertSame('Lion spotted in Africa', $receivedArticles[0]['title']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeArticle(string $title, string $link, string $publishedDate, string $lang = 'en'): array
    {
        return [
            'title' => $title,
            'link' => $link,
            'published_date' => $publishedDate,
            'language' => $lang,
            'name_source' => 'Test Source',
            'clean_url' => parse_url($link, PHP_URL_HOST) ?? 'example.com',
            'media' => null,
        ];
    }
}
