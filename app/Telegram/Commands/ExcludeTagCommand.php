<?php


/**
 * Admin "/excludetag" command
 *
 * Excludes specific words from chatter responses relations.
 */

namespace App\Telegram\Commands;

use App\Models\ExcludedTag;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Longman\TelegramBot\Commands\AdminCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class ExcludeTagCommand extends AdminCommand
{
    /**
     * @var string
     */
    protected $name = 'excludetag';

    /**
     * @var string
     */
    protected $description = 'Exclude Tag';

    /**
     * @var string
     */
    protected $usage = '/excludetag <word1 word2 word3...>';

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
        $data = [];
        foreach ($tags as $tag) {
            if ($tag == '@' . $this->telegram->getBotUsername() || $tag == $this->name) {
                continue;
            }

            $data[] = ['name' => Str::lower($tag)];
        }

        if (!empty($data)) {
            ExcludedTag::query()->insertOrIgnore($data);
            Log::info('Tags excluded: ' . implode(' ', array_column($data, 'name')));
        }

        return Request::deleteMessage([
            'chat_id' => $this->getMessage()->getChat()->getId(),
            'message_id' => $this->getMessage()->getMessageId(),
        ]);
    }
}
