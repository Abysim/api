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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use OpenAI\Laravel\Facades\OpenAI;

class AnalyzeNewsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 2400;

    public function __construct(private readonly int $id)
    {
    }

    /**
     * @throws TelegramException
     */
    public function handle(): void
    {
        $model = News::find($this->id);
        if (!empty($model->analysis) || $model->status == NewsStatus::BEING_PROCESSED) {
            return;
        }
        $model->status = NewsStatus::BEING_PROCESSED;
        $model->save();

        for ($i = 0; $i < 4; $i++) {
            try {
                Log::info("$model->id: News analysis $model->analysis_count");
                $params = [
                    'model' => $model->is_deep ? ($i > 1 ? 'anthropic/claude-3.7-sonnet:thinking' : 'claude-3-7-sonnet-20250219') : 'deepseek-ai/DeepSeek-R1',
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => Str::replace(
                                '<date>',
                                $model->date->format('j F Y'),
                                NewsController::getPrompt('analyzer')
                            ),
                        ],
                        ['role' => 'user', 'content' => '# ' . $model->publish_title . "\n\n" . $model->publish_content]
                    ],
                ];

                if ($model->is_deep && $i <= 1) {
                    $params['max_tokens'] = 64000;
                    $params['thinking'] = ['type' => 'enabled', 'budget_tokens' => 60000];
                } else {
                    $params['max_tokens'] = 128000;
                    $params['temperature'] = 0;
                    $params['provider'] = ['require_parameters' => true];
                    $params['reasoning'] = ['effort' => 'high'];
                }

                $chat = AI::client(($i > 1 || $model->is_deep) ? ($i > 1 ? 'openrouter' : 'anthropic') : 'nebius')->chat();
                $response = $chat->create($params);

                Log::info(
                    "$model->id: News analysis $model->analysis_count result: "
                    . json_encode($response, JSON_UNESCAPED_UNICODE)
                );

                if (!empty($response->choices[0]->message->content)) {
                    $model->refresh();
                    $content = trim(Str::after($response->choices[0]->message->content, '</think>'), "#* \n\r\t\v\0");
                    if (Str::substr($content, 0, 2) != 'Ні' && Str::substr($content, 0, 3) != 'Так') {
                        if (Str::contains($content, 'Так.')) {
                            $content = 'Так.' . Str::after($content, 'Так.') . Str::before($content, 'Так.');
                        } elseif (Str::contains($content, 'Ні.')) {
                            $content = 'Ні.' . Str::after($content, 'Ні.') . Str::before($content, 'Ні.');
                        }
                    }

                    if ($i > 0 || Str::substr($content, 0, 2) != 'Ні') {
                        $model->analysis = $content;
                        $model->status = NewsStatus::PENDING_REVIEW;
                        $model->analysis_count = $model->analysis_count + 1;
                        $model->save();
                    }
                }
            } catch (Exception $e) {
                Log::error("$model->id: News analysis $model->analysis_count fail: {$e->getMessage()}");
                if ($i > 0 && $i < 3) {
                    return;

                    sleep(30);
                }
            }

            if (!empty($model->analysis)) {
                if ($model->is_auto) {
                    if (Str::substr(trim($model->analysis, '*# '), 0, 3) == 'Так') {
                        ApplyNewsAnalysisJob::dispatch($model->id);
                    } elseif (Str::substr(trim($model->analysis, '*# '), 0, 2) == 'Ні') {
                        if (!$model->is_deep) {
                            $model->analysis = null;
                            $model->is_deep = true;
                            $model->analysis_count = 0;
                            $model->save();
                            AnalyzeNewsJob::dispatch($model->id);
                        } else {
                            $model->is_deepest = true;
                            $model->is_auto = false;
                            $model->save();

                            Request::sendMessage([
                                'chat_id' => explode(',', config('telegram.admins'))[0],
                                'reply_to_message_id' => $model->message_id,
                                'text' => 'Auto translation completed',
                                'reply_markup' => new InlineKeyboard([['text' => '❌Delete', 'callback_data' => 'delete']]),
                            ]);
                        }
                    } else {
                        $model->is_auto = false;
                        $model->save();

                        Request::sendMessage([
                            'chat_id' => explode(',', config('telegram.admins'))[0],
                            'reply_to_message_id' => $model->message_id,
                            'text' => 'Got unsupported analysis response',
                            'reply_markup' => new InlineKeyboard([['text' => '❌Delete', 'callback_data' => 'delete']]),
                        ]);
                    }
                }

                break;
            }
        }
    }
}
