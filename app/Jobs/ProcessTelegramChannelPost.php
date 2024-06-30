<?php

namespace App\Jobs;

use App\Models\Forward;
use App\MyCloudflareAI;
use App\Social;
use DeepL\Translator;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
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
     * @var int
     */
    public int $timeout = 180;


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
                    Cache::put($mediaGroupId, [$messageId => ['isHead' => true]], 60);
                } else {
                    $mediaGroup = Cache::get($mediaGroupId);
                    $mediaGroup[$messageId] = ['isHead' => false];
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

                    for ($i = 0; $i <= 4; $i++) {
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
                    'isHead' => $isGroupHead,
                ];
                Cache::put($mediaGroupId, $mediaGroup, 60);

                Log::info($messageId . ': ' . json_encode($mediaGroup[$messageId], JSON_UNESCAPED_UNICODE));

                for ($i = 0; $i < 4; $i++) {
                    sleep($isGroupHead ? 8 : 1);
                    $mediaGroup = Cache::get($mediaGroupId);
                    foreach ($mediaGroup as $id => $item) {
                        if (empty($item['path'])) {
                            if ($id == $messageId) {
                                $mediaGroup[$messageId] = [
                                    'text' => $text,
                                    'url' => $media[0]['url'],
                                    'path' => $media[0]['path'],
                                    'isHead' => $isGroupHead,
                                ];
                                Cache::put($mediaGroupId, $mediaGroup, 60);
                                Log::warning($messageId . ': media group collision. Fixing.');
                            }

                            continue 2;
                        }
                        if ($isGroupHead && $id != $messageId && $item['isHead']) {
                            if ($id < $messageId) {
                                $mediaGroup[$messageId]['isHead'] = false;
                                Cache::put($mediaGroupId, $mediaGroup, 60);
                                Log::warning($messageId . ': media group collision. Resolving.');

                                return;
                            } else {
                                Log::warning($messageId . ': media group collision. Waiting.');

                                continue 2;
                            }
                        }
                    }

                    break;
                }

                if ($isGroupHead) {
                    $text = '';
                    foreach ($mediaGroup as $id => $item) {
                        if (empty($text) && !empty($item['text'])) {
                            $text = $item['text'];

                            if ($id == $messageId) {
                                $mediaGroup[$id]['text'] = '';
                            }

                            break;
                        }
                    }

                    $media = $mediaGroup;
                } else {
                    return;
                }
            }

            $language = Social::detectLanguage($text); // TODO: remove further detections of the language and pass this value

            foreach ($media as &$item) {
                if (empty($item['text'])) {
                    for ($i = 0; $i < 4; $i++) {
                        try {
                            $response = MyCloudflareAI::runModel([
                                'image' => array_values(unpack('C*', File::get($item['path']))),
                                'prompt' => 'Generate a caption for this image',
                                'max_tokens' => 64,
                            ], 'unum/uform-gen2-qwen-500m');

                            Log::info($messageId . ': image description : ' . json_encode($response));

                            if (!empty($response['result']['description'])) {
                                $item['text'] = $response['result']['description'];

                                if ($language != 'en') {
                                    try {
                                        $translator = new Translator(config('deepl.key'));
                                        $item['text'] =
                                            (string) $translator->translateText($item['text'], 'en', $language);
                                    } catch (Exception $e) {
                                        Log::error($messageId . ': translation fail: ' . $e->getMessage());
                                    }
                                }
                            }
                        } catch (Exception $e) {
                            Log::error($messageId . ': image description fail: ' . $e->getMessage());
                        }

                        if (!empty($item['text'])) {
                            break;
                        }
                    }
                }
            }

            Log::info($messageId . ': ' . $text);

            foreach ($this->forwards as $forward) {
                Log::info($messageId . ': ' . json_encode($forward->getAttributes()));

                PostToSocial::dispatch( $messageId, $forward, $text ?? '', $media);
            }
        } catch (Exception $e) {
            Log::error($messageId . ': ' . $e->getMessage());
        }
    }
}
