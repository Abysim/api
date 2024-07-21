<?php

namespace App\Telegram\Commands;

use App\Enums\FlickrPhotoStatus;
use App\Http\Controllers\FlickrPhotoController;
use App\Models\FlickrPhoto;
use Illuminate\Support\Facades\File;
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
                switch ($action) {
                    case 'flickr_approve':
                        $model->status = FlickrPhotoStatus::APPROVED;
                        $model->save();

                        if ($message) {
                            Request::editMessageReplyMarkup([
                                'chat_id' => $message->getChat()->getId(),
                                'message_id' => $message->getMessageId(),
                                'reply_markup' => new InlineKeyboard([
                                    ['text' => '❌Cancel Approval', 'callback_data' => 'flickr_cancel ' . $model->id],
                                ]),
                            ]);
                        }

                        $controller = new FlickrPhotoController();
                        $controller->publish();

                        break;
                    case 'flickr_decline':
                        $model->status = FlickrPhotoStatus::REJECTED_MANUALLY;
                        $model->save();
                    case 'flickr_delete':
                        $model->deleteFile();

                        if ($message) {
                            Request::deleteMessage([
                                'chat_id' => $message->getChat()->getId(),
                                'message_id' => $message->getMessageId(),
                            ]);
                        }

                        break;
                    case 'flickr_cancel':
                        $model->status = FlickrPhotoStatus::PENDING_REVIEW;
                        $model->save();

                        if ($message) {
                            Request::editMessageReplyMarkup([
                                'chat_id' => $message->getChat()->getId(),
                                'message_id' => $message->getMessageId(),
                                'reply_markup' => $model->getInlineKeyboard(),
                            ]);
                        }

                        break;
                    case 'flickr_review':
                        $model->status = FlickrPhotoStatus::PENDING_REVIEW;
                        $model->save();

                        $controller = new FlickrPhotoController();
                        $controller->processCreatedPhotos([$model]);

                        break;
                    case 'flickr_original':
                        return $callbackQuery->answer([
                            'text' => Str::substr($model->title . "\n" . implode(' ', $model->tags), 0, 200),
                            'show_alert' => true,
                        ]);
                    default:
                        return $callbackQuery->answer(['text' => 'Unknown action!', 'show_alert' => true]);
                }
            } else {
                return $callbackQuery->answer(['text' => 'Photo not found!', 'show_alert' => true]);
            }

            return $callbackQuery->answer(['text' => 'Photo status updated according to the action ' . $action]);
        }

        return $callbackQuery->answer(['text' => 'No callback data!', 'show_alert' => true]);
    }
}
