<?php

namespace App\Jobs;

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

    public function __construct(int $id)
    {
        $this->id = $id;
    }

    public function handle(): void
    {
        $model = News::find($this->id);
        if (!$model) {
            return;
        }

        if ($model->is_content_cleaned) {
            return;
        }

        $needsRetranslation = $model->is_translated && $model->language !== 'uk';
        $textToClean = $needsRetranslation ? $model->original_content : $model->publish_content;

        try {
            $response = OpenAI::chat()->create([
                'model' => 'gpt-5-mini',
                'messages' => [
                    ['role' => 'system', 'content' => 'Clean this news article text. Remove advertisements, cookie consent notices, navigation elements, subscription prompts, related article links, social media buttons text, and any text not part of the actual news article. Return only the clean article body text, preserving paragraph structure. Do not add any commentary.'],
                    ['role' => 'user', 'content' => $textToClean],
                ],
                'temperature' => 0,
            ]);

            $cleaned = $response->choices[0]->message->content ?? null;

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

    public function failed(?\Throwable $exception): void
    {
        $model = News::find($this->id);
        if ($model && !$model->is_content_cleaned) {
            Log::warning("{$model->id}: AI cleanup exhausted retries, marking cleaned with original content");
            $model->is_content_cleaned = true;
            $model->save();

            $model->updateReplyMarkup();
        }
    }
}
