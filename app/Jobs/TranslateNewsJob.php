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

class TranslateNewsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 360;

    protected int $id;

    public function __construct(int $id)
    {
        $this->id = $id;
    }

    public function handle(): void
    {
        $model = News::find($this->id);
        if ($model->is_translated) {
            return;
        }

        for ($i = 0; $i < 4; $i++) {
            try {
                Log::info("$model->id: News translation");
                $params = [
                    'model' => 'o1-preview',
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => NewsController::getPrompt('translate')
                        ],
                        ['role' => 'user', 'content' => $model->publish_title . "\n\n" . $model->publish_content]
                    ],
                ];
                $response = OpenAI::chat()->create($params);

                Log::info(
                    "$model->id: News translation result: "
                    . json_encode($response, JSON_UNESCAPED_UNICODE)
                );

                if (!empty($response->choices[0]->message->content)) {
                    [$title, $content] = explode("\n", $response->choices[0]->message->content, 2);
                    $model->is_translated = true;
                    $model->publish_title = trim($title, '*#');
                    $model->publish_content = Str::replace('**', '', trim($content));
                    $model->save();
                }
            } catch (Exception $e) {
                Log::error("$model->id: News translation fail: {$e->getMessage()}");
            }

            if ($model->is_translated) {
                break;
            }
        }
    }
}
