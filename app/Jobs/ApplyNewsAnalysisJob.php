<?php

namespace App\Jobs;

use App\AI;
use App\Enums\NewsStatus;
use App\Http\Controllers\NewsController;
use App\Models\News;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Helpers\SentenceHasher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use OpenAI\Laravel\Facades\OpenAI;

class ApplyNewsAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TIMEOUT = 1800;

    public const CACHE_KEY_PREFIX = 'apply_job_state_';

    public int $tries = 0;

    public int $maxExceptions = 2;

    public int $timeout = self::TIMEOUT;

    public function __construct(private readonly int $id)
    {
    }

    /**
     * @throws TelegramException
     */
    public function handle(): void
    {
        $model = News::find($this->id);
        if (!$model) {
            return;
        }

        // PID guard — must check status BEFORE content to catch dead-worker resume
        if ($model->status == NewsStatus::BEING_PROCESSED) {
            $state = Cache::get(self::CACHE_KEY_PREFIX . $this->id);
            $pid = $state['pid'] ?? null;
            if ($pid && function_exists('posix_kill') && posix_kill($pid, 0)) {
                $this->release(config('queue.connections.database.retry_after'));
                return;
            }
            // Dead worker — resume, but only if there's actually work to apply
            if (empty($model->analysis) || Str::substr(trim($model->analysis, '*# '), 0, 2) == 'Ні') {
                return; // Stale job — article moved past Apply phase
            }
            $model->touch();
            Log::warning("$model->id: Resuming orphaned apply job");
            if (!empty($model->message_id)) {
                try {
                    Request::sendMessage([
                        'chat_id' => explode(',', config('telegram.admins'))[0],
                        'reply_to_message_id' => $model->message_id,
                        'text' => 'Resumed killed apply job',
                        'reply_markup' => new InlineKeyboard([['text' => '❌Delete', 'callback_data' => 'delete']]),
                    ]);
                } catch (TelegramException) {}
            }
        } elseif (
            empty($model->analysis)
            || Str::substr(trim($model->analysis, '*# '), 0, 2) == 'Ні'
        ) {
            return;
        }

        $model->status = NewsStatus::BEING_PROCESSED;
        $model->save();
        Cache::put(self::CACHE_KEY_PREFIX . $this->id, ['pid' => getmypid()], self::TIMEOUT + 120);

        $isScience = $model->platform == 'article';
        $preamble = trim(Str::before(NewsController::getPrompt('analyzer', $isScience), '1.'));
        // Rewrite analyzer preamble from first/second-person to third-person to avoid editor identity confusion
        $preamble = str_replace(['Ти — ', 'Твоя задача', 'Надавай одразу'], ['Це — ', 'Його задача', 'Надає одразу'], $preamble);
        $systemPrompt = str_replace(
            ['{analyzer_preamble}', '{date}'],
            [$preamble, $model->date->translatedFormat('j F Y')],
            NewsController::getPrompt('editor', $isScience)
        );

        // Correction-pair flip-flop detection (sub-sentence level)
        $correctionPairs = SentenceHasher::extractCorrectionPairs($model->analysis);
        $priorPairCycles = [];
        if (is_array($model->content_hashes) && !SentenceHasher::isOldFormat($model->content_hashes)) {
            foreach (($model->content_hashes['cycles'] ?? []) as $cycle) {
                $priorPairCycles[] = $cycle['pairs'] ?? [];
            }
        }
        $pairDetection = SentenceHasher::detectPairFlipFlops($correctionPairs, $priorPairCycles);
        $pairFlipFlops = $pairDetection['flipflops'];
        $analysisForEditor = $model->analysis;
        $pairFlipFlopWarning = '';
        $appliedPairs = array_map(fn($p) => ['old' => $p['old'], 'new' => $p['new']], $correctionPairs);

        if (!empty($pairFlipFlops)) {
            $warnings = [];
            foreach ($pairFlipFlops as $ff) {
                $pair = $correctionPairs[$ff['pair_index']];
                $warnings[] = "- «{$pair['raw_old']}» → «{$pair['raw_new']}»";
            }
            $pairFlipFlopWarning = "\n\nУВАГА — осциляція виправлень (ці виправлення скасовують раніше застосовані, їх пропущено):\n" . implode("\n", $warnings);
            Log::info("$model->id: Pair flip-flop detected: " . count($pairFlipFlops) . '/' . count($correctionPairs) . ' corrections are reversals');

            foreach ($pairFlipFlops as $ff) {
                $pair = $correctionPairs[$ff['pair_index']];
                $analysisForEditor = str_replace($pair['full_match'], '', $analysisForEditor);
            }
            $analysisForEditor = preg_replace('/^\s*(?:\d+\.|-)\s*[\n\r]/mu', '', $analysisForEditor);
            $analysisForEditor = preg_replace('/\n{3,}/', "\n\n", $analysisForEditor);

            // If ALL corrections are flip-flops, short-circuit to "no changes" path
            if (count($pairFlipFlops) >= count($correctionPairs)) {
                Log::info("$model->id: All corrections are pair flip-flops, treating as no-change");
                $model->previous_analysis = $model->analysis . $pairFlipFlopWarning;
                $model->status = NewsStatus::PENDING_REVIEW;
                $model->analysis = null;
                $model->save();
                Cache::forget(self::CACHE_KEY_PREFIX . $this->id);
                if ($model->is_auto) {
                    AnalyzeNewsJob::dispatch($model->id);
                }
                return;
            }

            // Keep only non-flip-flop pairs for storage
            $flipflopIndices = array_flip(array_column($pairFlipFlops, 'pair_index'));
            $appliedPairs = array_values(array_filter($appliedPairs, fn($_, $idx) => !isset($flipflopIndices[$idx]), ARRAY_FILTER_USE_BOTH));
        }

        for ($i = 0; $i < 4; $i++) {
            try {
                Log::info("$model->id: News applying analysis $model->analysis_count $i");
                $params = [
                    'model' => $i > 1 ? 'openai/gpt-5.4-mini' : 'gpt-5.4-mini',
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        [
                            'role' => 'user',
                            'content' => '# ' . $model->publish_title . "\n\n" . $model->publish_content
                                . "\n\n---\nВиправлення філолога:\n" . $analysisForEditor,
                        ],
                    ],
                ];
                $originalSentenceCount = count(SentenceHasher::splitSentences($model->publish_content));

                if ($i > 1) {
                    $params['provider'] = ['require_parameters' => true];
                    $params['reasoning'] = ['effort' => 'high'];
                    $response = AI::client('openrouter')->chat()->create($params);
                } else {
                    $params['reasoning_effort'] = 'high';
                    $response = OpenAI::chat()->create($params);
                }

                Log::info(
                    "$model->id: News applying analysis $model->analysis_count $i result: "
                    . json_encode($response, JSON_UNESCAPED_UNICODE)
                );

                if (!empty($response->choices[0]->message->content)) {
                    $model->refresh();
                    if ($model->status != NewsStatus::BEING_PROCESSED) {
                        Cache::forget(self::CACHE_KEY_PREFIX . $this->id);
                        return;
                    }
                    $content = trim(Str::after($response->choices[0]->message->content, '```markdown'));
                    $parts = explode("\n", $content, 2);
                    $newTitle = trim($parts[0], '*# ');
                    $newContent = trim(trim($parts[1] ?? '', '`'));
                    $newContent = preg_replace('/\s*(\n\s*---\s*)+\s*$/', '', $newContent);

                    // Per-sentence oscillation detection
                    $hashTexts = SentenceHasher::hashSentences($newTitle, $newContent);
                    $currentHashes = array_keys($hashTexts);

                    // Sentence count guard — detect accidental deletions/additions by applier
                    // hashSentences includes one title: entry; subtract it to compare content-only counts
                    $newSentenceCount = count($hashTexts) - 1;
                    if ($originalSentenceCount !== $newSentenceCount) {
                        Log::warning("$model->id: Applier changed sentence count from $originalSentenceCount to $newSentenceCount at analysis_count $model->analysis_count $i");
                        try {
                            $corrections = self::extractCorrectionsList($model->analysis);
                            $verifyResponse = OpenAI::chat()->create([
                                'model' => 'gpt-5.4-mini',
                                'reasoning_effort' => 'high',
                                'messages' => [
                                    ['role' => 'system', 'content' => 'Ти — верифікатор тексту. Порівняй оригінал із виправленим текстом, враховуючи список виправлень. Об\'єднання або розділення речень, вказане у виправленнях, є очікуваною зміною кількості речень.'],
                                    ['role' => 'user', 'content' => "Оригінал:\n" . $model->publish_title . "\n\n" . $model->publish_content
                                        . "\n\n---\nВиправлений:\n" . $newTitle . "\n\n" . $newContent
                                        . "\n\n---\nВказані виправлення:\n" . $corrections
                                        . "\n\n---\nЧи є у виправленому тексті зміни, які НЕ відповідають вказаним виправленням (випадкові видалення, додавання або заміни)? Якщо всі зміни відповідають виправленням — відповідай OK. Інакше — перелічи лише ті зміни, які виходять за межі виправлень."],
                                ],
                            ]);
                            $verifyResult = trim($verifyResponse->choices[0]->message->content ?? '');
                            if (!preg_match('/^\*{0,2}OK\b/i', $verifyResult)) {
                                Log::warning("$model->id: Diff verification found issues: $verifyResult, retrying");
                                continue;
                            }
                        } catch (Exception $e) {
                            Log::warning("$model->id: Diff verification failed: {$e->getMessage()}, retrying");
                            continue;
                        }
                    }

                    // Load stored cycles (backward compatible)
                    $stored = $model->content_hashes;
                    $cycles = [];
                    if (is_array($stored) && !SentenceHasher::isOldFormat($stored)) {
                        $cycles = $stored['cycles'] ?? [];
                    }

                    // Detect flip-flops
                    $detection = SentenceHasher::detectFlipFlops($currentHashes, $cycles);
                    $allChangesAreFlipFlops = $detection['flipflop_changes'] > 0
                        && $detection['flipflop_changes'] === count($detection['additions']);

                    if ($detection['total_changes'] === 0) {
                        // No changes — applier produced identical content, let analyzer re-evaluate
                        Log::info("$model->id: No changes at analysis_count $model->analysis_count, letting analyzer re-evaluate");
                        $model->previous_analysis = $model->analysis . $pairFlipFlopWarning;
                        $model->status = NewsStatus::PENDING_REVIEW;
                        $model->analysis = null;
                        $model->save();
                    } elseif ($detection['flipflop_changes'] > 0) {
                        // Flip-flop detected — AI selects best variant for oscillating sentences only
                        $flipType = $detection['is_all_flipflop'] ? "full, matched cycle {$detection['matched_cycle']}" : 'partial';
                        Log::info("$model->id: Flip-flop: {$detection['flipflop_changes']}/{$detection['total_changes']} sentences ($flipType), resolving");

                        // Position-based pairing: match flip-flop sentences by document position
                        $priorHashTexts = SentenceHasher::hashSentences($model->publish_title, $model->publish_content);
                        $currentPositions = array_keys($hashTexts);
                        $priorPositions = array_keys($priorHashTexts);

                        $removalsSet = array_flip($detection['removals']);
                        $flipflopPairs = [];
                        foreach ($detection['flipflop_hashes'] ?? [] as $addHash) {
                            $pos = array_search($addHash, $currentPositions);
                            if ($pos !== false && isset($priorPositions[$pos])) {
                                $remHash = $priorPositions[$pos];
                                if (isset($removalsSet[$remHash])) {
                                    $flipflopPairs[] = [
                                        'current' => $hashTexts[$addHash] ?? '',
                                        'prior' => $priorHashTexts[$remHash] ?? '',
                                    ];
                                }
                            }
                        }

                        // AI variant selection with article context
                        if (!empty($flipflopPairs)) {
                            try {
                                $articleContext = $newTitle . "\n\n" . $newContent;
                                $selectionResponse = OpenAI::chat()->create([
                                    'model' => 'gpt-5.4-mini',
                                    'messages' => [
                                        ['role' => 'system', 'content' => 'Ти — редактор української мови. Обирай граматично та стилістично кращий варіант.'],
                                        ['role' => 'user', 'content' => SentenceHasher::buildVariantSelectionPrompt($flipflopPairs, $articleContext)],
                                    ],
                                ]);

                                $selectionJson = json_decode($selectionResponse->choices[0]->message->content ?? '{}', true);
                                if (is_array($selectionJson)) {
                                    foreach ($flipflopPairs as $idx => $pair) {
                                        $key = (string) ($idx + 1);
                                        $choice = $selectionJson[$key] ?? 'A';
                                        if ($choice === 'B' && !empty($pair['prior'])) {
                                            $currentText = SentenceHasher::stripTitlePrefix($pair['current']);
                                            $priorText = SentenceHasher::stripTitlePrefix($pair['prior']);
                                            if (str_starts_with($pair['current'], 'title:')) {
                                                $newTitle = $priorText;
                                            } else {
                                                $newContent = Str::replaceFirst($currentText, $priorText, $newContent);
                                            }
                                        }
                                    }
                                }
                            } catch (Exception $e) {
                                Log::warning("$model->id: AI variant selection failed: {$e->getMessage()}, keeping current content");
                            }
                        }

                        // Recompute hashes for the patched content
                        $currentHashes = array_keys(SentenceHasher::hashSentences($newTitle, $newContent));
                    }

                    if ($detection['total_changes'] > 0) {
                        // Consecutive all-flip-flop cycles signal the applier is stuck; 2+ triggers early escalation
                        // Removals are not checked — they are the replaced side of sentence swaps, not independent changes
                        $flipflopNiCount = $stored['flipflop_ni_count'] ?? 0;
                        if ($allChangesAreFlipFlops) {
                            $flipflopNiCount++;
                            Log::info("$model->id: All changes are flip-flops at analysis_count $model->analysis_count, flipflop_ni_count: $flipflopNiCount");
                        } else {
                            $flipflopNiCount = 0;
                        }

                        // Save content and hashes — post-iteration check dispatches AnalyzeNewsJob
                        $cycles[] = ['hashes' => $currentHashes, 'pairs' => $appliedPairs];
                        $model->content_hashes = ['cycles' => $cycles, 'flipflop_ni_count' => $flipflopNiCount];
                        $model->previous_analysis = $model->analysis . $pairFlipFlopWarning;
                        $model->status = NewsStatus::PENDING_REVIEW;
                        $model->analysis = null;
                        $model->publish_title = $newTitle;
                        $model->publish_content = $newContent;
                        $model->save();
                    }
                }
            } catch (Exception $e) {
                Log::error("$model->id: News applying analysis $model->analysis_count $i fail: {$e->getMessage()}");
            }

            if (empty($model->analysis)) {
                Cache::forget(self::CACHE_KEY_PREFIX . $this->id);
                if ($model->is_auto) {
                    $storedNiCount = is_array($model->content_hashes) ? ($model->content_hashes['flipflop_ni_count'] ?? 0) : 0;
                    if ($storedNiCount >= 2) {
                        $this->escalate($model, 'Flip-flop escalation to deep', 'Flip-flop escalation — deepest analysis needed');
                    } elseif ($model->analysis_count < ($model->platform == 'article' ? 32 : 16)) {
                        AnalyzeNewsJob::dispatch($model->id);
                    } else {
                        $this->escalate($model, 'Deep analysis limit reached without success', 'Deepest analysis limit reached without success');
                    }
                }

                break;
            }
        }

        if (!empty($model->analysis)) {
            Log::error("$model->id: All 4 apply iterations failed, resetting to PENDING_REVIEW");
            $model->status = NewsStatus::PENDING_REVIEW;
            $model->save();
        }

        Cache::forget(self::CACHE_KEY_PREFIX . $this->id);
    }

    /**
     * @throws TelegramException
     */
    private function escalate($model, string $deepText, string $deepestText): void
    {
        Cache::forget(self::CACHE_KEY_PREFIX . $this->id);
        $model->content_hashes = null;
        $model->previous_analysis = null;
        $model->analysis = null;

        if (!$model->is_deep) {
            $model->is_deep = true;
            $model->analysis_count = 0;
            $model->save();

            Request::sendMessage([
                'chat_id' => explode(',', config('telegram.admins'))[0],
                'reply_to_message_id' => $model->message_id,
                'text' => $deepText,
                'reply_markup' => new InlineKeyboard([['text' => '❌Delete', 'callback_data' => 'delete']]),
            ]);

            AnalyzeNewsJob::dispatch($model->id);
        } else {
            // is_deepest aligns with AnalyzeNewsJob:419 — old cycle-limit path omitted this (bug)
            $model->is_deepest = true;
            $model->is_auto = false;
            $model->save();

            Request::sendMessage([
                'chat_id' => explode(',', config('telegram.admins'))[0],
                'reply_to_message_id' => $model->message_id,
                'text' => $deepestText,
                'reply_markup' => new InlineKeyboard([['text' => '❌Delete', 'callback_data' => 'delete']]),
            ]);
        }
    }

    public static function extractCorrectionsList(string $analysis): string
    {
        $stripped = preg_replace('/^\s*\*{0,2}Так\.?\*{0,2}\s*/u', '', $analysis);
        $stripped = preg_replace('/\*\*(.+?)\*\*/u', '$1', $stripped);
        $stripped = preg_replace('/^#{1,4}\s+.+$/mu', '', $stripped);

        preg_match_all('/^\s*(?:\d+\.|-)\s+.+$/mu', $stripped, $matches);

        if (empty($matches[0])) {
            return $analysis;
        }

        return implode("\n", array_map('trim', $matches[0]));
    }
}
