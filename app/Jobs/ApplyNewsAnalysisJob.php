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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use OpenAI\Laravel\Facades\OpenAI;

class ApplyNewsAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 360;

    public function __construct(private readonly int $id)
    {
    }

    /**
     * @throws TelegramException
     */
    public function handle(): void
    {
        $model = News::find($this->id);
        if (
            empty($model->analysis)
            || $model->status == NewsStatus::BEING_PROCESSED
            || Str::substr(trim($model->analysis, '*# '), 0, 2) == 'Ні'
        ) {
            return;
        }
        $model->status = NewsStatus::BEING_PROCESSED;
        $model->save();

        $isScience = $model->platform == 'article';
        $preamble = trim(Str::before(NewsController::getPrompt('analyzer', $isScience), '1.'));
        // Rewrite analyzer preamble from first/second-person to third-person to avoid editor identity confusion
        $preamble = str_replace(['Ти — ', 'Твоя задача', 'Надавай одразу'], ['Це — ', 'Його задача', 'Надає одразу'], $preamble);
        $systemPrompt = str_replace(
            ['{analyzer_preamble}', '{date}'],
            [$preamble, $model->date->translatedFormat('j F Y')],
            NewsController::getPrompt('editor', $isScience)
        );

        for ($i = 0; $i < 4; $i++) {
            try {
                Log::info("$model->id: News applying analysis $model->analysis_count $i");
                $params = [
                    'model' => $i % 2 ? 'openai/gpt-5-mini' : 'gpt-5-mini',
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        [
                            'role' => 'user',
                            'content' => '# ' . $model->publish_title . "\n\n" . $model->publish_content
                                . "\n\n---\nВиправлення філолога:\n" . $model->analysis,
                        ],
                    ],
                ];

                if ($i % 2) {
                    $params['provider'] = ['require_parameters' => true];
                    $response = AI::client('openrouter')->chat()->create($params);
                } else {
                    $response = OpenAI::chat()->create($params);
                }

                Log::info(
                    "$model->id: News applying analysis $model->analysis_count $i result: "
                    . json_encode($response, JSON_UNESCAPED_UNICODE)
                );

                if (!empty($response->choices[0]->message->content)) {
                    $model->refresh();
                    $content = trim(Str::after($response->choices[0]->message->content, '```markdown'));
                    [$title, $content] = explode("\n", $content, 2);
                    $newTitle = trim($title, '*# ');
                    $newContent = trim(trim($content, '`'));

                    // Per-sentence oscillation detection
                    $hashTexts = SentenceHasher::hashSentences($newTitle, $newContent);
                    $currentHashes = array_keys($hashTexts);

                    // Load stored cycles (backward compatible)
                    $stored = $model->content_hashes;
                    $cycles = [];
                    if (is_array($stored) && !SentenceHasher::isOldFormat($stored)) {
                        $cycles = $stored['cycles'] ?? [];
                    }

                    // Detect flip-flops
                    $detection = SentenceHasher::detectFlipFlops($currentHashes, $cycles);

                    if ($detection['total_changes'] === 0) {
                        // No changes — article identical to last cycle, treat as done
                        Log::info("$model->id: No changes at analysis_count $model->analysis_count, escalating");
                        $this->escalateOscillation($model);
                        break;
                    }

                    if ($detection['is_all_flipflop']) {
                        // 100% flip-flop — entire article reverted to a previously seen state
                        Log::info("$model->id: 100% flip-flop at analysis_count $model->analysis_count (matched cycle {$detection['matched_cycle']}, {$detection['total_changes']} changes)");

                        // AI variant selection: pick best version of each differing sentence
                        $priorHashTexts = SentenceHasher::hashSentences($model->publish_title, $model->publish_content);
                        $flipflopSentences = [];
                        $additionTexts = [];
                        foreach ($detection['additions'] as $hash) {
                            $additionTexts[] = $hashTexts[$hash] ?? '';
                        }
                        $removalTexts = [];
                        foreach ($detection['removals'] as $hash) {
                            $removalTexts[] = $priorHashTexts[$hash] ?? '';
                        }
                        for ($p = 0; $p < max(count($additionTexts), count($removalTexts)); $p++) {
                            $current = $additionTexts[$p] ?? '';
                            $prior = $removalTexts[$p] ?? '';
                            if ($current !== '' && $prior !== '') {
                                $flipflopSentences[] = ['current' => $current, 'prior' => $prior];
                            }
                        }

                        // Inline AI call to select best variants
                        $patchedTitle = $newTitle;
                        $patchedContent = $newContent;
                        if (!empty($flipflopSentences)) {
                            try {
                                $selectionResponse = OpenAI::chat()->create([
                                    'model' => 'gpt-5-mini',
                                    'messages' => [
                                        ['role' => 'system', 'content' => 'Ти — редактор української мови. Обирай граматично та стилістично кращий варіант.'],
                                        ['role' => 'user', 'content' => SentenceHasher::buildVariantSelectionPrompt($flipflopSentences)],
                                    ],
                                ]);

                                $selectionJson = json_decode($selectionResponse->choices[0]->message->content ?? '{}', true);
                                if (is_array($selectionJson)) {
                                    foreach ($flipflopSentences as $idx => $pair) {
                                        $key = (string) ($idx + 1);
                                        $choice = $selectionJson[$key] ?? 'A';
                                        if ($choice === 'B' && !empty($pair['prior'])) {
                                            $currentText = SentenceHasher::stripTitlePrefix($pair['current']);
                                            $priorText = SentenceHasher::stripTitlePrefix($pair['prior']);
                                            if (str_starts_with($pair['current'], 'title:')) {
                                                $patchedTitle = $priorText;
                                            } else {
                                                $patchedContent = Str::replaceFirst($currentText, $priorText, $patchedContent);
                                            }
                                        }
                                    }
                                }
                            } catch (Exception $e) {
                                Log::warning("$model->id: AI variant selection failed: {$e->getMessage()}, keeping current content");
                            }
                        }

                        $model->publish_title = $patchedTitle;
                        $model->publish_content = $patchedContent;
                        $this->escalateOscillation($model);
                        break;
                    }

                    // Partial flip-flop or all-new changes — log and continue normally
                    if ($detection['flipflop_changes'] > 0) {
                        Log::info("$model->id: Partial flip-flop: {$detection['flipflop_changes']}/{$detection['total_changes']} sentences (additions: " . count($detection['additions']) . ", removals: " . count($detection['removals']) . ")");
                    }

                    // Append current cycle (hashes only) and save
                    $cycles[] = ['hashes' => $currentHashes];
                    $model->content_hashes = ['cycles' => $cycles];
                    $model->previous_analysis = $model->analysis;

                    $model->status = NewsStatus::PENDING_REVIEW;
                    $model->analysis = null;
                    $model->publish_title = $newTitle;
                    $model->publish_content = $newContent;
                    $model->save();
                }
            } catch (Exception $e) {
                Log::error("$model->id: News applying analysis $model->analysis_count $i fail: {$e->getMessage()}");
            }

            if (empty($model->analysis)) {
                if ($model->is_auto) {
                   if ($model->analysis_count < ($model->platform == 'article' ? 32 : 16)) {
                       AnalyzeNewsJob::dispatch($model->id);
                   } else {
                       if (!$model->is_deep) {
                           $model->is_deep = true;
                           $model->analysis_count = 0;
                           $model->content_hashes = null;
                           $model->previous_analysis = null;
                           $model->save();

                           Request::sendMessage([
                               'chat_id' => explode(',', config('telegram.admins'))[0],
                               'reply_to_message_id' => $model->message_id,
                               'text' => 'Deep analysis limit reached without success',
                               'reply_markup' => new InlineKeyboard([['text' => '❌Delete', 'callback_data' => 'delete']]),
                           ]);

                           AnalyzeNewsJob::dispatch($model->id);
                       } else {
                           $model->is_auto = false;
                           $model->content_hashes = null;
                           $model->previous_analysis = null;
                           $model->save();

                           Request::sendMessage([
                               'chat_id' => explode(',', config('telegram.admins'))[0],
                               'reply_to_message_id' => $model->message_id,
                               'text' => 'Deepest analysis limit reached without success',
                               'reply_markup' => new InlineKeyboard([['text' => '❌Delete', 'callback_data' => 'delete']]),
                           ]);
                       }
                   }
                }

                break;
            }
        }
    }

    private function escalateOscillation(object $model): void
    {
        $model->status = NewsStatus::PENDING_REVIEW;
        $model->analysis = null;
        $model->previous_analysis = null;
        $model->content_hashes = null;

        if (!$model->is_deep) {
            $model->is_deep = true;
            $model->analysis_count = 0;
            $model->save();

            Request::sendMessage([
                'chat_id' => explode(',', config('telegram.admins'))[0],
                'reply_to_message_id' => $model->message_id,
                'text' => 'Oscillation detected, escalating to deep analysis',
                'reply_markup' => new InlineKeyboard([['text' => '❌Delete', 'callback_data' => 'delete']]),
            ]);

            AnalyzeNewsJob::dispatch($model->id);
        } else {
            $model->is_deepest = true;
            $model->is_auto = false;
            $model->save();

            Request::sendMessage([
                'chat_id' => explode(',', config('telegram.admins'))[0],
                'reply_to_message_id' => $model->message_id,
                'text' => 'Deep oscillation detected, auto analysis completed ' . $model->analysis_count,
                'reply_markup' => new InlineKeyboard([['text' => '❌Delete', 'callback_data' => 'delete']]),
            ]);
        }
    }
}
