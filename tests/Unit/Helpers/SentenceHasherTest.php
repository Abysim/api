<?php

namespace Tests\Unit\Helpers;

use App\Helpers\SentenceHasher;
use PHPUnit\Framework\TestCase;

class SentenceHasherTest extends TestCase
{
    // --- splitSentences ---

    public function test_split_sentences_basic(): void
    {
        $result = SentenceHasher::splitSentences('First sentence. Second sentence.');
        $this->assertCount(2, $result);
        $this->assertSame('First sentence.', $result[0]);
        $this->assertSame('Second sentence.', $result[1]);
    }

    public function test_split_sentences_ukrainian_abbreviations(): void
    {
        $result = SentenceHasher::splitSentences('Проф. Іваненко працює в м. Київ.');
        $this->assertCount(1, $result);
        $this->assertSame('Проф. Іваненко працює в м. Київ.', $result[0]);
    }

    public function test_split_sentences_multiple_terminators(): void
    {
        $result = SentenceHasher::splitSentences('Really? Yes! Done.');
        $this->assertCount(3, $result);
    }

    public function test_split_sentences_empty_string(): void
    {
        $this->assertSame([], SentenceHasher::splitSentences(''));
    }

    public function test_split_sentences_whitespace_only(): void
    {
        $this->assertSame([], SentenceHasher::splitSentences('   '));
    }

    public function test_split_sentences_no_terminator(): void
    {
        $result = SentenceHasher::splitSentences('Single sentence without period');
        $this->assertCount(1, $result);
    }

    public function test_split_sentences_abbreviation_тисяч(): void
    {
        $result = SentenceHasher::splitSentences('Близько 4 тис. тигрів залишилось.');
        $this->assertCount(1, $result);
    }

    // --- hashSentences ---

    public function test_hash_sentences_includes_title(): void
    {
        $result = SentenceHasher::hashSentences('My Title', 'Some content.');
        $titleHash = md5(mb_strtolower(preg_replace('/\s+/', ' ', 'title:My Title')));
        $this->assertArrayHasKey($titleHash, $result);
        $this->assertSame('title:My Title', $result[$titleHash]);
    }

    public function test_hash_sentences_position_independent(): void
    {
        $result1 = SentenceHasher::hashSentences('T', 'First. Second.');
        $result2 = SentenceHasher::hashSentences('T', 'Second. First.');
        // Same hash keys regardless of order
        $keys1 = array_keys($result1);
        $keys2 = array_keys($result2);
        sort($keys1);
        sort($keys2);
        $this->assertSame($keys1, $keys2);
    }

    public function test_hash_sentences_whitespace_normalized(): void
    {
        $result1 = SentenceHasher::hashSentences('Title', 'Hello world.');
        $result2 = SentenceHasher::hashSentences('Title', 'Hello   world.');
        $this->assertSame(array_keys($result1), array_keys($result2));
    }

    public function test_hash_sentences_empty_content(): void
    {
        $result = SentenceHasher::hashSentences('Title', '');
        $this->assertCount(1, $result);
        $titleHash = md5(mb_strtolower('title:title'));
        $this->assertArrayHasKey($titleHash, $result);
    }

    // --- detectFlipFlops ---

    public function test_detect_flipflops_first_cycle(): void
    {
        $result = SentenceHasher::detectFlipFlops(['h1', 'h2', 'h3'], []);
        $this->assertFalse($result['is_all_flipflop']);
        $this->assertNull($result['matched_cycle']);
        $this->assertSame(3, $result['total_changes']);
        $this->assertSame(0, $result['flipflop_changes']);
        $this->assertEmpty($result['flipflop_hashes']);
    }

    public function test_detect_flipflops_a_b_a_set_match(): void
    {
        // Cycle 0: {h1, h2, h3} (state A)
        // Cycle 1: {h1, h4, h3} (state B — h2 replaced by h4)
        // Current:  {h1, h2, h3} (state A again)
        $priorCycles = [
            ['hashes' => ['h1', 'h2', 'h3']],
            ['hashes' => ['h1', 'h4', 'h3']],
        ];
        $result = SentenceHasher::detectFlipFlops(['h1', 'h2', 'h3'], $priorCycles);

        $this->assertTrue($result['is_all_flipflop']);
        $this->assertSame(0, $result['matched_cycle']);
        $this->assertContains('h2', $result['additions']);
        $this->assertContains('h4', $result['removals']);
        $this->assertSame(2, $result['total_changes']);
        $this->assertContains('h2', $result['flipflop_hashes']);
    }

