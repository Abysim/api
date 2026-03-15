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
use Illuminate\Support\Facades\Http;
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

        for ($i = 0; $i < 4; $i++) {
            try {
                Log::info("$model->id: News applying analysis $model->analysis_count $i");
                $params = [
                    'model' => $i % 2 ? 'openai/gpt-5-mini' : 'gpt-5-mini',
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => trim(Str::before(
                                NewsController::getPrompt('analyzer', $model->platform == 'article'),
                                '1.'
                            )) . "\n" . trim(Str::after(Str::afterLast(
                                    trim(NewsController::getPrompt('analyzer', $model->platform == 'article')),
                                    "\n"
                            ), '.')) . ' ' . $model->date->translatedFormat('j F Y'),
                        ],
                        ['role' => 'user', 'content' => '# ' . $model->publish_title . "\n\n" . $model->publish_content],
                        ['role' => 'assistant', 'content' => $model->analysis],
                        ['role' => 'user', 'content' => NewsController::getPrompt('editor')],
                    ],
                ];

                if ($i % 2) {
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
                    $content = trim(Str::after($response->choices[0]->message->content, '```markdown'));
                    [$title, $content] = explode("\n", $content, 2);
                    $newTitle = trim($title, '*# ');
                    $newContent = trim(trim($content, '`'));

                    // Compute content hash for oscillation detection
                    $hash = md5(mb_strtolower(preg_replace('/\s+/', ' ', $newTitle . "\n" . $newContent)));
                    $hashes = $model->content_hashes ?? [];

                    if (in_array($hash, $hashes, true)) {
                        // Oscillation detected: content reverted to a previously seen state
                        // Escalate to next tier (same as "Ні") — do NOT apply reverted content
                        Log::info("$model->id: Oscillation detected at analysis_count $model->analysis_count, escalating");
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

                        break;
                    }

                    $hashes[] = $hash;
                    $model->content_hashes = $hashes;
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
}
