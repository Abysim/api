<?php

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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;

class ApplyNewsAnalysisJob implements ShouldQueue
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
        if (
            empty($model->analysis)
            || $model->status == NewsStatus::BEING_PROCESSED
            || Str::substr(trim($model->analysis, '*# '), 0, 2) == 'Ні'
        ) {
            return;
        }
        $model->status = NewsStatus::BEING_PROCESSED;
        $model->save();

        for ($i = 0; $i < 4; $i++) {
            try {
                Log::info("$model->id: News applying analysis $model->analysis_count");
                $params = [
                    'model' => 'deepseek-ai/DeepSeek-V3',
                    'messages' => [
                        [
                            'role' => 'system',
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
                    'temperature' => 0,
                ];
                $response = AI::client('openrouter')->chat()->create($params);

                Log::info(
                    "$model->id: News applying analysis $model->analysis_count result: "
                    . json_encode($response, JSON_UNESCAPED_UNICODE)
                );

                if (!empty($response->choices[0]->message->content)) {
                    $content = trim(Str::after($response->choices[0]->message->content, '```markdown'));
                    [$title, $content] = explode("\n", $content, 2);
                    $model->status = NewsStatus::PENDING_REVIEW;
                    $model->analysis = null;
                    $model->publish_title = trim($title, '*# ');
                    $model->publish_content = Str::replace('**', '', trim($content));
                    $model->save();
                }
            } catch (Exception $e) {
                Log::error("$model->id: News applying analysis $model->analysis_count fail: {$e->getMessage()}");
            }

            if (empty($model->analysis)) {
                if ($model->is_auto) {
                   if ($model->analysis_count < 16) {
                       AnalyzeNewsJob::dispatch($model->id);
                   } else {
                       if (!$model->is_deep) {
                           $model->is_deep = true;
                           $model->analysis_count = 0;
                           $model->save();

                           Request::sendMessage([
                               'chat_id' => explode(',', config('telegram.admins'))[0],
                               'reply_to_message_id' => $model->message_id,
                               'text' => 'Deep analysis limit reached without success',
                               'reply_markup' => new InlineKeyboard([['text' => '❌Delete', 'callback_data' => 'delete']]),
                           ]);

                           AnalyzeNewsJob::dispatch($model->id);
                       } else {
                           $model->is_auto = false;
                           $model->save();

                           Request::sendMessage([
                               'chat_id' => explode(',', config('telegram.admins'))[0],
                               'reply_to_message_id' => $model->message_id,
                               'text' => 'Deepest analysis limit reached without success',
                               'reply_markup' => new InlineKeyboard([['text' => '❌Delete', 'callback_data' => 'delete']]),
                           ]);
                       }
                   }
                }

                break;
            }
        }
    }
}
