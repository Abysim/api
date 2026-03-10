<?php

namespace Tests\Unit\Jobs;

use App\Jobs\CleanFreeNewsContentJob;
use App\Jobs\TranslateNewsJob;
use App\Models\News;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class CleanFreeNewsContentJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function mockNewsFind(?object $return, int $id = 1): void
    {
        $mock = Mockery::mock('alias:' . News::class);
        $mock->shouldReceive('find')->with($id)->andReturn($return);
    }

    private function makeNewsObject(array $attributes = []): object
    {
        $defaults = [
            'id' => 1,
            'platform' => 'FreeNews',
            'language' => 'en',
            'is_content_cleaned' => false,
            'publish_content' => 'Some article content. ' . str_repeat('Real text. ', 20),
        ];

        return (object) array_merge($defaults, $attributes);
    }

    // handle — model not found

    public function test_handle_returns_early_when_model_not_found(): void
    {
        $this->mockNewsFind(null, 999);

        $job = new CleanFreeNewsContentJob(999);
        $job->handle();

        Queue::assertNothingPushed();
    }

    // handle — already cleaned or non-FreeNews → dispatch translate for non-uk

    public function test_handle_dispatches_translate_when_already_cleaned_and_language_is_not_uk(): void
    {
        $news = $this->makeNewsObject(['is_content_cleaned' => true, 'language' => 'en']);
        $this->mockNewsFind($news);

        $job = new CleanFreeNewsContentJob(1);
        $job->handle();

        Queue::assertPushed(TranslateNewsJob::class);
    }

    public function test_handle_does_not_dispatch_translate_when_already_cleaned_and_language_is_uk(): void
    {
        $news = $this->makeNewsObject(['is_content_cleaned' => true, 'language' => 'uk']);
        $this->mockNewsFind($news);

        $job = new CleanFreeNewsContentJob(1);
        $job->handle();

        Queue::assertNothingPushed();
    }

    public function test_handle_dispatches_translate_for_non_free_news_platform(): void
    {
        $news = $this->makeNewsObject(['platform' => 'NewsCatcher3', 'language' => 'en']);
        $this->mockNewsFind($news);

        $job = new CleanFreeNewsContentJob(1);
        $job->handle();

        Queue::assertPushed(TranslateNewsJob::class);
    }

    // handle — AI cleaning succeeds

    public function test_handle_cleans_content_and_dispatches_translate_for_non_uk(): void
    {
        $cleanedText = str_repeat('Cleaned article content. ', 10);
        $news = $this->makeNewsObject(['language' => 'en']);
        $this->mockNewsFind($news);

        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => $cleanedText]]],
            ]),
        ]);

        $job = new CleanFreeNewsContentJob(1);
        $job->handle();

        $this->assertSame($cleanedText, $news->publish_content);
        $this->assertTrue($news->is_content_cleaned);
        Queue::assertPushed(TranslateNewsJob::class);
    }

    public function test_handle_cleans_content_and_does_not_dispatch_translate_for_uk(): void
    {
        $cleanedText = str_repeat('Очищений текст статті. ', 10);
        $news = $this->makeNewsObject(['language' => 'uk']);
        $this->mockNewsFind($news);

        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => $cleanedText]]],
            ]),
        ]);

        $job = new CleanFreeNewsContentJob(1);
        $job->handle();

        $this->assertSame($cleanedText, $news->publish_content);
        $this->assertTrue($news->is_content_cleaned);
        Queue::assertNothingPushed();
    }

    // handle — AI returns insufficient content

    public function test_handle_does_not_mark_cleaned_when_ai_returns_short_content(): void
    {
        $news = $this->makeNewsObject(['language' => 'en']);
        $this->mockNewsFind($news);

        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => 'Too short']]],
            ]),
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'insufficient content'));

        $job = new CleanFreeNewsContentJob(1);
        $job->handle();

        $this->assertFalse($news->is_content_cleaned);
        Queue::assertPushed(TranslateNewsJob::class);
    }

    public function test_handle_does_not_mark_cleaned_when_ai_returns_null(): void
    {
        $news = $this->makeNewsObject(['language' => 'en']);
        $this->mockNewsFind($news);

        Http::fake([
            '*' => Http::response(['choices' => [['message' => ['content' => null]]]]),
        ]);

        Log::shouldReceive('warning')->once();

        $job = new CleanFreeNewsContentJob(1);
        $job->handle();

        $this->assertFalse($news->is_content_cleaned);
    }

    // handle — AI throws exception

    public function test_handle_still_dispatches_translate_after_ai_exception_for_non_uk(): void
    {
        $news = $this->makeNewsObject(['language' => 'en']);
        $this->mockNewsFind($news);

        Http::fake([
            '*' => Http::response('Server Error', 500),
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'cleanup failed'));

        $job = new CleanFreeNewsContentJob(1);
        $job->handle();

        $this->assertFalse($news->is_content_cleaned);
        Queue::assertPushed(TranslateNewsJob::class);
    }

    public function test_handle_does_not_dispatch_translate_after_ai_exception_for_uk(): void
    {
        $news = $this->makeNewsObject(['language' => 'uk']);
        $this->mockNewsFind($news);

        Http::fake([
            '*' => Http::response('Server Error', 500),
        ]);

        Log::shouldReceive('warning')->once();

        $job = new CleanFreeNewsContentJob(1);
        $job->handle();

        $this->assertFalse($news->is_content_cleaned);
        Queue::assertNothingPushed();
    }
}
