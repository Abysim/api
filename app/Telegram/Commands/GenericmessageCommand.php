<?php

namespace App\Telegram\Commands;

use App\Models\FlickrPhoto;
use Illuminate\Support\Facades\Cache;
use Longman\TelegramBot\Commands\SystemCommand;
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
        $text = $message->getText(true);

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
        /** @var FlickrPhoto $model */
        $model = FlickrPhoto::query()->find($id);
        if (empty($model)) {
            return Request::emptyResponse();
        }

        if (empty($value)) {
            return Request::emptyResponse();
        }

        if ($action == 'flickr_title') {
            $model->publish_title = $value;
        } elseif ($action == 'flickr_tags') {
            $model->publish_tags = $value;
        } else {
            return Request::emptyResponse();
        }

        $model->save();
        Request::deleteMessage([
            'chat_id' => $message->getChat()->getId(),
            'message_id' => $message->getMessageId(),
        ]);

        if ($model->message_id) {
            Request::editMessageCaption([
                'chat_id' => $message->getChat()->getId(),
                'message_id' => $model->message_id,
                'caption' => $model->getCaption(),
                'reply_markup' => $model->getInlineKeyboard(),
            ]);

            $response = Request::sendMessage([
                'chat_id' => $message->getChat()->getId(),
                'reply_to_message_id' => $model->message_id,
                'text' => 'Photo status updated according to the action ' . $action,
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
