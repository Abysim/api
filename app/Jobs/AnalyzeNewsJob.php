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
use Illuminate\Support\Facades\Cache;
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

    public const TIMEOUT = 7200;

    public int $tries = 0;

    public int $maxExceptions = 2;

    public int $timeout = self::TIMEOUT;

    public const CACHE_KEY_PREFIX = 'analyze_job_state_';

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

        $resumeState = null;
        if (!empty($model->analysis) || $model->status != NewsStatus::PENDING_REVIEW) {
            if (empty($model->analysis) && $model->status == NewsStatus::BEING_PROCESSED) {
                $state = $this->getState();
                $pid = $state['pid'] ?? null;
                if ($pid && function_exists('posix_kill') && posix_kill($pid, 0)) {
                    // Original worker still alive — re-queue for next check
                    $this->release(config('queue.connections.database.retry_after'));
                    return;
                }
                // Original worker is dead — resume this job
                $resumeState = $state;
                $model->touch();
                Log::warning("$model->id: Resuming orphaned analysis job, cached state: " . json_encode($state));
                $this->sendResumeNotification($model, $state);
            } else {
                $this->clearState();
                return;
            }
        } else {
            $model->status = NewsStatus::BEING_PROCESSED;
            $model->save();
        }

        // Store initial state (or update PID on resume)
        // poll_start_time tracks when the batch was originally submitted (for >2h cancel decision)
        // $startTime tracks current execution start (for polling loop timeout)
        $this->saveState([
            'i' => $resumeState['i'] ?? 0,
            'j' => 0,
            'batch_id' => $resumeState['batch_id'] ?? null,
            'poll_start_time' => $resumeState['poll_start_time'] ?? $startTime->timestamp,
        ]);

        $isOA = $model->platform == 'article' || config('app.is_news_by_openai');
        if (
            $isOA
            && !$model->is_deep
            && AiUsage::today()->total_tokens + $model->max_tokens * 2 > 1000000
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

                $this->clearState();
                return;
            } else {
                $isOA = false;
            }
        }

        $startI = $resumeState['i'] ?? 0;
        for ($i = $startI; $i < 4; $i++) {
            try {
                Log::info("$model->id: News analysis $model->analysis_count $i");

                $this->saveState([
                    'i' => $i,
                    'j' => 0,
                    'batch_id' => null,
                    'poll_start_time' => $startTime->timestamp,
                ]);

                $params = [
                    'model' => $model->is_deep
                        ? ($i > 1 ? 'anthropic/claude-opus-4-8' : 'claude-opus-4-8')
                        : ($i > 1
                            ? ($isOA ? 'openai/gpt-5.5' : 'gemini-3.1-pro-preview')
                            : ($isOA ? 'gpt-5.5' : 'gemini-3.1-pro-preview')
                        ),
                ];

                $userContent = '# ' . $model->publish_title . "\n\n" . $model->publish_content;
                if (!empty($model->previous_analysis)) {
                    $userContent .= "\n\n---\nПопередній аналіз (для контексту — якщо бачиш, що твоє виправлення скасовує щойно застосоване, обери кращий варіант за правилами та зафіксуй вибір, а не пропонуй зворотну зміну):\n" . $model->previous_analysis;
                }

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
                        ['role' => 'user', 'content' => $userContent]
                    ];

                    $params['max_tokens'] = 128000;
                    $params['thinking'] = ['type' => 'adaptive'];
                    $params['output_config'] = ['effort' => 'max'];
                } else {
                    $params['messages'] = [
                        [
                            'role' => 'system',
                            'content' => NewsController::getPrompt('analyzer', $model->platform == 'article')
                                . ' ' . $model->date->translatedFormat('j F Y')
                        ],
                        ['role' => 'user', 'content' => $userContent]
                    ];

                    if ($model->is_deep) {
                        $params['max_tokens'] = 128000;
                        $params['provider'] = ['require_parameters' => true];
                        $params['reasoning'] = ['max_tokens' => 100000];
                    } elseif ($isOA) {
                        if ($i > 1) {
                            $params['provider'] = ['require_parameters' => true];
                            $params['reasoning'] = ['effort' => 'xhigh'];
                        } else {
                            $params['reasoning_effort'] = 'xhigh';
                        }
                    }
                }

                // Pre-debit estimated tokens to protect against billed-but-untracked calls
                $estimatedTokens = 0;
                if ($isOA && !$model->is_deep && $model->max_tokens > 0) {
                    $estimatedTokens = $model->max_tokens;
                    AiUsage::today()
                        ->increment('total_tokens', $estimatedTokens);
                }

                if (!$model->is_deep && !$isOA) {
                    $response = Http::asJson()
                        ->withToken(config('services.gemini.api_key'))
                        ->timeout(config('services.gemini.api_timeout'))
                        ->post('https://' . config('services.gemini.api_endpoint') . '/chat/completions', $params)
                        ->object();
                } elseif (!$model->is_deep && $isOA && $i <= 1) {
                    $response = OpenAI::chat()->create($params);
                    $actualTokens = $response->usage->totalTokens ?? 0;
                    $adjustment = $actualTokens - $estimatedTokens;
                    if ($adjustment != 0) {
                        AiUsage::today()
                            ->increment('total_tokens', $adjustment);
                    }
                } elseif ($model->is_deep && $i <= 1) {
                    $batchId = null;
                    $resumingBatch = $resumeState && !empty($resumeState['batch_id']) && ($resumeState['i'] ?? -1) == $i;

                    if ($resumingBatch) {
                        // PATH B: Resume — check existing batch status
                        $batchId = $resumeState['batch_id'];
                        $batchStartTime = $resumeState['poll_start_time'] ?? $startTime->timestamp;
                        $elapsed = now()->timestamp - $batchStartTime;

                        $response = $this->anthropicHttp()
                            ->get('https://' . config('services.anthropic.api_endpoint') . '/messages/batches/' . $batchId)
                            ->object();

                        Log::info(
                            "$model->id: News analysis batch resume check $model->analysis_count $i: "
                            . json_encode($response, JSON_UNESCAPED_UNICODE)
                        );

                        if ($response->processing_status == 'ended') {
                            if (!empty($response->results_url)) {
                                $response = $this->anthropicHttp()
                                    ->get($response->results_url)
                                    ->object();

                                if (!empty($response->result->message)) {
                                    $response = $response->result->message;
                                } else {
                                    throw new Exception(
                                        "$model->id: News analysis failed to get batch result on resume $model->analysis_count $i: "
                                        . json_encode($response, JSON_UNESCAPED_UNICODE)
                                    );
                                }
                            }
                        } elseif ($elapsed >= self::TIMEOUT) {
                            Log::warning("$model->id: News analysis batch timeout on resume $model->analysis_count $i, elapsed {$elapsed}s");
                            $this->anthropicHttp()
                                ->post('https://' . config('services.anthropic.api_endpoint') . '/messages/batches/' . $batchId . '/cancel')
                                ->object();

                            $model->status = NewsStatus::PENDING_REVIEW;
                            $model->save();
                            $this->clearState();
                            AnalyzeNewsJob::dispatch($model->id);
                            break;
                        }
                    }

                    if (!$resumingBatch || (isset($response->processing_status) && $response->processing_status != 'ended')) {
                        if (!$resumingBatch) {
                            // PATH A: Fresh start — submit new batch
                            $response = $this->anthropicHttp(config('services.anthropic.api_timeout'))
                                ->asJson()
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

                            if (
                                isset($response->error->message)
                                && Str::contains($response->error->message, 'credit balance', true)
                                && Cache::add('anthropic_credit_alert', true, now()->addHours(24))
                            ) {
                                Request::sendMessage([
                                    'chat_id' => explode(',', config('telegram.admins'))[0],
                                    'text' => '⚠️ Anthropic credit balance too low — top up to restore deep analysis. '
                                        . 'Falling back to OpenRouter until then.',
                                    'reply_markup' => new InlineKeyboard([
                                        ['text' => '❌Delete', 'callback_data' => 'delete']
                                    ]),
                                ]);
                            }

                            if (empty($response->id)) {
                                throw new Exception("$model->id: Failed to get batch ID $model->analysis_count $i");
                            }
                            $batchId = $response->id;
                        }

                        $sleep = 30;
                        $startJ = ($resumingBatch && isset($resumeState['j'])) ? $resumeState['j'] : 0;
                        $pollStartTime = ($resumingBatch && isset($resumeState['poll_start_time']))
                            ? $resumeState['poll_start_time']
                            : $startTime->timestamp;

                        for ($j = $startJ; $j < intdiv($this->timeout, $sleep); $j++) {
                            sleep($sleep);

                            $this->saveState([
                                'i' => $i,
                                'j' => $j,
                                'batch_id' => $batchId,
                                'poll_start_time' => $pollStartTime,
                            ]);

                            try {
                                $response = $this->anthropicHttp($sleep)
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

                                    $batchResult = $this->anthropicHttp($sleep)
                                        ->get($response->results_url)
                                        ->object();

                                    if (empty($batchResult->result->message)) {
                                        throw new Exception(
                                            "$model->id: News analysis failed to get batch result $model->analysis_count $i: "
                                            . json_encode($batchResult, JSON_UNESCAPED_UNICODE)
                                        );
                                    }

                                    $response = $batchResult->result->message;
                                    break;
                                }
                            } catch (Exception $e) {
                                Log::error("$model->id: News analysis batch failed $model->analysis_count $i: {$e->getMessage()}");
                                if (isset($response->processing_status) && $response->processing_status == 'ended') {
                                    continue 2;
                                }
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
                    }

                    if (empty($response->content[1]->text)) {
                        Log::warning("$model->id: News analysis batch without result $model->analysis_count $i");
                        if (!empty($batchId)) {
                            $this->anthropicHttp()
                                ->post('https://' . config('services.anthropic.api_endpoint') . '/messages/batches/' . $batchId . '/cancel')
                                ->object();
                        }

                        $model->status = NewsStatus::PENDING_REVIEW;
                        $model->save();
                        $this->clearState();
                        if (!isset($response->processing_status) || $response->processing_status == 'in_progress') {
                            Log::warning("$model->id: News analysis batch timeout $model->analysis_count $i");
                            AnalyzeNewsJob::dispatch($model->id);
                        }

                        break;
                    }
                } else {
                    $response = AI::client('openrouter')->chat()->create($params);
                    if ($isOA && !$model->is_deep) {
                        $actualTokens = $response->usage->totalTokens ?? $response->usage->total_tokens ?? 0;
                        $adjustment = $actualTokens - $estimatedTokens;
                        if ($adjustment != 0) {
                            AiUsage::today()
                                ->increment('total_tokens', $adjustment);
                        }
                    }
                }

                Log::info(
                    "$model->id: News analysis $model->analysis_count $i result: "
                    . json_encode($response, JSON_UNESCAPED_UNICODE)
                );

                $content = $response->content[1]->text ?? $response->choices[0]->message->content ?? null;
                if (!empty($content)) {
                    $model->refresh();
                    if ($model->status != NewsStatus::BEING_PROCESSED) {
                        Log::warning("AnalyzeNewsJob {$model->id}: status changed to {$model->status->name} during analysis, aborting");
                        $this->clearState();
                        return;
                    }
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
                        $model->previous_analysis = null;
                        $model->save();
                        $this->clearState();
                    }
                }
            } catch (Exception $e) {
                Log::error("$model->id: News analysis $model->analysis_count $i fail: {$e->getMessage()}");
                if ($i < 3) {
                    sleep(30);
                } else {
                    $model->status = NewsStatus::PENDING_REVIEW;
                    $model->save();
                    $this->clearState();

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
                        $this->clearState();
                        ApplyNewsAnalysisJob::dispatch($model->id);
                    } elseif (Str::substr(trim($model->analysis, '*# '), 0, 2) == 'Ні') {
                        if (!$model->is_deep) {
                            $model->analysis = null;
                            $model->is_deep = true;
                            $model->analysis_count = 0;
                            $model->content_hashes = null;
                            $model->previous_analysis = null;
                            $model->save();
                            $this->clearState();
                            AnalyzeNewsJob::dispatch($model->id);
                        } else {
                            $model->is_deepest = true;
                            $model->is_auto = false;
                            $model->content_hashes = null;
                            $model->previous_analysis = null;
                            $model->save();
                            $this->clearState();

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
                        $this->clearState();

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

        $this->clearState();
    }

    private function anthropicHttp(int $timeout = 30): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders([
            'x-api-key' => config('services.anthropic.api_key'),
            'anthropic-version' => '2023-06-01',
        ])->timeout($timeout);
    }

    private function cacheKey(): string
    {
        return self::CACHE_KEY_PREFIX . $this->id;
    }

    private function saveState(array $state): void
    {
        Cache::put($this->cacheKey(), array_merge($state, ['pid' => getmypid()]), self::TIMEOUT);
    }

    private function getState(): ?array
    {
        return Cache::get($this->cacheKey());
    }

    private function clearState(): void
    {
        Cache::forget($this->cacheKey());
    }

    private function sendResumeNotification(News $model, ?array $state): void
    {
        if (empty($model->message_id)) {
            return;
        }

        $stuckMinutes = $state
            ? intdiv(now()->timestamp - ($state['poll_start_time'] ?? now()->timestamp), 60)
            : 0;
        $resumeType = $state && !empty($state['batch_id']) ? 'polling' : 'API';

        try {
            Request::sendMessage([
                'chat_id' => explode(',', config('telegram.admins'))[0],
                'reply_to_message_id' => $model->message_id,
                'text' => $state
                    ? "Resumed killed analysis job (stuck {$stuckMinutes}m, {$resumeType}, i={$state['i']})"
                    : 'Resumed killed analysis job (no cached state)',
                'reply_markup' => new InlineKeyboard([
                    ['text' => '❌Delete', 'callback_data' => 'delete'],
                ]),
            ]);
        } catch (TelegramException $e) {
            Log::error("$model->id: Failed to send resume notification: {$e->getMessage()}");
        }
    }
}
