<?php

namespace App\Jobs;

use App\Models\News;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CleanFreeNewsContentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
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

        if ($model->platform !== 'FreeNews' || $model->is_content_cleaned) {
            if ($model->language !== 'uk') {
                TranslateNewsJob::dispatch($model->id);
            }
            return;
        }

        try {
            $response = Http::asJson()
                ->withToken(config('services.nebius.api_key'))
                ->timeout(15)
                ->post('https://' . config('services.nebius.api_endpoint') . '/chat/completions', [
                    'model' => 'gpt-4.1-nano',
                    'messages' => [
                        ['role' => 'system', 'content' => 'Clean this news article text. Remove advertisements, cookie consent notices, navigation elements, subscription prompts, related article links, social media buttons text, and any text not part of the actual news article. Return only the clean article body text, preserving paragraph structure. Do not add any commentary.'],
                        ['role' => 'user', 'content' => $model->publish_content],
                    ],
                    'temperature' => 0,
                ]);

            $cleaned = $response->object()->choices[0]->message->content ?? null;

            if (!empty($cleaned) && mb_strlen($cleaned) > 100) {
                // Only update publish_content — content stays as raw archive
                $model->publish_content = $cleaned;
                $model->is_content_cleaned = true;
                $model->save();
            } else {
                Log::warning("{$model->id}: AI cleanup returned insufficient content, proceeding with original");
            }
        } catch (\Throwable $e) {
            Log::warning("{$model->id}: AI content cleanup failed: {$e->getMessage()}");
        }

        if ($model->language !== 'uk') {
            TranslateNewsJob::dispatch($model->id);
        }
    }
}
