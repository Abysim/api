<?php

namespace Tests\Unit\Jobs;

use App\Jobs\CleanNewsContentJob;
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
class CleanNewsContentJobTest extends TestCase
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
            'is_translated' => false,
            'publish_content' => 'Some article content. ' . str_repeat('Real text. ', 20),
            'publish_title' => 'English Title',
            'original_content' => null,
            'original_title' => null,
        ];

        $obj = new class extends \stdClass {
            public bool $updateReplyMarkupCalled = false;
            public function save(): bool { return true; }
            public function updateReplyMarkup(): void { $this->updateReplyMarkupCalled = true; }
        };

        foreach (array_merge($defaults, $attributes) as $key => $value) {
            $obj->$key = $value;
        }

        return $obj;
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

        $job = new CleanNewsContentJob(999);
        $job->handle();

        Queue::assertNothingPushed();
    }

    // handle — already cleaned

    public function test_handle_skips_when_already_cleaned(): void
    {
        $news = $this->makeNewsObject(['is_content_cleaned' => true]);
        $this->mockNewsFind($news);

        $job = new CleanNewsContentJob(1);
        $job->handle();

        Queue::assertNothingPushed();
    }

    // handle — untranslated articles: cleans publish_content for various platforms

    public function test_handle_cleans_content_for_free_news(): void
    {
        $cleanedText = str_repeat('Cleaned article content. ', 10);
        $news = $this->makeNewsObject(['platform' => 'FreeNews', 'language' => 'en']);
        $this->mockNewsFind($news);
        $this->fakeOpenAiResponse($cleanedText);

        $job = new CleanNewsContentJob(1);
        $job->handle();

        $this->assertSame($cleanedText, $news->publish_content);
        $this->assertTrue($news->is_content_cleaned);
        Queue::assertNothingPushed();
    }

    public function test_handle_cleans_content_for_newscatcher(): void
    {
        $cleanedText = str_repeat('Cleaned article content. ', 10);
        $news = $this->makeNewsObject(['platform' => 'NewsCatcher3', 'language' => 'en']);
        $this->mockNewsFind($news);
        $this->fakeOpenAiResponse($cleanedText);

        $job = new CleanNewsContentJob(1);
        $job->handle();

        $this->assertSame($cleanedText, $news->publish_content);
        $this->assertTrue($news->is_content_cleaned);
        Queue::assertNothingPushed();
    }

    public function test_handle_cleans_content_for_article(): void
    {
        $cleanedText = str_repeat('Cleaned article content. ', 10);
        $news = $this->makeNewsObject(['platform' => 'article', 'language' => 'en']);
        $this->mockNewsFind($news);
        $this->fakeOpenAiResponse($cleanedText);

        $job = new CleanNewsContentJob(1);
        $job->handle();

        $this->assertSame($cleanedText, $news->publish_content);
        $this->assertTrue($news->is_content_cleaned);
        Queue::assertNothingPushed();
    }

    // handle — updateReplyMarkup conditional behavior

    public function test_handle_does_not_call_update_reply_markup_when_content_changed(): void
    {
        $cleanedText = str_repeat('Cleaned article content. ', 10);
        $news = $this->makeNewsObject(['language' => 'en']);
        $this->mockNewsFind($news);
        $this->fakeOpenAiResponse($cleanedText);

        $job = new CleanNewsContentJob(1);
        $job->handle();

        $this->assertFalse($news->updateReplyMarkupCalled);
    }

    public function test_handle_calls_update_reply_markup_when_content_not_changed(): void
    {
        $news = $this->makeNewsObject(['language' => 'en']);
        $this->mockNewsFind($news);
        $this->fakeOpenAiResponse('Too short');

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'insufficient content'));

        $job = new CleanNewsContentJob(1);
        $job->handle();

        $this->assertTrue($news->updateReplyMarkupCalled);
    }

    // handle — untranslated articles: no TranslateNewsJob

    public function test_handle_does_not_dispatch_translate_job_for_untranslated_article(): void
    {
        $cleanedText = str_repeat('Cleaned article content. ', 10);
        $news = $this->makeNewsObject(['language' => 'en', 'is_translated' => false]);
        $this->mockNewsFind($news);
        $this->fakeOpenAiResponse($cleanedText);

        $job = new CleanNewsContentJob(1);
        $job->handle();

        Queue::assertNothingPushed();
    }

    // handle — AI returns short content

    public function test_handle_keeps_original_when_ai_returns_short_content(): void
    {
        $originalContent = 'Some article content. ' . str_repeat('Real text. ', 20);
        $news = $this->makeNewsObject(['language' => 'en']);
        $this->mockNewsFind($news);
        $this->fakeOpenAiResponse('Too short');

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'insufficient content'));

        $job = new CleanNewsContentJob(1);
        $job->handle();

        $this->assertSame($originalContent, $news->publish_content);
        $this->assertTrue($news->is_content_cleaned);
        $this->assertTrue($news->updateReplyMarkupCalled);
    }

    // handle — exception re-thrown for retry

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

        $job = new CleanNewsContentJob(1);
        $job->handle();
    }

    // failed — marks cleaned as fallback

    public function test_failed_marks_cleaned_as_fallback(): void
    {
        $news = $this->makeNewsObject(['language' => 'en', 'is_content_cleaned' => false]);
        $this->mockNewsFind($news);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'exhausted retries'));

        $job = new CleanNewsContentJob(1);
        $job->failed(new \RuntimeException('API timeout'));

        $this->assertTrue($news->is_content_cleaned);
    }

    public function test_failed_calls_update_reply_markup(): void
    {
        $news = $this->makeNewsObject(['language' => 'en', 'is_content_cleaned' => false]);
        $this->mockNewsFind($news);

        Log::shouldReceive('warning')->once();

        $job = new CleanNewsContentJob(1);
        $job->failed(new \RuntimeException('API timeout'));

        $this->assertTrue($news->updateReplyMarkupCalled);
    }

    // handle — translated non-UK articles

    public function test_handle_cleans_original_content_for_translated_article(): void
    {
        $cleanedText = str_repeat('Cleaned original content. ', 10);
        $originalContent = 'Original English article. ' . str_repeat('Original text. ', 20);
        $news = $this->makeNewsObject([
            'language' => 'en',
            'is_translated' => true,
            'original_content' => $originalContent,
            'original_title' => 'Original English Title',
            'publish_content' => 'Перекладений текст українською. ' . str_repeat('Текст. ', 20),
            'publish_title' => 'Перекладений заголовок',
        ]);
        $this->mockNewsFind($news);
        $this->fakeOpenAiResponse($cleanedText);

        $job = new CleanNewsContentJob(1);
        $job->handle();

        $this->assertSame($cleanedText, $news->original_content);
        $this->assertSame($cleanedText, $news->publish_content);
        $this->assertSame('Original English Title', $news->publish_title);
        $this->assertTrue($news->is_content_cleaned);
    }

    public function test_handle_dispatches_translate_job_for_translated_article(): void
    {
        $cleanedText = str_repeat('Cleaned original content. ', 10);
        $originalContent = 'Original English article. ' . str_repeat('Original text. ', 20);
        $news = $this->makeNewsObject([
            'language' => 'en',
            'is_translated' => true,
            'original_content' => $originalContent,
            'original_title' => 'Original English Title',
            'publish_content' => 'Перекладений текст. ' . str_repeat('Текст. ', 20),
            'publish_title' => 'Перекладений заголовок',
        ]);
        $this->mockNewsFind($news);
        $this->fakeOpenAiResponse($cleanedText);

        $job = new CleanNewsContentJob(1);
        $job->handle();

        Queue::assertPushed(TranslateNewsJob::class);
    }

    public function test_handle_resets_is_translated_for_translated_article(): void
    {
        $cleanedText = str_repeat('Cleaned original content. ', 10);
        $originalContent = 'Original English article. ' . str_repeat('Original text. ', 20);
        $news = $this->makeNewsObject([
            'language' => 'en',
            'is_translated' => true,
            'original_content' => $originalContent,
            'original_title' => 'Original English Title',
            'publish_content' => 'Перекладений текст. ' . str_repeat('Текст. ', 20),
            'publish_title' => 'Перекладений заголовок',
        ]);
        $this->mockNewsFind($news);
        $this->fakeOpenAiResponse($cleanedText);

        $job = new CleanNewsContentJob(1);
        $job->handle();

        $this->assertFalse($news->is_translated);
    }

    // handle — Ukrainian articles: never dispatch TranslateNewsJob

    public function test_handle_does_not_dispatch_translate_for_ukrainian_article(): void
    {
        $cleanedText = str_repeat('Очищений текст статті. ', 10);
        $news = $this->makeNewsObject([
            'language' => 'uk',
            'is_translated' => true,
            'publish_content' => 'Текст статті. ' . str_repeat('Текст. ', 20),
        ]);
        $this->mockNewsFind($news);
        $this->fakeOpenAiResponse($cleanedText);

        $job = new CleanNewsContentJob(1);
        $job->handle();

        $this->assertSame($cleanedText, $news->publish_content);
        $this->assertTrue($news->is_content_cleaned);
        Queue::assertNothingPushed();
    }

    // handle — translated article with unchanged content

    public function test_handle_does_not_reset_translation_when_content_unchanged(): void
    {
        $originalContent = 'Original English article. ' . str_repeat('Original text. ', 20);
        $news = $this->makeNewsObject([
            'language' => 'en',
            'is_translated' => true,
            'original_content' => $originalContent,
            'original_title' => 'Original English Title',
            'publish_content' => 'Перекладений текст. ' . str_repeat('Текст. ', 20),
            'publish_title' => 'Перекладений заголовок',
        ]);
        $this->mockNewsFind($news);
        $this->fakeOpenAiResponse('Too short');

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'insufficient content'));

        $job = new CleanNewsContentJob(1);
        $job->handle();

        $this->assertTrue($news->is_translated);
        $this->assertSame('Перекладений заголовок', $news->publish_title);
        $this->assertTrue($news->is_content_cleaned);
        $this->assertTrue($news->updateReplyMarkupCalled);
        Queue::assertNothingPushed();
    }

    // failed — does not touch translation state

    public function test_failed_does_not_touch_translation_state(): void
    {
        $news = $this->makeNewsObject([
            'language' => 'en',
            'is_translated' => true,
            'is_content_cleaned' => false,
            'original_content' => 'Original text. ' . str_repeat('Text. ', 20),
            'publish_content' => 'Перекладений текст. ' . str_repeat('Текст. ', 20),
        ]);
        $this->mockNewsFind($news);

        Log::shouldReceive('warning')->once();

        $job = new CleanNewsContentJob(1);
        $job->failed(new \RuntimeException('API timeout'));

        $this->assertTrue($news->is_translated);
        $this->assertTrue($news->is_content_cleaned);
        $this->assertTrue($news->updateReplyMarkupCalled);
        Queue::assertNothingPushed();
    }
}
