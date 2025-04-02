<?php


/**
 * Admin "/excludetag" command
 *
 * Excludes specific words from chatter responses relations.
 */

namespace App\Telegram\Commands;

use App\Jobs\NewsJob;
use App\Models\ExcludedTag;
use App\Services\NewsCatcher3Service;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Longman\TelegramBot\Commands\AdminCommand;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class SubscriptionCommand extends AdminCommand
{
    /**
     * @var string
     */
    protected $name = 'subscription';

    /**
     * @var string
     */
    protected $description = 'Subscription';

    /**
     * @var string
     */
    protected $usage = '/subscription';

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
        $service = new NewsCatcher3Service();

        Request::deleteMessage([
            'chat_id' => $this->getMessage()->getChat()->getId(),
            'message_id' => $this->getMessage()->getMessageId(),
        ]);

        return Request::sendMessage([
            'chat_id' => $this->getMessage()->getChat()->getId(),
            'text' => $service->subscription(),
            'reply_markup' => new InlineKeyboard([['text' => '❌Delete', 'callback_data' => 'delete']]),
        ]);
    }
}
