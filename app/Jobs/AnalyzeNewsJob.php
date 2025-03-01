<?php
/**
 * @author Andrii Kalmus <andrii.kalmus@abysim.com>
 */

namespace App\Jobs;

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
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use OpenAI\Laravel\Facades\OpenAI;

class AnalyzeNewsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 360;

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
                    'temperature' => $model->is_deep ? 1 : 0,
                ];
                $response = OpenAI::chat()->create($params);

                Log::info(
                    "$model->id: News analysis result: "
                    . json_encode($response, JSON_UNESCAPED_UNICODE)
                );

                if (!empty($response->choices[0]->message->content)) {
                    $model->analysis = $response->choices[0]->message->content;
                    $model->status = NewsStatus::PENDING_REVIEW;
                    $model->analysis_count = $model->analysis_count + 1;
                    $model->save();
                }
            } catch (Exception $e) {
                Log::error("$model->id: News analysis fail: {$e->getMessage()}");
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
