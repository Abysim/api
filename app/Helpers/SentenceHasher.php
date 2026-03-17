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
        return md5(mb_strtolower(preg_replace('/\s+/', ' ', trim($text))));
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
    public static function buildVariantSelectionPrompt(array $flipflopSentences): string
    {
        $prompt = "Для кожної пари речень обери кращий варіант (A або B) за правилами української мови. Відповідай ТІЛЬКИ у форматі JSON: {\"1\": \"A\", \"2\": \"B\", ...}\n\n";

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
}
