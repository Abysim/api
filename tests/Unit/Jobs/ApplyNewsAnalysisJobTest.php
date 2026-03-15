<?php

namespace Tests\Unit\Jobs;

use App\Enums\NewsStatus;
use App\Jobs\AnalyzeNewsJob;
use App\Jobs\ApplyNewsAnalysisJob;
use App\Models\News;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class ApplyNewsAnalysisJobTest extends TestCase
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

    private function mockTelegramRequest(): void
    {
        $mock = Mockery::mock('alias:' . \Longman\TelegramBot\Request::class);
        $mock->shouldReceive('sendMessage')->andReturn(
            new \Longman\TelegramBot\Entities\ServerResponse(['ok' => true, 'result' => true], '')
        );
    }

    private function makeNewsObject(array $attributes = []): object
    {
        $defaults = [
            'id' => 1,
            'platform' => 'FreeNews',
            'status' => NewsStatus::PENDING_REVIEW,
            'analysis' => 'Так. Виправлення: «по тому» → «потому»',
            'analysis_count' => 2,
            'content_hashes' => null,
            'previous_analysis' => null,
            'is_auto' => true,
            'is_deep' => false,
            'is_deepest' => false,
            'publish_title' => 'Original Title',
            'publish_content' => 'Original Content',
            'message_id' => 12345,
            'date' => Carbon::parse('2026-03-15'),
        ];

        $obj = new class extends \stdClass {
            public function save(): bool { return true; }
            public function refresh(): static { return $this; }
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

    private function computeHash(string $title, string $content): string
    {
        return md5(mb_strtolower(preg_replace('/\s+/', ' ', $title . "\n" . $content)));
    }

    // --- Test 1: Oscillation detected → escalates to deep ---

    public function test_oscillation_detected_escalates_to_deep(): void
    {
        $newTitle = 'New Title';
        $newContent = 'New content text';
        $hash = $this->computeHash($newTitle, $newContent);

        $news = $this->makeNewsObject([
            'content_hashes' => [$hash],
            'is_deep' => false,
        ]);
        $this->mockNewsFind($news);
        $this->mockTelegramRequest();
        $this->fakeOpenAiResponse("# $newTitle\n$newContent");

        $job = new ApplyNewsAnalysisJob(1);
        $job->handle();

        $this->assertTrue($news->is_deep);
        $this->assertSame(0, $news->analysis_count);
        $this->assertNull($news->content_hashes);
        $this->assertNull($news->previous_analysis);
        $this->assertNull($news->analysis);
        Queue::assertPushed(AnalyzeNewsJob::class);
    }

    // --- Test 2: Deep oscillation detected → finishes ---

    public function test_deep_oscillation_detected_finishes(): void
    {
        $newTitle = 'New Title';
        $newContent = 'New content text';
        $hash = $this->computeHash($newTitle, $newContent);

        $news = $this->makeNewsObject([
            'content_hashes' => [$hash],
            'is_deep' => true,
        ]);
        $this->mockNewsFind($news);
        $this->mockTelegramRequest();
        $this->fakeOpenAiResponse("# $newTitle\n$newContent");

        $job = new ApplyNewsAnalysisJob(1);
        $job->handle();

        $this->assertTrue($news->is_deepest);
        $this->assertFalse($news->is_auto);
        $this->assertNull($news->content_hashes);
        Queue::assertNotPushed(AnalyzeNewsJob::class);
    }

    // --- Test 3: No oscillation → appends hash and dispatches ---

    public function test_no_oscillation_appends_hash_and_dispatches_analyze(): void
    {
        $newTitle = 'New Title';
        $newContent = 'New content text';

        $news = $this->makeNewsObject([
            'content_hashes' => [],
            'analysis_count' => 2,
        ]);
        $this->mockNewsFind($news);
        $this->fakeOpenAiResponse("# $newTitle\n$newContent");

        $job = new ApplyNewsAnalysisJob(1);
        $job->handle();

        $expectedHash = $this->computeHash($newTitle, $newContent);
        $this->assertIsArray($news->content_hashes);
        $this->assertContains($expectedHash, $news->content_hashes);
        $this->assertNull($news->analysis);
        $this->assertSame('Так. Виправлення: «по тому» → «потому»', $news->previous_analysis);
        $this->assertSame($newTitle, $news->publish_title);
        $this->assertSame($newContent, $news->publish_content);
        Queue::assertPushed(AnalyzeNewsJob::class);
    }

    // --- Test 4: Previous analysis preserved before clearing ---

    public function test_previous_analysis_preserved_before_clearing(): void
    {
        $analysisText = 'Так. Fix the title spelling';
        $news = $this->makeNewsObject([
            'analysis' => $analysisText,
            'content_hashes' => [],
        ]);
        $this->mockNewsFind($news);
        $this->fakeOpenAiResponse("# Fixed Title\nFixed content");

        $job = new ApplyNewsAnalysisJob(1);
        $job->handle();

        $this->assertSame($analysisText, $news->previous_analysis);
        $this->assertNull($news->analysis);
    }

    // --- Test 5: Hash uses normalized content ---

    public function test_hash_uses_normalized_content(): void
    {
        // Pre-compute hash from normalized "new title\nnew content text"
        $hash = $this->computeHash('New Title', 'New Content Text');

        $news = $this->makeNewsObject([
            'content_hashes' => [$hash],
            'is_deep' => false,
        ]);
        $this->mockNewsFind($news);
        $this->mockTelegramRequest();
        // AI returns same content but with different whitespace and case
        $this->fakeOpenAiResponse("#   New  Title  \n  New   Content   Text  ");

        $job = new ApplyNewsAnalysisJob(1);
        $job->handle();

        // Should detect oscillation despite whitespace/case differences
        $this->assertTrue($news->is_deep);
        $this->assertSame(0, $news->analysis_count);
        Queue::assertPushed(AnalyzeNewsJob::class);
    }
}
