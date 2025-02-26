<?php
/**
 * @author Andrii Kalmus <andrii.kalmus@abysim.com>
 */

namespace App\Jobs;

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
use OpenAI\Laravel\Facades\OpenAI;

class AnalyzeNewsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 360;

    public function __construct(private readonly int $id)
    {
    }

    public function handle(): void
    {
        $model = News::find($this->id);
        if (!empty($model->analysis)) {
            return;
        }

        for ($i = 0; $i < 4; $i++) {
            try {
                Log::info("$model->id: News analysis");
                $params = [
                    'model' => $model->is_deep ? 'o1-preview' : 'chatgpt-4o-latest',
                    'messages' => [
                        [
                            'role' => $model->is_deep ? 'user' : 'developer',
                            'content' => Str::replace(
                                '<date>',
                                $model->date->format('j F Y'),
                                NewsController::getPrompt('analyzer')
                            ),
                        ],
                        ['role' => 'user', 'content' => $model->publish_title . "\n\n" . $model->publish_content]
                    ],
                ];
                $response = OpenAI::chat()->create($params);

                Log::info(
                    "$model->id: News analysis result: "
                    . json_encode($response, JSON_UNESCAPED_UNICODE)
                );

                if (!empty($response->choices[0]->message->content)) {
                    $model->analysis = $response->choices[0]->message->content;
                    $model->save();
                }
            } catch (Exception $e) {
                Log::error("$model->id: News analysis fail: {$e->getMessage()}");
            }

            if (!empty($model->analysis)) {
                break;
            }
        }
    }
}