    public function test_detect_flipflops_a_b_c_a_set_match(): void
    {
        // Cycle 0: {h1, h2} (state A)
        // Cycle 1: {h1, h3} (state B)
        // Cycle 2: {h1, h4} (state C)
        // Current:  {h1, h2} (state A again)
        $priorCycles = [
            ['hashes' => ['h1', 'h2']],
            ['hashes' => ['h1', 'h3']],
            ['hashes' => ['h1', 'h4']],
        ];
        $result = SentenceHasher::detectFlipFlops(['h1', 'h2'], $priorCycles);

        $this->assertTrue($result['is_all_flipflop']);
        $this->assertSame(0, $result['matched_cycle']);
        $this->assertContains('h2', $result['flipflop_hashes']);
    }

    public function test_detect_flipflops_partial_no_set_match(): void
    {
        // Cycle 0: {h1, h2, h3}
        // Cycle 1: {h1, h4, h3}
        // Current:  {h1, h2, h5} — h2 returns (flip-flop) but h5 is new
        $priorCycles = [
            ['hashes' => ['h1', 'h2', 'h3']],
            ['hashes' => ['h1', 'h4', 'h3']],
        ];
        $result = SentenceHasher::detectFlipFlops(['h1', 'h2', 'h5'], $priorCycles);

        $this->assertFalse($result['is_all_flipflop']);
        $this->assertSame(1, $result['flipflop_changes']); // h2 is a flip-flop addition
        $this->assertGreaterThan(0, $result['total_changes']);
        $this->assertContains('h2', $result['flipflop_hashes']);
        $this->assertNotContains('h5', $result['flipflop_hashes']);
    }

    public function test_detect_flipflops_set_match_requires_two_prior_cycles(): void
    {
        // Only 1 prior cycle — can't detect A→B→A
        $priorCycles = [
            ['hashes' => ['h1', 'h2', 'h3']],
        ];
        $result = SentenceHasher::detectFlipFlops(['h1', 'h4', 'h3'], $priorCycles);

        $this->assertFalse($result['is_all_flipflop']);
    }

    public function test_detect_flipflops_no_changes(): void
    {
        $priorCycles = [
            ['hashes' => ['h1', 'h2']],
            ['hashes' => ['h1', 'h3']],
        ];
        $result = SentenceHasher::detectFlipFlops(['h1', 'h3'], $priorCycles);

        $this->assertSame(0, $result['total_changes']);
        $this->assertEmpty($result['additions']);
        $this->assertEmpty($result['removals']);
        $this->assertEmpty($result['flipflop_hashes']);
    }

    public function test_detect_flipflops_additions_and_removals_computed(): void
    {
        $priorCycles = [
            ['hashes' => ['h1', 'h2']],
            ['hashes' => ['h1', 'h3']],
        ];
        // Current: {h1, h4} — h3 removed, h4 added
        $result = SentenceHasher::detectFlipFlops(['h1', 'h4'], $priorCycles);

        $this->assertContains('h4', $result['additions']);
        $this->assertContains('h3', $result['removals']);
        $this->assertSame(2, $result['total_changes']);
    }

    public function test_detect_flipflops_no_match_different_sets(): void
    {
        // No prior set matches current, even though h2 is a flip-flop addition
        $priorCycles = [
            ['hashes' => ['h1', 'h2', 'h3']],
            ['hashes' => ['h1', 'h4', 'h3']],
        ];
        // Current: {h1, 'h2', 'h6'} — doesn't match cycle 0 {h1, h2, h3}
        $result = SentenceHasher::detectFlipFlops(['h1', 'h2', 'h6'], $priorCycles);

        $this->assertFalse($result['is_all_flipflop']);
        $this->assertSame(1, $result['flipflop_changes']); // h2 is flip-flop
        $this->assertContains('h2', $result['flipflop_hashes']);
    }

