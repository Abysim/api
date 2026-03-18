<?php

namespace Tests\Unit\Jobs;

use App\Jobs\CleanFreeNewsContentJob;
use App\Jobs\TranslateNewsJob;
use App\Models\News;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
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

        $obj = new class extends \stdClass {
            public function save(): bool { return true; }
        };

        foreach (array_merge($defaults, $attributes) as $key => $value) {
            $obj->$key = $value;
        }

        return $obj;
    }

    private function makeBasicObject(array $attributes = []): object
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

    private function fakeOpenAiResponse(string $content): void
    {
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [
                    ['message' => ['content' => $content]],
                ],
            ]),
        ]);
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
        $news = $this->makeBasicObject(['is_content_cleaned' => true, 'language' => 'en']);
        $this->mockNewsFind($news);

        $job = new CleanFreeNewsContentJob(1);
        $job->handle();

        Queue::assertPushed(TranslateNewsJob::class);
    }

    public function test_handle_does_not_dispatch_translate_when_already_cleaned_and_language_is_uk(): void
    {
        $news = $this->makeBasicObject(['is_content_cleaned' => true, 'language' => 'uk']);
        $this->mockNewsFind($news);

        $job = new CleanFreeNewsContentJob(1);
        $job->handle();

        Queue::assertNothingPushed();
    }

    public function test_handle_dispatches_translate_for_non_free_news_platform(): void
    {
        $news = $this->makeBasicObject(['platform' => 'NewsCatcher3', 'language' => 'en']);
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
        $this->fakeOpenAiResponse($cleanedText);

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
        $this->fakeOpenAiResponse($cleanedText);

        $job = new CleanFreeNewsContentJob(1);
        $job->handle();

        $this->assertSame($cleanedText, $news->publish_content);
        $this->assertTrue($news->is_content_cleaned);
        Queue::assertNothingPushed();
    }

    // handle — AI returns insufficient content (fallback: mark cleaned with original)

    public function test_handle_marks_cleaned_with_original_when_ai_returns_short_content(): void
    {
        $originalContent = 'Some article content. ' . str_repeat('Real text. ', 20);
        $news = $this->makeNewsObject(['language' => 'en']);
        $this->mockNewsFind($news);
        $this->fakeOpenAiResponse('Too short');

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'insufficient content'));

        $job = new CleanFreeNewsContentJob(1);
        $job->handle();

        $this->assertSame($originalContent, $news->publish_content);
        $this->assertTrue($news->is_content_cleaned);
        Queue::assertPushed(TranslateNewsJob::class);
    }

    public function test_handle_marks_cleaned_with_original_when_ai_returns_empty(): void
    {
        $news = $this->makeNewsObject(['language' => 'uk']);
        $this->mockNewsFind($news);
        $this->fakeOpenAiResponse('');

        Log::shouldReceive('warning')->once();

        $job = new CleanFreeNewsContentJob(1);
        $job->handle();

        $this->assertTrue($news->is_content_cleaned);
        Queue::assertNothingPushed();
    }

    // handle — AI throws exception → re-thrown for retry

    public function test_handle_rethrows_exception_for_retry(): void
    {
        $news = $this->makeNewsObject(['language' => 'en']);
        $this->mockNewsFind($news);

        OpenAI::fake([
            new \RuntimeException('Connection timeout'),
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'cleanup failed'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Connection timeout');

        $job = new CleanFreeNewsContentJob(1);
        $job->handle();
    }

    public function test_handle_does_not_dispatch_translate_on_exception(): void
    {
        $news = $this->makeNewsObject(['language' => 'en']);
        $this->mockNewsFind($news);

        OpenAI::fake([
            new \RuntimeException('Connection timeout'),
        ]);

        Log::shouldReceive('warning')->once();

        try {
            $job = new CleanFreeNewsContentJob(1);
            $job->handle();
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertFalse($news->is_content_cleaned);
        Queue::assertNothingPushed();
    }

    // failed — marks cleaned as fallback after retries exhausted

    public function test_failed_marks_cleaned_and_dispatches_translate_for_non_uk(): void
    {
        $news = $this->makeNewsObject(['language' => 'en', 'is_content_cleaned' => false]);
        $this->mockNewsFind($news);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'exhausted retries'));

        $job = new CleanFreeNewsContentJob(1);
        $job->failed(new \RuntimeException('API timeout'));

        $this->assertTrue($news->is_content_cleaned);
        Queue::assertPushed(TranslateNewsJob::class);
    }

    public function test_failed_marks_cleaned_without_translate_for_uk(): void
    {
        $news = $this->makeNewsObject(['language' => 'uk', 'is_content_cleaned' => false]);
        $this->mockNewsFind($news);

        Log::shouldReceive('warning')->once();

        $job = new CleanFreeNewsContentJob(1);
        $job->failed(new \RuntimeException('API timeout'));

        $this->assertTrue($news->is_content_cleaned);
        Queue::assertNothingPushed();
    }

    public function test_failed_skips_when_already_cleaned(): void
    {
        $news = $this->makeBasicObject(['is_content_cleaned' => true]);
        $this->mockNewsFind($news);

        $job = new CleanFreeNewsContentJob(1);
        $job->failed(new \RuntimeException('error'));

        Queue::assertNothingPushed();
    }

    public function test_failed_skips_when_model_not_found(): void
    {
        $this->mockNewsFind(null, 999);

        $job = new CleanFreeNewsContentJob(999);
        $job->failed(new \RuntimeException('error'));

        Queue::assertNothingPushed();
    }
}
