<?php

namespace App\Telegram\Commands;

use App\Enums\FlickrPhotoStatus;
use App\Http\Controllers\FlickrPhotoController;
use App\Models\FlickrPhoto;
use Exception;
use Illuminate\Support\Str;
use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class CallbackqueryCommand extends SystemCommand
{
    /**
     * @var string
     */
    protected $name = 'callbackquery';

    /**
     * @var string
     */
    protected $description = 'Handle the callback query';

    /**
     * @var string
     */
    protected $version = '1.0.0';

    /**
     * Main command execution
     *
     * @return ServerResponse
     * @throws Exception
     */
    public function execute(): ServerResponse
    {
        // Callback query data can be fetched and handled accordingly.
        $callbackQuery = $this->getCallbackQuery();
        $callbackData  = $callbackQuery->getData();
        $message = $callbackQuery->getMessage();

        if ($callbackData == 'delete') {
            Request::deleteMessage([
                'chat_id' => $message->getChat()->getId(),
                'message_id' => $message->getMessageId(),
            ]);

            return $callbackQuery->answer(['text' => 'Ok!']);
        }

        if ($callbackData) {
            [$action, $id] = explode(' ', $callbackData);
            /** @var FlickrPhoto $model */
            $model = FlickrPhoto::query()->find($id);

            if ($model) {
                if (
                    str_starts_with($action, 'flickr_')
                    && ($method = str_replace('flickr_', '', $action))
                    && method_exists(($controller = new FlickrPhotoController()), $method)
                ) {
                    $answer = $controller->$method($model, $message);
                } else {
                    return $callbackQuery->answer(['text' => 'Unknown action!', 'show_alert' => true]);
                }
            } else {
                return $callbackQuery->answer(['text' => 'Photo not found!', 'show_alert' => true]);
            }

            return $callbackQuery->answer($answer ?? ['text' => 'Photo status updated according to the action ' . $action]);
        }

        return $callbackQuery->answer(['text' => 'No callback data!', 'show_alert' => true]);
    }
}