    public function test_detect_flipflops_returns_flipflop_hashes(): void
    {
        // h2 is a flip-flop (was in cycle 0), h5 is genuine new
        $priorCycles = [
            ['hashes' => ['h1', 'h2', 'h3']],
            ['hashes' => ['h1', 'h4', 'h3']],
        ];
        $result = SentenceHasher::detectFlipFlops(['h1', 'h2', 'h5'], $priorCycles);

        $this->assertSame(['h2'], $result['flipflop_hashes']);
        $this->assertSame(1, $result['flipflop_changes']);
    }

    public function test_detect_flipflops_multiple_flipflops_with_genuine(): void
    {
        // h2 and h3 are flip-flops (from cycle 0), h7 is genuine new
        $priorCycles = [
            ['hashes' => ['h1', 'h2', 'h3', 'h4']],
            ['hashes' => ['h1', 'h5', 'h6', 'h4']],
        ];
        $result = SentenceHasher::detectFlipFlops(['h1', 'h2', 'h3', 'h7'], $priorCycles);

        $this->assertSame(['h2', 'h3'], $result['flipflop_hashes']);
        $this->assertSame(2, $result['flipflop_changes']);
        $this->assertNotContains('h7', $result['flipflop_hashes']);
        $this->assertFalse($result['is_all_flipflop']);
    }

    // --- isOldFormat ---

    public function test_is_old_format_flat_array(): void
    {
        $this->assertTrue(SentenceHasher::isOldFormat(['abc123', 'def456']));
    }

    public function test_is_old_format_empty_array(): void
    {
        $this->assertTrue(SentenceHasher::isOldFormat([]));
    }

    public function test_is_old_format_new_structure(): void
    {
        $this->assertFalse(SentenceHasher::isOldFormat(['cycles' => []]));
    }

    public function test_is_old_format_null(): void
    {
        $this->assertFalse(SentenceHasher::isOldFormat(null));
    }

    // --- buildVariantSelectionPrompt ---

    public function test_build_variant_selection_prompt(): void
    {
        $pairs = [
            ['current' => 'У найкращому у світі місці.', 'prior' => 'У найкращому в світі місці.'],
        ];
        $prompt = SentenceHasher::buildVariantSelectionPrompt($pairs);

        $this->assertStringContainsString('1.', $prompt);
        $this->assertStringContainsString('A: У найкращому у світі місці.', $prompt);
        $this->assertStringContainsString('B: У найкращому в світі місці.', $prompt);
        $this->assertStringContainsString('JSON', $prompt);
    }

    public function test_build_variant_selection_prompt_title_labeled(): void
    {
        $pairs = [
            ['current' => 'title:Заголовок А', 'prior' => 'title:Заголовок Б'],
        ];
        $prompt = SentenceHasher::buildVariantSelectionPrompt($pairs);

        $this->assertStringContainsString('Заголовок:', $prompt);
        $this->assertStringContainsString('A: Заголовок А', $prompt);
        $this->assertStringContainsString('B: Заголовок Б', $prompt);
        // Ensure "title:" prefix is stripped from displayed text
        $this->assertStringNotContainsString('title:', $prompt);
    }

    public function test_build_variant_selection_prompt_with_article_context(): void
    {
        $pairs = [
            ['current' => 'Вищі хижаки.', 'prior' => 'Вершинні хижаки.'],
        ];
        $context = "Заголовок статті\n\nОцелоти є вершинними хижаками екосистеми.";
        $prompt = SentenceHasher::buildVariantSelectionPrompt($pairs, $context);

        $this->assertStringContainsString('Контекст статті:', $prompt);
        $this->assertStringContainsString($context, $prompt);
        $this->assertStringContainsString('===', $prompt);
        $this->assertStringContainsString('A: Вищі хижаки.', $prompt);
        $this->assertStringContainsString('B: Вершинні хижаки.', $prompt);
    }

    public function test_build_variant_selection_prompt_without_context_unchanged(): void
    {
        $pairs = [
            ['current' => 'Sentence A.', 'prior' => 'Sentence B.'],
        ];
        $promptWithout = SentenceHasher::buildVariantSelectionPrompt($pairs);
        $promptEmpty = SentenceHasher::buildVariantSelectionPrompt($pairs, '');

        $this->assertSame($promptWithout, $promptEmpty);
        $this->assertStringNotContainsString('Контекст статті:', $promptWithout);
    }
}
