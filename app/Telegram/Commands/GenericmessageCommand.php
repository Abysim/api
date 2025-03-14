<?php

namespace App\Telegram\Commands;

use App\Models\FlickrPhoto;
use App\Models\News;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\InputMedia\InputMediaPhoto;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;

class GenericmessageCommand extends SystemCommand
{
    /**
     * @var string
     */
    protected $name = 'genericmessage';

    /**
     * @var string
     */
    protected $description = 'Handle generic message';

    /**
     * @var string
     */
    protected $version = '1.0.0';

    /**
     * Main command execution
     *
     * @return ServerResponse
     * @throws TelegramException
     */
    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        if ($message->getType()  == 'photo') {
            $text = $message->getCaption();
            $baseUrl = 'https://api.telegram.org/file/bot' . $this->getTelegram()->getApiKey();

            $photos = $message->getPhoto();
            $maxSize = 0;
            foreach ($photos as $p) {
                if ($p->getFileSize() > $maxSize) {
                    $maxSize = $p->getFileSize();
                    $photo = $p;
                }
            }

            if (!empty($photo)) {
                $filePath = Request::getFile(['file_id' => $photo->getFileId()])->getResult()->getFilePath();

                $photoUrl = $baseUrl . '/'. $filePath;
            }
        } else {
            $text = $message->getText(true);
        }

        if (empty($message->getFrom()) || empty($text) || !$this->telegram->isAdmin()) {
            return Request::emptyResponse();
        }

        [$botHandle, $action, $id, $value] = array_pad(explode(' ', $text, 4), 4, null);
        if (trim($botHandle, '@') != $this->telegram->getBotUsername()) {
            return Request::emptyResponse();
        }

        if (in_array($action, ['excludetag', 'deleteexcludedtag'])) {
            return $this->getTelegram()->executeCommand($action);
        }

        if (empty($id)) {
            return Request::emptyResponse();
        }

        [$type, $action] = explode('_', $action, 2);
        switch ($type) {
            case 'flickr':
                $modelClass = FlickrPhoto::class;
                $name = 'Photo';
                break;
            case 'news':
                $modelClass = News::class;
                $name = 'News';
                break;
            default:
                return  Request::emptyResponse();
        }

        /** @var News|FlickrPhoto $model */
        $model = $modelClass::find($id);
        if (empty($model)) {
            return Request::emptyResponse();
        }

        if (!empty($photoUrl)) {
            $value = $photoUrl;
        }

        if (empty($value)) {
            return Request::emptyResponse();
        }

        if ($action == 'title') {
            $model->publish_title = $value;
        } elseif ($action == 'tags') {
            $model->publish_tags = $value;
        } elseif ($action == 'image') {
            $model->media = $value;
        } else {
            return Request::emptyResponse();
        }

        $model->save();
        Request::deleteMessage([
            'chat_id' => $message->getChat()->getId(),
            'message_id' => $message->getMessageId(),
        ]);

        if ($model->message_id) {
            $response = Request::sendMessage([
                'chat_id' => $message->getChat()->getId(),
                'reply_to_message_id' => $model->message_id,
                'text' => $name . ' status updated according to the action ' . $action,
            ]);

            $telegramReplyMessageId = Cache::get('telegramReplyMessageId');
            if ($telegramReplyMessageId) {
                Request::deleteMessage([
                    'chat_id' => $message->getChat()->getId(),
                    'message_id' => $telegramReplyMessageId,
                ]);
            }
            Cache::put('telegramReplyMessageId', $response->getResult()->getMessageId());
        }

        return Request::emptyResponse();
    }
}
