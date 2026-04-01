<?php

namespace App\Jobs;

use App\Http\Controllers\NewsController;
use App\Models\News;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;

class CleanNewsContentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;

    protected int $id;
    protected string $mode;

    /**
     * @param string $mode 'auto' (pipeline cleaning for FreeNews) or 'manual' (Telegram button, any platform)
     */
    public function __construct(int $id, string $mode = 'manual')
    {
        $this->id = $id;
        $this->mode = $mode;
    }

    public function handle(): void
    {
        $model = News::find($this->id);
        if (!$model) {
            return;
        }

        if ($this->mode === 'auto') {
            $this->handleAuto($model);
        } else {
            $this->handleManual($model);
        }
    }

    private function handleAuto($model): void
    {
        if ($model->platform !== 'FreeNews' || $model->is_content_cleaned) {
            if ($model->language !== 'uk') {
                TranslateNewsJob::dispatch($model->id);
            }
            return;
        }

        try {
            $cleaned = $this->cleanViaAI($model->publish_content);

            if (!empty($cleaned) && mb_strlen($cleaned) > 100) {
                $model->publish_content = $cleaned;
            } else {
                Log::warning("{$model->id}: AI cleanup returned insufficient content, proceeding with original");
            }

            $model->is_content_cleaned = true;
            $model->save();

            if ($model->language !== 'uk') {
                TranslateNewsJob::dispatch($model->id);
            }
        } catch (\Throwable $e) {
            Log::warning("{$model->id}: AI content cleanup failed: {$e->getMessage()}");
            throw $e;
        }
    }

    private function handleManual($model): void
    {
        if ($model->is_content_cleaned) {
            return;
        }

        $needsRetranslation = $model->is_translated && $model->language !== 'uk';
        $textToClean = $needsRetranslation ? $model->original_content : $model->publish_content;

        try {
            $cleaned = $this->cleanViaAI($textToClean);

            $contentChanged = !empty($cleaned) && mb_strlen($cleaned) > 100;

            if ($contentChanged) {
                if ($needsRetranslation) {
                    $model->original_content = $cleaned;
                    $model->publish_title = $model->original_title;
                    $model->publish_content = $cleaned;
                    $model->is_translated = false;
                } else {
                    $model->publish_content = $cleaned;
                }
            } else {
                Log::warning("{$model->id}: AI cleanup returned insufficient content, proceeding with original");
            }

            $model->is_content_cleaned = true;
            $model->save();

            if ($contentChanged && $needsRetranslation) {
                TranslateNewsJob::dispatch($model->id);
            } elseif (!$contentChanged) {
                $model->updateReplyMarkup();
            }
        } catch (\Throwable $e) {
            Log::warning("{$model->id}: AI content cleanup failed: {$e->getMessage()}");
            throw $e;
        }
    }

    private function cleanViaAI(string $text): ?string
    {
        $response = OpenAI::chat()->create([
            'model' => 'gpt-5-mini',
            'messages' => [
                ['role' => 'system', 'content' => NewsController::getPrompt('cleaner')],
                ['role' => 'user', 'content' => $text],
            ],
        ]);

        return $response->choices[0]->message->content ?? null;
    }

    public function failed(?\Throwable $exception): void
    {
        $model = News::find($this->id);
        if (!$model || $model->is_content_cleaned) {
            return;
        }

        if ($this->mode === 'auto') {
            Log::warning("{$model->id}: AI cleanup exhausted retries, publishing with original content");
            $model->is_content_cleaned = true;
            $model->save();

            if ($model->language !== 'uk') {
                TranslateNewsJob::dispatch($model->id);
            }
        } else {
            Log::warning("{$model->id}: AI cleanup exhausted retries, marking cleaned with original content");
            $model->is_content_cleaned = true;
            $model->save();

            $model->updateReplyMarkup();
        }
    }
}
