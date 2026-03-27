<?php

namespace App\Helpers;

class SentenceHasher
{
    private const ABBREV_PATTERN = '/\b(т\.д|т\.п|т\.ч|н\.е|ім|напр|проф|акад|канд|вул|тис|млн|млрд|рис|табл|стор|д-р|обл|м|с|о|р|п)\./ui';

    public static function splitSentences(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $protected = preg_replace_callback(self::ABBREV_PATTERN, fn($m) => $m[1] . "\x00", $text);

        // Split on sentence-ending punctuation followed by whitespace + uppercase letter
        $parts = preg_split(
            '/(?<=[.!?])\s+(?=[А-ЯІЇЄҐA-Z])/u',
            $protected,
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        // Restore periods from placeholders and trim
        return array_values(array_filter(
            array_map(fn($s) => trim(str_replace("\x00", '.', $s)), $parts),
            fn($s) => $s !== ''
        ));
    }

    /**
     * Compute sentence hashes for title + content.
     * Returns [hash => original_sentence_text, ...].
     * Title is prefixed with "title:" to distinguish from content sentences.
     * Only array_keys() should be stored in DB; values are for runtime use.
     */
    public static function hashSentences(string $title, string $content): array
    {
        $result = [];

        $titleEntry = 'title:' . $title;
        $result[self::hash($titleEntry)] = trim($titleEntry);

        foreach (self::splitSentences($content) as $sentence) {
            $result[self::hash($sentence)] = trim($sentence);
        }

        return $result;
    }

    public static function hash(string $text): string
    {
        return md5(self::normalize($text));
    }

    private static function normalize(string $text): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($text)));
    }

    public static function stripTitlePrefix(string $text): string
    {
        return str_starts_with($text, 'title:') ? substr($text, 6) : $text;
    }

    /**
     * Detect flip-flops using two-level analysis:
     * Level 1 (primary): Set comparison — detects full article reversion (A→B→A)
     * Level 2 (secondary): Additions/removals — for logging and AI prompt building
     */
    public static function detectFlipFlops(array $currentHashes, array $priorCycles): array
    {
        $result = [
            'is_all_flipflop' => false,
            'matched_cycle' => null,
            'additions' => [],
            'removals' => [],
            'total_changes' => 0,
            'flipflop_changes' => 0,
            'flipflop_hashes' => [],
        ];

        if (empty($priorCycles)) {
            $result['additions'] = $currentHashes;
            $result['total_changes'] = count($currentHashes);
            return $result;
        }

        // Level 2: Compute additions and removals vs last cycle
        $lastCycle = end($priorCycles);
        $lastHashes = $lastCycle['hashes'] ?? [];
        $result['additions'] = array_values(array_diff($currentHashes, $lastHashes));
        $result['removals'] = array_values(array_diff($lastHashes, $currentHashes));
        $result['total_changes'] = count($result['additions']) + count($result['removals']);

        // Single pass over older cycles for both flip-flop counting and set comparison
        $olderCycles = array_slice($priorCycles, 0, -1);
        $olderHashes = [];
        $sortedCurrent = $currentHashes;
        sort($sortedCurrent);

        foreach ($olderCycles as $index => $cycle) {
            $cycleHashes = $cycle['hashes'] ?? [];
            foreach ($cycleHashes as $hash) {
                $olderHashes[$hash] = true;
            }
            // Level 1: Set comparison for full reversion (needs ≥2 prior cycles)
            if (!$result['is_all_flipflop'] && count($priorCycles) >= 2) {
                $sortedPrior = $cycleHashes;
                sort($sortedPrior);
                if ($sortedCurrent === $sortedPrior) {
                    $result['is_all_flipflop'] = true;
                    $result['matched_cycle'] = $index;
                }
            }
        }

        // Level 2: Count partial flip-flops (additions that appeared in older cycles)
        foreach ($result['additions'] as $hash) {
            if (isset($olderHashes[$hash])) {
                $result['flipflop_changes']++;
                $result['flipflop_hashes'][] = $hash;
            }
        }

        return $result;
    }

    /**
     * Check if stored content_hashes is in the old flat-array format.
     */
    public static function isOldFormat(mixed $data): bool
    {
        return is_array($data) && !isset($data['cycles']);
    }

    /**
     * Build the AI variant selection prompt for flip-flop resolution.
     * Each entry in $flipflopSentences has 'current' and 'prior' keys.
     */
    public static function buildVariantSelectionPrompt(array $flipflopSentences, string $articleContext = ''): string
    {
        $prompt = "Для кожної пари речень обери кращий варіант (A або B) за правилами української мови. Відповідай ТІЛЬКИ у форматі JSON: {\"1\": \"A\", \"2\": \"B\", ...}\n\n";

        if ($articleContext !== '') {
            $prompt = "Контекст статті:\n$articleContext\n\n===\n\n" . $prompt;
        }

        foreach ($flipflopSentences as $i => $pair) {
            $num = $i + 1;
            $currentText = $pair['current'] ?? '';
            $priorText = $pair['prior'] ?? '';
            $isTitle = str_starts_with($currentText, 'title:') || str_starts_with($priorText, 'title:');
            $label = $isTitle ? ' Заголовок:' : '';
            $currentClean = self::stripTitlePrefix($currentText);
            $priorClean = self::stripTitlePrefix($priorText);
            $prompt .= "$num.$label\nA: $currentClean\nB: $priorClean\n\n";
        }

        return trim($prompt);
    }

    /**
     * Extract correction pairs from analysis text.
     * Parses «old» → «new» format with optional **bold** wrapping.
     * Returns array of ['old' => normalized, 'new' => normalized, 'raw_old' => original, 'raw_new' => original, 'full_match' => matched string].
     */
    public static function extractCorrectionPairs(string $analysis): array
    {
        $pairs = [];
        if (preg_match_all('/\*{0,2}«([^»]+)»\*{0,2}\s*→\s*\*{0,2}«([^»]+)»\*{0,2}/u', $analysis, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $pairs[] = [
                    'old' => self::normalizePair($match[1]),
                    'new' => self::normalizePair($match[2]),
                    'raw_old' => $match[1],
                    'raw_new' => $match[2],
                    'full_match' => $match[0],
                ];
            }
        }
        return $pairs;
    }

    public static function normalizePair(string $text): string
    {
        return self::normalize($text);
    }

    /**
     * Detect flip-flops at the correction-pair level.
     * Catches sub-sentence oscillations invisible to sentence-level hashing.
     *
     * @param array $currentPairs Pairs from extractCorrectionPairs() for current analysis
     * @param array $priorPairCycles Array of arrays — each element is one cycle's stored pairs [['old'=>..,'new'=>..], ...]
     * @return array ['flipflops' => [...]]
     */
    public static function detectPairFlipFlops(array $currentPairs, array $priorPairCycles): array
    {
        $result = ['flipflops' => []];

        if (empty($priorPairCycles) || empty($currentPairs)) {
            return $result;
        }

        // Build lookup maps from all prior cycles
        $priorNewValues = []; // normalized new_value => first cycle index
        $priorOldValues = []; // normalized old_value => first cycle index

        foreach ($priorPairCycles as $cycleIdx => $cyclePairs) {
            foreach ($cyclePairs as $pair) {
                if (!isset($priorNewValues[$pair['new'] ?? ''])) {
                    $priorNewValues[$pair['new'] ?? ''] = $cycleIdx;
                }
                if (!isset($priorOldValues[$pair['old'] ?? ''])) {
                    $priorOldValues[$pair['old'] ?? ''] = $cycleIdx;
                }
            }
        }

        foreach ($currentPairs as $pairIdx => $pair) {
            // Direct reversal: current correction undoes a prior correction
            // current.old == prior.new means "the text we want to change was itself produced by a prior correction"
            if (isset($priorNewValues[$pair['old']])) {
                $result['flipflops'][] = [
                    'pair_index' => $pairIdx,
                    'type' => 'direct_reversal',
                    'matched_cycle' => $priorNewValues[$pair['old']],
                ];
                continue; // Direct reversal is more specific than drift; skip drift check
            }

            // 3-state drift: current.new matches a prior.old (A→B→...→A)
            if (isset($priorOldValues[$pair['new']])) {
                $result['flipflops'][] = [
                    'pair_index' => $pairIdx,
                    'type' => 'drift',
                    'matched_cycle' => $priorOldValues[$pair['new']],
                ];
            }
        }

        return $result;
    }
}
