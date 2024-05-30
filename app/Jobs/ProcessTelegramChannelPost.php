<?php

namespace App\Jobs;

use App\Models\Forward;
use App\Social;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

class ProcessTelegramChannelPost implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var Message
     */
    protected Message $channelPost;

    /**
     * @var Collection
     */
    protected Collection $forwards;

    /**
     * Create a new job instance.
     * @throws TelegramException
     */
    public function __construct(Message $channelPost, Collection $forwards)
    {
        $this->channelPost = $channelPost;
        $this->forwards = $forwards;
    }

    /**
     * Execute the job.
     * @throws TelegramException
     */
    public function handle(): void
    {
        $channelPost = $this->channelPost;
        $messageId = $channelPost->getMessageId();
        $media = [];

        try {
            $isGroupHead = false;
            $mediaGroupId = $channelPost->getMediaGroupId();
            Log::info($messageId . ': job handling');
            if (!empty($mediaGroupId)) {
                if (!Cache::has($mediaGroupId)) {
                    $isGroupHead = true;
                    Cache::put($mediaGroupId, [$messageId => []], 60);
                } else {
                    $mediaGroup = Cache::get($mediaGroupId);
                    $mediaGroup[$messageId] = [];
                    Cache::put($mediaGroupId, $mediaGroup, 60);
                }
            }

            switch ($channelPost->getType()) {
                case 'photo':
                    $telegram = new Telegram(config('telegram.bot.api_token'), config('telegram.bot.username'));
                    $telegram->setDownloadPath(storage_path('app/public/telegram'));
                    $text = $channelPost->getCaption();
                    $photos = $channelPost->getPhoto();
                    $maxSize = 0;
                    foreach ($photos as $p) {
                        if ($p->getFileSize() > $maxSize) {
                            $maxSize = $p->getFileSize();
                            $photo = $p;
                        }
                    }

                    if (empty($photo)) {
                        throw new TelegramException('Photo not found');
                    }

                    for ($i = 0; $i < 5; $i++) {
                        $file = Request::getFile(['file_id' => $photo->getFileId()]);
                        if ($file->isOk()) {
                            if (Request::downloadFile($file->getResult())) {
                                $media[0] = [
                                    'url' => asset(Storage::url('telegram/' . $file->getResult()->getFilePath())),
                                    'path' => storage_path('app/public/telegram/' . $file->getResult()->getFilePath()),
                                ];
                                break;
                            }
                        }
                    }
                    if (empty($media)) {
                        throw new TelegramException('File not found');
                    }

                    break;
                case 'text':
                    $text = $channelPost->getText();
                    break;
                default:
                    throw new TelegramException('Unsupported type');
            }

            if (!empty($mediaGroupId)) {
                $mediaGroup = Cache::get($mediaGroupId);
                $mediaGroup[$messageId] = [
                    'text' => $text,
                    'url' => $media[0]['url'],
                    'path' => $media[0]['path'],
                ];
                Cache::put($mediaGroupId, $mediaGroup, 60);

                Log::info($messageId . ': ' . json_encode($mediaGroup[$messageId]));

                if ($isGroupHead) {
                    for ($i = 0; $i < 6; $i++) {
                        sleep(10);
                        $mediaGroup = Cache::get($mediaGroupId);
                        foreach ($mediaGroup as $item) {
                            if (empty($item['path'])) {
                                continue 2;
                            }
                        }

                        break;
                    }

                    $text = '';
                    foreach ($mediaGroup as $item) {
                        if (empty($text) && !empty($item['text'])) {
                            $text = $item['text'];

                            break;
                        }
                    }

                    $media = $mediaGroup;
                } else {
                    return;
                }
            }

            Log::info($messageId . ': ' . $text);

            foreach ($this->forwards as $forward) {
                Log::info($messageId . ': ' . json_encode($forward->getAttributes()));
                $socialClass = Forward::CONNECTIONS[$forward->to_connection];
                /** @var Social $social */
                $social = new $socialClass($forward->to_id);
                $resultResponse = $social->post($text, $media);
                Log::info($messageId . ': ' . json_encode($resultResponse));
            }
        } catch (Exception $e) {
            Log::error($messageId . ': ' . $e->getMessage());
        }
    }
}