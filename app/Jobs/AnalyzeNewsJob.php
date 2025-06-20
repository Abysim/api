<?php

namespace App\Jobs;

use App\AI;
use App\Enums\NewsStatus;
use App\Http\Controllers\NewsController;
use App\Models\AiUsage;
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

    public int $timeout = 7200;

    public function __construct(private readonly int $id)
    {
    }

    /**
     * @throws TelegramException
     */
    public function handle(): void
    {
        $startTime = now();
        $model = News::find($this->id);
        if (!empty($model->analysis) || $model->status != NewsStatus::PENDING_REVIEW) {
            return;
        }
        $model->status = NewsStatus::BEING_PROCESSED;
        $model->save();

        $isOA = $model->platform == 'article' || config('app.is_news_by_openai');
        if (
            $isOA
            && !$model->is_deep
            && AiUsage::firstOrCreate(['date' => now()->format('Y-m-d')])->total_tokens + $model->max_tokens * 2 > 1000000
        ) {
            Log::warning("$model->id: News analysis $model->analysis_count: OpenAI token limit exceeded");
            if ($model->platform == 'article') {
                $model->status = NewsStatus::PENDING_REVIEW;
                $model->save();

                Request::sendMessage([
                    'chat_id' => explode(',', config('telegram.admins'))[0],
                    'reply_to_message_id' => $model->message_id,
                    'text' => 'OpenAI token limit exceeded',
                    'reply_markup' => new InlineKeyboard([['text' => '❌Delete', 'callback_data' => 'delete']]),
                ]);

                return;
            } else {
                $isOA = false;
            }
        }

        for ($i = 0; $i < 4; $i++) {
            try {
                Log::info("$model->id: News analysis $model->analysis_count $i");
                $params = [
                    'model' => $model->is_deep
                        ? ($i > 1 ? 'anthropic/claude-4-sonnet:thinking' : 'claude-sonnet-4-20250514')
                        : ($i > 1
                            ? ($isOA ? 'openai/o3' : 'gemini-2.5-pro-preview-06-05')
                            : ($isOA ? 'o3' : 'gemini-2.5-pro-exp-03-25')
                        ),
                ];

                if ($model->is_deep && $i <= 1) {
                    $params['system'] = [
                        [
                            'type' => 'text',
                            'text' => NewsController::getPrompt('analyzer', $model->platform == 'article'),
                            'cache_control' => ['type' => 'ephemeral'],
                        ],
                        ['type' => 'text', 'text' => $model->date->translatedFormat('j F Y')]
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
                            'content' => NewsController::getPrompt('analyzer', $model->platform == 'article')
                                . ' ' . $model->date->translatedFormat('j F Y')
                        ],
                        ['role' => 'user', 'content' => '# ' . $model->publish_title . "\n\n" . $model->publish_content]
                    ];

                    if ($model->is_deep || $i > 1 && $isOA) {
                        $params['max_tokens'] = 64000;
                        $params['provider'] = ['require_parameters' => true];
                        $params['reasoning'] = ['effort' => 'high'];
                    } elseif ($isOA) {
                        $params['reasoning_effort'] = 'high';
                    }
                }

                if (!$model->is_deep && !$isOA) {
                    $response = Http::asJson()
                        ->withToken(config('services.gemini.api_key'))
                        ->timeout(config('services.gemini.api_timeout'))
                        ->post('https://' . config('services.gemini.api_endpoint') . '/chat/completions', $params)
                        ->object();
                } elseif (!$model->is_deep && $isOA && $i <= 1) {
                    $response = OpenAI::chat()->create($params);
                    AiUsage::firstOrCreate(['date' => now()->format('Y-m-d')])
                        ->increment('total_tokens', $response->usage->totalTokens ?? 0);
                } elseif ($model->is_deep && $i <= 1) {
                    $response = Http::asJson()
                        ->withHeaders([
                            'x-api-key' => config('services.anthropic.api_key'),
                            'anthropic-version' => '2023-06-01',
                        ])
                        ->timeout(config('services.anthropic.api_timeout'))
                        ->post(
                            'https://' . config('services.anthropic.api_endpoint') . '/messages/batches',
                            [
                                'requests' => [
                                    [
                                        'custom_id' => $model->id . 'analyze' . $model->analysis_count . 'i' . $i,
                                        'params' => $params,
                                    ]
                                ]
                            ]
                        )
                        ->object();
                    Log::info(
                        "$model->id: News analysis batch started $model->analysis_count $i: "
                        . json_encode($response, JSON_UNESCAPED_UNICODE)
                    );

                    if (empty($response->id)) {
                        throw new Exception("$model->id: Failed to get batch ID $model->analysis_count $i");
                    }
                    $batchId = $response->id;

                    $sleep = 30;
                    for ($j = 0; $j < intdiv($this->timeout, $sleep); $j++) {
                        sleep($sleep);
                        try {
                            $response = Http::withHeaders([
                                'x-api-key' => config('services.anthropic.api_key'),
                                'anthropic-version' => '2023-06-01',
                            ])
                                ->timeout($sleep)
                                ->get('https://' . config('services.anthropic.api_endpoint') . '/messages/batches/' . $batchId)
                                ->object();

                            Log::info(
                                "$model->id: News analysis batch waiting $model->analysis_count $i $j: "
                                . json_encode($response, JSON_UNESCAPED_UNICODE)
                            );

                            if ($response->processing_status == 'ended') {
                                if (empty($response->results_url)) {
                                    break;
                                }

                                $response = Http::withHeaders([
                                        'x-api-key' => config('services.anthropic.api_key'),
                                        'anthropic-version' => '2023-06-01',
                                    ])
                                    ->timeout($sleep)
                                    ->get($response->results_url)
                                    ->object();

                                if (empty($response->result->message)) {
                                    throw new Exception(
                                        "$model->id: News analysis failed to get batch result $model->analysis_count $i: "
                                        . json_encode($response, JSON_UNESCAPED_UNICODE)
                                    );
                                }

                                $response = $response->result->message;
                                break;
                            }
                        } catch (Exception $e) {
                            Log::error("$model->id: News analysis batch failed $model->analysis_count $i: {$e->getMessage()}");
                        }

                        $currentTime = now();
                        if (
                            $currentTime->diffInSeconds($startTime) >= $this->timeout - $sleep * 2
                            || isset($response->processing_status) && $response->processing_status != 'in_progress'
                        ) {
                            break;
                        }

                        unset($response);
                        gc_collect_cycles();
                    }

                    if (empty($response->content[1]->text)) {
                        Log::warning("$model->id: News analysis batch without result $model->analysis_count $i");
                        Http::withHeaders([
                            'x-api-key' => config('services.anthropic.api_key'),
                            'anthropic-version' => '2023-06-01',
                        ])
                            ->timeout($sleep)
                            ->post('https://' . config('services.anthropic.api_endpoint') . '/messages/batches/' . $batchId . '/cancel')
                            ->object();

                        $model->status = NewsStatus::PENDING_REVIEW;
                        $model->save();
                        if (!isset($response->processing_status) || $response->processing_status == 'in_progress') {
                            Log::warning("$model->id: News analysis batch timeout $model->analysis_count $i");
                            AnalyzeNewsJob::dispatch($model->id);
                        }

                        break;
                    }
                } else {
                    $response = AI::client('openrouter')->chat()->create($params);
                    if ($isOA && !$model->is_deep) {
                        AiUsage::firstOrCreate(['date' => now()->format('Y-m-d')])
                            ->increment(
                                'total_tokens',
                                $response->usage->totalTokens ?? $response->usage->total_tokens ??  0
                            );
                    }
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

                    if ($i > 0 || !$model->is_deep && !$isOA || Str::substr($content, 0, 2) != 'Ні') {
                        $totalTokens = $response->usage->totalTokens ?? $response->usage->total_tokens ?? 0;
                        if ($totalTokens > $model->max_tokens) {
                            $model->max_tokens = $totalTokens;
                        }

                        $model->analysis = $content;
                        $model->status = NewsStatus::PENDING_REVIEW;
                        $model->analysis_count = $model->analysis_count + 1;
                        $model->save();
                    }
                }
            } catch (Exception $e) {
                Log::error("$model->id: News analysis $model->analysis_count $i fail: {$e->getMessage()}");
                if ($i < 3) {
                    sleep(30);
                } else {
                    $model->status = NewsStatus::PENDING_REVIEW;
                    $model->save();

                    Request::sendMessage([
                        'chat_id' => explode(',', config('telegram.admins'))[0],
                        'reply_to_message_id' => $model->message_id,
                        'text' => 'Analysis failed',
                        'reply_markup' => new InlineKeyboard([['text' => '❌Delete', 'callback_data' => 'delete']]),
                    ]);
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
