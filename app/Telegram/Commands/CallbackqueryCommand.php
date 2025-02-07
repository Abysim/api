<?php

namespace App\Telegram\Commands;

use App\Enums\FlickrPhotoStatus;
use App\Http\Controllers\FlickrPhotoController;
use App\Http\Controllers\NewsController;
use App\Models\FlickrPhoto;
use App\Models\News;
use Exception;
use Illuminate\Database\Eloquent\Model;
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
            [$type, $method] = explode('_', $action);
            switch ($type) {
                case 'flickr':
                    $modelClass = FlickrPhoto::class;
                    $controllerClass = FlickrPhotoController::class;
                    $name = 'Photo';
                    break;
                case 'news':
                    $modelClass = News::class;
                    $controllerClass = NewsController::class;
                    $name = 'News';
                    break;
                default:
                    return $callbackQuery->answer(['text' => 'Unknown type!', 'show_alert' => true]);
            }

            /** @var Model $model */
            $model = $modelClass::find($id);

            if ($model) {
                if (method_exists(($controller = app($controllerClass)), $method)) {
                    $answer = $controller->$method($model, $message);
                } else {
                    return $callbackQuery->answer(['text' => 'Unknown action!', 'show_alert' => true]);
                }
            } else {
                return $callbackQuery->answer(['text' => $name . ' not found!', 'show_alert' => true]);
            }

            return $callbackQuery->answer($answer ?? ['text' => $name . ' status updated according to the action ' . $action]);
        }

        return $callbackQuery->answer(['text' => 'No callback data!', 'show_alert' => true]);
    }
}
