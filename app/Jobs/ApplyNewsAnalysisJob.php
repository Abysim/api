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
                Log::info("$model->id: News applying analysis");
                $params = [
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        [
                            'role' => 'developer',
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
                $response = OpenAI::chat()->create($params);

                Log::info(
                    "$model->id: News applying analysis result: "
                    . json_encode($response, JSON_UNESCAPED_UNICODE)
                );

                if (!empty($response->choices[0]->message->content)) {
                    [$title, $content] = explode("\n", $response->choices[0]->message->content, 2);
                    if (!$model->is_deep || $i > 0) {
                        $model->status = NewsStatus::PENDING_REVIEW;
                        $model->analysis = null;
                    }
                    $model->publish_title = trim($title, '*# ');
                    $model->publish_content = Str::replace('**', '', trim($content));
                    $model->save();

                    if (!empty($tempContent) && $tempContent == $model->publish_content) {
                        Log::warning("$model->id: News applying analysis: Same content at reapply!");
                        Request::sendMessage([
                            'chat_id' => explode(',', config('telegram.admins'))[0],
                            'reply_to_message_id' => $model->message_id,
                            'text' => 'Same content at reapply! ' . $model->analysis_count,
                            'reply_markup' => new InlineKeyboard([['text' => '❌Delete', 'callback_data' => 'delete']]),
                        ]);
                    } else {
                        $tempContent = $model->publish_content;
                    }
                }
            } catch (Exception $e) {
                Log::error("$model->id: News applying analysis fail: {$e->getMessage()}");
            }

            if (empty($model->analysis)) {
                if ($model->is_auto) {
                   if ($model->analysis_count < 4) {
                        AnalyzeNewsJob::dispatch($model->id);
                   } else {
                       if (!$model->is_deep) {
                           $model->is_deep = true;
                           $model->analysis_count = 0;
                           $model->save();
                           AnalyzeNewsJob::dispatch($model->id);
                       } else {
                           $model->is_auto = false;
                           $model->save();

                           Request::sendMessage([
                               'chat_id' => explode(',', config('telegram.admins'))[0],
                               'reply_to_message_id' => $model->message_id,
                               'text' => 'Analysis limit reached without success',
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
