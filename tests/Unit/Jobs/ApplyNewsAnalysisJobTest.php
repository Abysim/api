<?php

namespace Tests\Unit\Jobs;

use App\Enums\NewsStatus;
use App\Helpers\SentenceHasher;
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
            'analysis' => 'Так. Виправлення: «у світі» → «в світі»',
            'analysis_count' => 2,
            'content_hashes' => null,
            'previous_analysis' => null,
            'is_auto' => true,
            'is_deep' => false,
            'is_deepest' => false,
            'publish_title' => 'Original Title',
            'publish_content' => 'Original content sentence one. Original content sentence two.',
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

    private function fakeOpenAiResponses(array $contents): void
    {
        $responses = array_map(fn($content) =>
            $content instanceof CreateResponse
                ? $content
                : ($content instanceof \Exception
                    ? $content
                    : CreateResponse::fake([
                        'choices' => [['message' => ['content' => $content]]],
                    ])),
            $contents
        );
        OpenAI::fake($responses);
    }

    // --- Test 1: First cycle stores sentence hashes ---

    public function test_first_cycle_stores_sentence_hashes(): void
    {
        $news = $this->makeNewsObject(['content_hashes' => null]);
        $this->mockNewsFind($news);
        $this->fakeOpenAiResponses(["# New Title\nNew content text."]);

        $job = new ApplyNewsAnalysisJob(1);
        $job->handle();

        $this->assertIsArray($news->content_hashes);
        $this->assertArrayHasKey('cycles', $news->content_hashes);
        $this->assertCount(1, $news->content_hashes['cycles']);
        $this->assertArrayHasKey('hashes', $news->content_hashes['cycles'][0]);
        $this->assertNull($news->analysis);
        $this->assertSame('New Title', $news->publish_title);
        Queue::assertPushed(AnalyzeNewsJob::class);
    }

    // --- Test 2: Old content_hashes format treated as empty ---

    public function test_old_content_hashes_format_treated_as_empty(): void
    {
        $news = $this->makeNewsObject([
            'content_hashes' => ['abc123', 'def456'], // old flat array
        ]);
        $this->mockNewsFind($news);
        $this->fakeOpenAiResponses(["# New Title\nNew content."]);

        $job = new ApplyNewsAnalysisJob(1);
        $job->handle();

        $this->assertIsArray($news->content_hashes);
        $this->assertArrayHasKey('cycles', $news->content_hashes);
        Queue::assertPushed(AnalyzeNewsJob::class);
    }

    // --- Test 3: No changes → does NOT escalate, dispatches AnalyzeNewsJob ---

    public function test_no_changes_continues_cycle(): void
    {
        $title = 'Same Title';
        $content = 'Same content.';
        $hashTexts = SentenceHasher::hashSentences($title, $content);

        $news = $this->makeNewsObject([
            'publish_title' => $title,
            'publish_content' => $content,
            'content_hashes' => ['cycles' => [
                ['hashes' => array_keys($hashTexts)],
                ['hashes' => array_keys($hashTexts)],
            ]],
            'is_deep' => false,
        ]);
        $this->mockNewsFind($news);
        $this->fakeOpenAiResponses(["# $title\n$content"]);

        $job = new ApplyNewsAnalysisJob(1);
        $job->handle();

        // Does NOT escalate — hands back to analyzer
        $this->assertFalse($news->is_deep);
        $this->assertNull($news->analysis);
        $this->assertSame('Так. Виправлення: «у світі» → «в світі»', $news->previous_analysis);
        Queue::assertPushed(AnalyzeNewsJob::class);
    }

    // --- Test 4: Flip-flop → AI selects best variant, continues cycle ---

    public function test_flipflop_resolves_and_continues_cycle(): void
    {
        $titleA = 'Title';
        $contentA = 'Ягуари є верхівковими хижаками.';
        $contentB = 'Ягуари є вищими хижаками.';
        $hashesA = array_keys(SentenceHasher::hashSentences($titleA, $contentA));
        $hashesB = array_keys(SentenceHasher::hashSentences($titleA, $contentB));

        $news = $this->makeNewsObject([
            'publish_title' => $titleA,
            'publish_content' => $contentB, // model has state B
            'content_hashes' => ['cycles' => [
                ['hashes' => $hashesA], // Cycle 0: state A
                ['hashes' => $hashesB], // Cycle 1: state B (last)
            ]],
            'is_deep' => false,
        ]);
        $this->mockNewsFind($news);
        // Editor returns state A (flip-flop), then AI variant selection picks B
        $this->fakeOpenAiResponses([
            "# $titleA\n$contentA",
            CreateResponse::fake(['choices' => [['message' => ['content' => '{"1": "B"}']]]])
        ]);

        $job = new ApplyNewsAnalysisJob(1);
        $job->handle();

        // Does NOT escalate — resolves flip-flop and continues cycle
        $this->assertFalse($news->is_deep);
        $this->assertNull($news->analysis);
        // AI chose B → prior variant applied
        $this->assertSame('Ягуари є вищими хижаками.', $news->publish_content);
        // Hashes stored for resolved content
        $this->assertArrayHasKey('cycles', $news->content_hashes);
        $this->assertCount(3, $news->content_hashes['cycles']);
        Queue::assertPushed(AnalyzeNewsJob::class);
    }

    // --- Test 5: Partial flip-flop continues normally ---

    public function test_partial_flipflop_continues_normally(): void
    {
        $title = 'Title';
        $hashesAB = array_keys(SentenceHasher::hashSentences($title, 'Sentence A. Sentence B.'));
        $hashesAC = array_keys(SentenceHasher::hashSentences($title, 'Sentence A. Sentence C.'));

        $news = $this->makeNewsObject([
            'publish_title' => $title,
            'publish_content' => 'Sentence A. Sentence C.',
            'content_hashes' => ['cycles' => [
                ['hashes' => $hashesAB],
                ['hashes' => $hashesAC],
            ]],
        ]);
        $this->mockNewsFind($news);
        $this->fakeOpenAiResponses(["# $title\nSentence A. Sentence B. Sentence D."]);

        $job = new ApplyNewsAnalysisJob(1);
        $job->handle();

        $this->assertFalse($news->is_deep);
        $this->assertArrayHasKey('cycles', $news->content_hashes);
        $this->assertCount(3, $news->content_hashes['cycles']);
        Queue::assertPushed(AnalyzeNewsJob::class);
    }

    // --- Test 6: AI selection invalid JSON keeps current content ---

    public function test_ai_selection_invalid_json_keeps_current(): void
    {
        $title = 'Title';
        $contentA = 'Version A sentence.';
        $contentB = 'Version B sentence.';
        $hashesA = array_keys(SentenceHasher::hashSentences($title, $contentA));
        $hashesB = array_keys(SentenceHasher::hashSentences($title, $contentB));

        $news = $this->makeNewsObject([
            'publish_title' => $title,
            'publish_content' => $contentB,
            'content_hashes' => ['cycles' => [
                ['hashes' => $hashesA],
                ['hashes' => $hashesB],
            ]],
        ]);
        $this->mockNewsFind($news);
        $this->fakeOpenAiResponses([
            "# $title\n$contentA",
            CreateResponse::fake(['choices' => [['message' => ['content' => 'not valid json']]]]),
        ]);

        $job = new ApplyNewsAnalysisJob(1);
        $job->handle();

        // Keeps current (state A) content since JSON parse failed, continues cycle
        $this->assertFalse($news->is_deep);
        $this->assertSame($contentA, $news->publish_content);
        Queue::assertPushed(AnalyzeNewsJob::class);
    }

    // --- Test 7: Normal apply preserves previous_analysis ---

    public function test_normal_apply_preserves_previous_analysis(): void
    {
        $analysisText = 'Так. Fix the title spelling';
        $news = $this->makeNewsObject([
            'analysis' => $analysisText,
            'content_hashes' => null,
        ]);
        $this->mockNewsFind($news);
        $this->fakeOpenAiResponses(["# Fixed Title\nFixed content."]);

        $job = new ApplyNewsAnalysisJob(1);
        $job->handle();

        $this->assertSame($analysisText, $news->previous_analysis);
        $this->assertNull($news->analysis);
    }

    // --- Test 8: Flip-flop AI receives both variants ---

    public function test_flipflop_ai_receives_both_variants(): void
    {
        $title = 'Title';
        $contentA = 'У найкращому у світі місці.';
        $contentB = 'У найкращому в світі місці.';
        $hashesA = array_keys(SentenceHasher::hashSentences($title, $contentA));
        $hashesB = array_keys(SentenceHasher::hashSentences($title, $contentB));

        $news = $this->makeNewsObject([
            'publish_title' => $title,
            'publish_content' => $contentB, // model has state B
            'content_hashes' => ['cycles' => [
                ['hashes' => $hashesA],
                ['hashes' => $hashesB],
            ]],
        ]);
        $this->mockNewsFind($news);
        // AI returns state A, selection picks A
        $this->fakeOpenAiResponses([
            "# $title\n$contentA",
            CreateResponse::fake(['choices' => [['message' => ['content' => '{"1": "A"}']]]])
        ]);

        $job = new ApplyNewsAnalysisJob(1);
        $job->handle();

        // AI chose A → keeps the current (restored) content, continues cycle
        $this->assertSame($contentA, $news->publish_content);
        $this->assertFalse($news->is_deep); // no escalation
        Queue::assertPushed(AnalyzeNewsJob::class);
    }
}
