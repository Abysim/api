<?php


/**
 * Admin "/deleteexcludedtag" command
 *
 * Excludes specific words from chatter responses relations.
 */

namespace App\Telegram\Commands;

use App\Models\ExcludedTag;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Longman\TelegramBot\Commands\AdminCommand;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;

class DeleteExcludedTagCommand extends AdminCommand
{
    /**
     * @var string
     */
    protected $name = 'deleteexcludedtag';

    /**
     * @var string
     */
    protected $description = 'Delete Excluded Tag';

    /**
     * @var string
     */
    protected $usage = '/deleteexcludedtag';

    /**
     * @var string
     */
    protected $version = '1.0.0';

    /**
     * Main command execution
     *
     * @return ServerResponse
     */
    public function execute(): ServerResponse
    {
        $tags = explode(' ', trim($this->getMessage()->getText(true)));

        foreach ($tags as $tag) {
            if ($tag == '@' . $this->telegram->getBotUsername() || $tag == $this->name || empty($tag)) {
                continue;
            }

            ExcludedTag::query()->where('name', Str::lower($tag))->delete();
            Log::info('Excluded tag deleted: ' . $tag);

            break;
        }

        return Request::deleteMessage([
            'chat_id' => $this->getMessage()->getChat()->getId(),
            'message_id' => $this->getMessage()->getMessageId(),
        ]);
    }
}
