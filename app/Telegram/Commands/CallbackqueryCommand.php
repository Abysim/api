<?php

namespace App\Telegram\Commands;

use App\Enums\FlickrPhotoStatus;
use App\Http\Controllers\FlickrPhotoController;
use App\Models\FlickrPhoto;
use Illuminate\Support\Facades\File;
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
     * @throws \Exception
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
                if ($action == 'flickr_approve') {
                    $model->status = FlickrPhotoStatus::APPROVED;
                    $model->save();

                    $controller = new FlickrPhotoController();
                    $controller->publish();
                } elseif ($action == 'flickr_decline') {
                    $model->status = FlickrPhotoStatus::REJECTED_MANUALLY;
                    File::delete($model->getFilePath());
                    $model->save();
                } elseif ($action == 'flickr_original') {
                    return $callbackQuery->answer(['text' => $model->title, 'show_alert' => true]);
                } else {
                    return $callbackQuery->answer(['text' => 'Unknown action!', 'show_alert' => true]);
                }
            } else {
                return $callbackQuery->answer(['text' => 'Photo not found!', 'show_alert' => true]);
            }

            if ($message) {
                Request::editMessageReplyMarkup([
                    'chat_id' => $message->getChat()->getId(),
                    'message_id' => $message->getMessageId(),
                    'reply_markup' => new InlineKeyboard([]),
                ]);
            }

            return $callbackQuery->answer(['text' => 'Photo status updated according to the action ' . $action]);
        }

        return $callbackQuery->answer(['text' => 'No callback data!', 'show_alert' => true]);
    }
}
