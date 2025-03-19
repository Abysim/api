<?php
/**
 * @author Andrii Kalmus <andrii.kalmus@abysim.com>
 */

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

class TranslateNewsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 660;

    protected int $id;

    public function __construct(int $id)
    {
        $this->id = $id;
    }

    public function handle(): void
    {
        $model = News::find($this->id);
        if ($model->is_translated || $model->status == NewsStatus::BEING_PROCESSED) {
            return;
        }
        $model->status = NewsStatus::BEING_PROCESSED;
        $model->save();

        for ($i = 0; $i < 4; $i++) {
            try {
                Log::info("$model->id: News translation");
                $params = [
                    'model' => 'deepseek-ai/DeepSeek-R1',
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => NewsController::getPrompt('translate')
                        ],
                        ['role' => 'user', 'content' => $model->publish_title . "\n\n" . $model->publish_content]
                    ],
                    'temperature' => 0,
                ];
                $response = AI::client('openrouter')->chat()->create($params);

                Log::info(
                    "$model->id: News translation result: "
                    . json_encode($response, JSON_UNESCAPED_UNICODE)
                );

                if (!empty($response->choices[0]->message->content)) {
                    $model->refresh();
                    [$title, $content] = explode("\n", $response->choices[0]->message->content, 2);
                    $model->is_translated = true;
                    $model->publish_title = trim($title, '*# ');
                    $model->publish_content = Str::replace('**', '', trim($content));
                    $model->status = NewsStatus::PENDING_REVIEW;
                    $model->save();
                }
            } catch (Exception $e) {
                Log::error("$model->id: News translation fail: {$e->getMessage()}");
            }

            if ($model->is_translated) {
                if ($model->is_auto) {
                    AnalyzeNewsJob::dispatch($model->id);
                }

                break;
            }
        }
    }
}
