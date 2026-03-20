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
use Illuminate\Queue\Middleware\WithoutOverlapping;
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

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->id))->releaseAfter(300)->expireAfter(600)];
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
                    $newContent = preg_replace('/\s*(\n\s*---\s*)+\s*$/', '', $newContent);

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
                        // No changes — applier produced identical content, let analyzer re-evaluate
                        Log::info("$model->id: No changes at analysis_count $model->analysis_count, letting analyzer re-evaluate");
                        $model->previous_analysis = $model->analysis;
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
                        foreach ($detection['flipflop_hashes'] as $addHash) {
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
                                    'model' => 'gpt-5-mini',
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
                        // Save content and hashes — post-iteration check dispatches AnalyzeNewsJob
                        $cycles[] = ['hashes' => $currentHashes];
                        $model->content_hashes = ['cycles' => $cycles];
                        $model->previous_analysis = $model->analysis;
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

}
