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
            'publish_content' => 'Original content sentence.',
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

    // --- Test 5: Partial flip-flop resolves via variant selection ---

    public function test_partial_flipflop_resolves_via_variant_selection(): void
    {
        $title = 'Title';
        // Cycle 0: "Sentence A. Sentence B. Sentence E." — Cycle 1: "Sentence A. Sentence C. Sentence E."
        // Current apply returns: "Sentence A. Sentence B. Sentence D." (same count: 3)
        // → "Sentence B." is a flip-flop (was in cycle 0), "Sentence D." is genuine new
        $hashesABE = array_keys(SentenceHasher::hashSentences($title, 'Sentence A. Sentence B. Sentence E.'));
        $hashesACE = array_keys(SentenceHasher::hashSentences($title, 'Sentence A. Sentence C. Sentence E.'));

        $news = $this->makeNewsObject([
            'publish_title' => $title,
            'publish_content' => 'Sentence A. Sentence C. Sentence E.',
            'content_hashes' => ['cycles' => [
                ['hashes' => $hashesABE],
                ['hashes' => $hashesACE],
            ]],
        ]);
        $this->mockNewsFind($news);
        // First response: editor output. Second response: variant selector picks B (prior version)
        $this->fakeOpenAiResponses([
            "# $title\nSentence A. Sentence B. Sentence D.",
            CreateResponse::fake(['choices' => [['message' => ['content' => '{"1": "B"}']]]])
        ]);

        $job = new ApplyNewsAnalysisJob(1);
        $job->handle();

        $this->assertFalse($news->is_deep);
        // AI chose B (prior) → flip-flop sentence reverted to "Sentence C.", genuine "Sentence D." preserved
        $this->assertStringContainsString('Sentence C.', $news->publish_content);
        $this->assertStringContainsString('Sentence D.', $news->publish_content);
        $this->assertStringNotContainsString('Sentence B.', $news->publish_content);
        $this->assertArrayHasKey('cycles', $news->content_hashes);
        $this->assertCount(3, $news->content_hashes['cycles']);
        Queue::assertPushed(AnalyzeNewsJob::class);
    }

    // --- Test 5b: Partial flip-flop position-based pairing correctness ---

    public function test_partial_flipflop_position_based_pairing(): void
    {
        $title = 'Title';
        // 3 content sentences. Middle one will flip-flop, last one changes genuinely.
        // Cycle 0: "First. Вищі хижаки. Third."
        // Cycle 1: "First. Вершинні хижаки. Third."  (middle changed)
        // Current: "First. Вищі хижаки. Fourth."      (middle reverts = flip-flop, last = genuine)
        $content0 = 'First. Вищі хижаки. Third.';
        $content1 = 'First. Вершинні хижаки. Third.';
        $contentCurrent = 'First. Вищі хижаки. Fourth.';
        $hashes0 = array_keys(SentenceHasher::hashSentences($title, $content0));
        $hashes1 = array_keys(SentenceHasher::hashSentences($title, $content1));

        $news = $this->makeNewsObject([
            'publish_title' => $title,
            'publish_content' => $content1, // model has state from cycle 1
            'content_hashes' => ['cycles' => [
                ['hashes' => $hashes0],
                ['hashes' => $hashes1],
            ]],
        ]);
        $this->mockNewsFind($news);
        // Editor returns content with flip-flop. Variant selector picks B (prior = "Вершинні хижаки.")
        $this->fakeOpenAiResponses([
            "# $title\n$contentCurrent",
            CreateResponse::fake(['choices' => [['message' => ['content' => '{"1": "B"}']]]])
        ]);

        $job = new ApplyNewsAnalysisJob(1);
        $job->handle();

        // AI chose B → flip-flop sentence reverted to "Вершинні хижаки."
        // Genuine change "Fourth." preserved (was not sent to variant selection)
        $this->assertStringContainsString('Вершинні хижаки.', $news->publish_content);
        $this->assertStringContainsString('Fourth.', $news->publish_content);
        $this->assertStringNotContainsString('Вищі хижаки.', $news->publish_content);
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

    // --- Test 9: flipflop_ni_count increments when all changes are flip-flops ---

    public function test_flipflop_ni_count_increments_when_all_changes_are_flipflops(): void
    {
        $title = 'Title';
        $contentA = 'Ягуари є верхівковими хижаками.';
        $contentB = 'Ягуари є вищими хижаками.';
        $hashesA = array_keys(SentenceHasher::hashSentences($title, $contentA));
        $hashesB = array_keys(SentenceHasher::hashSentences($title, $contentB));

        $news = $this->makeNewsObject([
            'publish_title' => $title,
            'publish_content' => $contentB,
            'content_hashes' => [
                'cycles' => [
                    ['hashes' => $hashesA], // Cycle 0: state A
                    ['hashes' => $hashesB], // Cycle 1: state B (last)
                ],
                'flipflop_ni_count' => 0,
            ],
        ]);
        $this->mockNewsFind($news);
        // Editor returns state A (flip-flop), variant selector picks A (keep current)
        $this->fakeOpenAiResponses([
            "# $title\n$contentA",
            CreateResponse::fake(['choices' => [['message' => ['content' => '{"1": "A"}']]]])
        ]);

        $job = new ApplyNewsAnalysisJob(1);
        $job->handle();

        // Counter incremented from 0 to 1
        $this->assertSame(1, $news->content_hashes['flipflop_ni_count']);
        // Does NOT escalate (count < 2)
        $this->assertFalse($news->is_deep);
        Queue::assertPushed(AnalyzeNewsJob::class);
    }

    // --- Test 10: flipflop_ni_count resets on real (non-flip-flop) changes ---

    public function test_flipflop_ni_count_resets_on_real_changes(): void
    {
        $title = 'Title';
        $contentOld = 'Old content sentence.';
        $contentNew = 'Completely new content sentence.';
        $hashesOld = array_keys(SentenceHasher::hashSentences($title, $contentOld));

        $news = $this->makeNewsObject([
            'publish_title' => $title,
            'publish_content' => $contentOld,
            'content_hashes' => [
                'cycles' => [
                    ['hashes' => $hashesOld],
                ],
                'flipflop_ni_count' => 1, // had a flip-flop before
            ],
        ]);
        $this->mockNewsFind($news);
        // Editor returns genuinely new content (no overlap with older cycles)
        $this->fakeOpenAiResponses(["# $title\n$contentNew"]);

        $job = new ApplyNewsAnalysisJob(1);
        $job->handle();

        // Counter resets to 0 — real changes happened
        $this->assertSame(0, $news->content_hashes['flipflop_ni_count']);
        $this->assertFalse($news->is_deep);
        Queue::assertPushed(AnalyzeNewsJob::class);
    }

    // --- Test 11: Flip-flop escalation to deep at count=2 ---

    public function test_flipflop_escalation_to_deep_at_count_2(): void
    {
        $title = 'Title';
        $contentA = 'Ягуари є верхівковими хижаками.';
        $contentB = 'Ягуари є вищими хижаками.';
        $hashesA = array_keys(SentenceHasher::hashSentences($title, $contentA));
        $hashesB = array_keys(SentenceHasher::hashSentences($title, $contentB));

        $news = $this->makeNewsObject([
            'publish_title' => $title,
            'publish_content' => $contentB,
            'analysis_count' => 5,
            'content_hashes' => [
                'cycles' => [
                    ['hashes' => $hashesA],
                    ['hashes' => $hashesB],
                ],
                'flipflop_ni_count' => 1, // one prior flip-flop-only detection
            ],
            'is_deep' => false,
        ]);
        $this->mockNewsFind($news);
        $this->mockTelegramRequest();
        // Editor returns state A (flip-flop), variant selector picks A
        $this->fakeOpenAiResponses([
            "# $title\n$contentA",
            CreateResponse::fake(['choices' => [['message' => ['content' => '{"1": "A"}']]]])
        ]);

        $job = new ApplyNewsAnalysisJob(1);
        $job->handle();

        // Counter incremented to 2 → flip-flop escalation to deep
        $this->assertTrue($news->is_deep);
        $this->assertSame(0, $news->analysis_count);
        $this->assertNull($news->content_hashes);
        $this->assertNull($news->previous_analysis);
        $this->assertFalse($news->is_deepest);
        Queue::assertPushed(AnalyzeNewsJob::class);
    }

    // --- Test 12: Flip-flop escalation to deepest at count=2 (already deep) ---

    public function test_flipflop_escalation_to_deepest_at_count_2(): void
    {
        $title = 'Title';
        $contentA = 'Ягуари є верхівковими хижаками.';
        $contentB = 'Ягуари є вищими хижаками.';
        $hashesA = array_keys(SentenceHasher::hashSentences($title, $contentA));
        $hashesB = array_keys(SentenceHasher::hashSentences($title, $contentB));

        $news = $this->makeNewsObject([
            'publish_title' => $title,
            'publish_content' => $contentB,
            'analysis_count' => 3,
            'content_hashes' => [
                'cycles' => [
                    ['hashes' => $hashesA],
                    ['hashes' => $hashesB],
                ],
                'flipflop_ni_count' => 1,
            ],
            'is_deep' => true, // already deep
            'is_deepest' => false,
        ]);
        $this->mockNewsFind($news);
        $this->mockTelegramRequest();
        // Editor returns state A (flip-flop), variant selector picks A
        $this->fakeOpenAiResponses([
            "# $title\n$contentA",
            CreateResponse::fake(['choices' => [['message' => ['content' => '{"1": "A"}']]]])
        ]);

        $job = new ApplyNewsAnalysisJob(1);
        $job->handle();

        // Counter incremented to 2 → flip-flop escalation to deepest (already deep)
        $this->assertTrue($news->is_deepest);
        $this->assertFalse($news->is_auto);
        $this->assertNull($news->content_hashes);
        $this->assertNull($news->previous_analysis);
        Queue::assertNotPushed(AnalyzeNewsJob::class); // stops, no more dispatching
    }

    // --- Test 13: Zero-change cycles do not affect flipflop_ni_count ---

    public function test_zero_changes_do_not_affect_flipflop_ni_count(): void
    {
        $title = 'Same Title';
        $content = 'Same content.';
        $hashTexts = SentenceHasher::hashSentences($title, $content);

        $news = $this->makeNewsObject([
            'publish_title' => $title,
            'publish_content' => $content,
            'content_hashes' => [
                'cycles' => [
                    ['hashes' => array_keys($hashTexts)],
                    ['hashes' => array_keys($hashTexts)],
                ],
                'flipflop_ni_count' => 1, // had a flip-flop before
            ],
        ]);
        $this->mockNewsFind($news);
        // Editor returns identical content (zero changes)
        $this->fakeOpenAiResponses(["# $title\n$content"]);

        $job = new ApplyNewsAnalysisJob(1);
        $job->handle();

        // Counter persists at 1 — zero-change path does not modify content_hashes
        $this->assertSame(1, $news->content_hashes['flipflop_ni_count']);
        $this->assertFalse($news->is_deep);
        Queue::assertPushed(AnalyzeNewsJob::class);
    }

    // --- extractCorrectionsList tests ---

    public function test_extract_corrections_numbered_with_bold_and_headers(): void
    {
        $analysis = "Так.\n\n**Орфографічна помилка:**\n\n1. **«вічноземельних»** → **«вічнозелених»** — слова «вічноземельний» не існує.\n\n**Граматичні помилки:**\n\n2. **«островів Балі та Ява»** → **«островів Балі та Яви»** — родовий відмінок.";

        $result = ApplyNewsAnalysisJob::extractCorrectionsList($analysis);

        $this->assertStringContainsString('вічноземельних', $result);
        $this->assertStringContainsString('островів Балі та Яви', $result);
        $this->assertStringNotContainsString('**', $result);
        $this->assertStringNotContainsString('Орфографічна помилка', $result);
        $this->assertStringNotContainsString('Граматичні помилки', $result);
        $this->assertSame(2, substr_count($result, "\n") + 1);
    }

    public function test_extract_corrections_with_markdown_headers(): void
    {
        $analysis = "Так.\n\n### Чергування і/й\n\n1. **«довгі, і часто»** → **«довгі, й часто»** — після голосної.\n\n### Чергування у/в\n\n2. **«а у прохолодніших»** → **«а в прохолодніших»** — після голосної.";

        $result = ApplyNewsAnalysisJob::extractCorrectionsList($analysis);

        $this->assertStringContainsString('довгі, й часто', $result);
        $this->assertStringContainsString('а в прохолодніших', $result);
        $this->assertStringNotContainsString('###', $result);
        $this->assertStringNotContainsString('**', $result);
    }

    public function test_extract_corrections_simple_numbered_no_headers(): void
    {
        $analysis = "Так.\n\n1. «у світі» → «в світі» — чергування у/в.\n2. «і зник» → «й зник» — чергування і/й.";

        $result = ApplyNewsAnalysisJob::extractCorrectionsList($analysis);

        $this->assertStringContainsString('у світі', $result);
        $this->assertStringContainsString('і зник', $result);
        $this->assertSame(2, substr_count($result, "\n") + 1);
    }

    public function test_extract_corrections_prose_fallback(): void
    {
        $analysis = 'Так. Виправлення: «у світі» → «в світі»';

        $result = ApplyNewsAnalysisJob::extractCorrectionsList($analysis);

        // Prose format has no numbered items — falls back to full analysis
        $this->assertSame($analysis, $result);
    }

    public function test_extract_corrections_dash_prefixed(): void
    {
        $analysis = "Так.\n\n- «у світі» → «в світі» — чергування у/в\n- «і зник» → «й зник» — чергування і/й";

        $result = ApplyNewsAnalysisJob::extractCorrectionsList($analysis);

        $this->assertStringContainsString('у світі', $result);
        $this->assertStringContainsString('і зник', $result);
        $this->assertSame(2, substr_count($result, "\n") + 1);
    }

    public function test_extract_corrections_empty_input(): void
    {
        $result = ApplyNewsAnalysisJob::extractCorrectionsList('');
        $this->assertSame('', $result);
    }
}
