<?php


/**
 * Admin "/showexcludedtags" command
 *
 * Excludes specific words from chatter responses relations.
 */

namespace App\Telegram\Commands;

use App\Models\ExcludedTag;
use Longman\TelegramBot\Commands\AdminCommand;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;

class ShowExcludedTagsCommand extends AdminCommand
{
    /**
     * @var string
     */
    protected $name = 'showexcludedtags';

    /**
     * @var string
     */
    protected $description = 'Show Excluded Tags';

    /**
     * @var string
     */
    protected $usage = '/showexcludedtags';

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
        Request::deleteMessage([
            'chat_id' => $this->getMessage()->getChat()->getId(),
            'message_id' => $this->getMessage()->getMessageId(),
        ]);

        return Request::sendMessage([
            'chat_id' => $this->getMessage()->getChat()->getId(),
            'text' => implode(' ', ExcludedTag::query()->pluck('name')->all()),
            'reply_markup' => new InlineKeyboard([
                ['text' => 'Exclude Tag', 'switch_inline_query_current_chat' => 'excludetag '],
                ['text' => 'Remove Excluded Tag', 'switch_inline_query_current_chat' => 'deleteexcludedtag ']
            ], [
                ['text' => '❌Delete', 'callback_data' => 'delete']
            ]),
        ]);
    }
}
