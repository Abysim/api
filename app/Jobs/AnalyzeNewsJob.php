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

        for ($i = 0; $i < 2; $i++) {
            try {
                Log::info("$model->id: News analysis $model->analysis_count $i");
                $params = [
                    'model' => $model->is_deep
                        ? ($i > 1 ? 'anthropic/claude-3.7-sonnet:thinking' : 'claude-3-7-sonnet-20250219')
                        : ($i > 1 ? 'google/gemini-2.5-pro-exp-03-25:free' : 'gemini-2.5-pro-exp-03-25'),
                ];

                if ($model->is_deep && $i <= 1) {
                    $params['system'] = [
                        [
                            'type' => 'text',
                            'text' => NewsController::getPrompt('analyzer'),
                            'cache_control' => ['type' => 'ephemeral'],
                        ],
                        ['type' => 'text', 'text' => $model->date->format('j F Y')]
                    ];
                    $params['messages'] = [
                        ['role' => 'user', 'content' => '# ' . $model->publish_title . "\n\n" . $model->publish_content]
                    ];

                    $params['max_tokens'] = 64000;
                    $params['thinking'] = ['type' => 'enabled', 'budget_tokens' => 60000];
                } else {
                    $params['messages'] = [
                        [
                            'role' => 'system',
                            'content' => NewsController::getPrompt('analyzer') . $model->date->format('j F Y')
                        ],
                        ['role' => 'user', 'content' => '# ' . $model->publish_title . "\n\n" . $model->publish_content]
                    ];

                    $params['temperature'] = 0;

                    if ($model->is_deep) {
                        $params['max_tokens'] = 128000;
                        $params['provider'] = ['require_parameters' => true];
                        $params['reasoning'] = ['effort' => 'high'];
                    }
                }

                if (!$model->is_deep && $i <= 1) {
                    $response = Http::asJson()
                        ->withToken(config('services.gemini.api_key'))
                        ->timeout(config('services.gemini.api_timeout'))
                        ->post('https://' . config('services.gemini.api_endpoint') . '/chat/completions', $params)
                        ->object();
                } elseif ($model->is_deep && $i <= 1) {
                    $response = Http::asJson()
                        ->withHeaders([
                            'x-api-key' => config('services.anthropic.api_key'),
                            'anthropic-version' => '2023-06-01',
                        ])
                        ->timeout(config('services.anthropic.api_timeout'))
                        ->post('https://' . config('services.anthropic.api_endpoint') . '/messages', $params)
                        ->object();
                } else {
                    $response = AI::client('openrouter')->chat()->create($params);
                }

                Log::info(
                    "$model->id: News analysis $model->analysis_count $i result: "
                    . json_encode($response, JSON_UNESCAPED_UNICODE + JSON_PRETTY_PRINT)
                );

                $content = $response->content[1]->text ?? $response->choices[0]->message->content ?? null;
                if (!empty($content)) {
                    $model->refresh();
                    $content = trim(Str::after($content, '</think>'), "#* \n\r\t\v\0");
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
                Log::error("$model->id: News analysis $model->analysis_count $i fail: {$e->getMessage()}");
                if ($i > 0 && $i < 3) {
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
                                'text' => 'Auto translation completed ' . $model->analysis_count,
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
