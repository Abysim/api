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

class ApplyNewsAnalysisJob implements ShouldQueue
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
        if (empty($model->analysis)) {
            return;
        }

        for ($i = 0; $i < 4; $i++) {
            try {
                Log::info("$model->id: News applying analysis");
                $params = [
                    'model' => $model->is_deep ? 'o1-preview' : 'o3-mini',
                    'messages' => [
                        [
                            'role' => $model->is_deep ? 'user' : 'developer',
                            'content' => Str::replace(
                                '<date>',
                                $model->date->format('j F Y'),
                                NewsController::getPrompt('analyzer')
                            ),
                        ],
                        ['role' => 'user', 'content' => $model->publish_title . "\n\n" . $model->publish_content],
                        ['role' => 'assistant', 'content' => $model->analysis],
                        ['role' => 'user', 'content' => NewsController::getPrompt('editor')],
                    ],
                ];
                if (!$model->is_deep) {
                    $params['reasoning_effort'] = 'high';
                }
                $response = OpenAI::chat()->create($params);

                Log::info(
                    "$model->id: News applying analysis result: "
                    . json_encode($response, JSON_UNESCAPED_UNICODE)
                );

                if (!empty($response->choices[0]->message->content)) {
                    [$title, $content] = explode("\n", $response->choices[0]->message->content, 2);
                    $model->analysis = null;
                    $model->publish_title = trim($title, '*');
                    $model->publish_content = Str::replace('**', '', trim($content));
                    $model->save();
                }
            } catch (Exception $e) {
                Log::error("$model->id: News applying analysis fail: {$e->getMessage()}");
            }

            if (empty($model->analysis)) {
                break;
            }
        }
    }
}
