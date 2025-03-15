<?php


/**
 * Admin "/excludetag" command
 *
 * Excludes specific words from chatter responses relations.
 */

namespace App\Telegram\Commands;

use App\Jobs\NewsJob;
use App\Models\ExcludedTag;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Longman\TelegramBot\Commands\AdminCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class NewsCommand extends AdminCommand
{
    /**
     * @var string
     */
    protected $name = 'news';

    /**
     * @var string
     */
    protected $description = 'News';

    /**
     * @var string
     */
    protected $usage = '/news <load force lang>';

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
        [$load, $force, $lang] = array_pad(explode(' ', trim($this->getMessage()->getText(true)), 4), 3, null);

        NewsJob::dispatch($load ?? false, $force ?? false, $lang ?? null);

        return Request::deleteMessage([
            'chat_id' => $this->getMessage()->getChat()->getId(),
            'message_id' => $this->getMessage()->getMessageId(),
        ]);
    }
}
