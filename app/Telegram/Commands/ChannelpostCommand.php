<?php


namespace App\Telegram\Commands;


use App\Jobs\ProcessTelegramChannelPost;
use App\Models\Forward;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;


class ChannelpostCommand extends SystemCommand
{

    /** @var string Command name */
    protected $name = 'channelpost';
    /** @var string Command description */
    protected $description = 'Handle channel post';
    /** @var string Usage description */
    protected $version = '1.0.0';

    /**
     * @throws TelegramException
     */
    public function execute(): ServerResponse
    {
        // Get the channel post
        $channelPost = $this->getChannelPost();
        if (str_contains($channelPost->getText() ?? '', '‌')) {
            return Request::emptyResponse();
        }

        $forwards = Forward::query()
            ->where('from_connection', 'telegram')
            ->where('from_id', (string) $channelPost->getChat()->getId())
            ->get();

        Log::info($channelPost->getMessageId() . ': ' . $forwards->count() . ' ' . $channelPost->getChat()->getId());

        if ($forwards->count()) {
            ProcessTelegramChannelPost::dispatch($channelPost, $forwards);
            Log::info($channelPost->getMessageId() . ": job dispatched");
        }

        return Request::emptyResponse();
    }
}
