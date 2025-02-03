<?php

namespace App\Jobs;

use App\Models\Forward;
use App\Models\Post;
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
use OpenAI\Laravel\Facades\OpenAI;

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

        $forwardedChat = $channelPost->getForwardFromChat();
        if ($forwardedChat && $forwardedChat->getId() != $channelPost->getChat()->getId()) {
            return;
        }

        $messageId = $channelPost->getMessageId();
        $replyToPost = $channelPost->getReplyToMessage();
        if ($replyToPost) {
            $replyToPostId = $replyToPost->getMessageId();
        }

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
                                $media[$messageId] = [
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
                    'url' => $media[$messageId]['url'],
                    'path' => $media[$messageId]['path'],
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
                                    'url' => $media[$messageId]['url'],
                                    'path' => $media[$messageId]['path'],
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

                    if ($i > 0) {
                        break;
                    }
                }

                if ($isGroupHead) {
                    $text = '';
                    ksort($mediaGroup);
                    $first = true;
                    foreach ($mediaGroup as $id => $item) {
                        if (empty($text) && !empty($item['text'])) {
                            $text = $item['text'];

                            if ($first) {
                                $mediaGroup[$id]['text'] = '';
                            }

                            break;
                        }

                        $first = false;
                    }

                    $media = $mediaGroup;
                } else {
                    return;
                }
            }

            $language = Social::detectLanguage($text);

            foreach ($media as &$item) {
                if (empty($item['text'])) {
                    for ($i = 0; $i < 4; $i++) {
                        try {
                            $response = OpenAI::chat()->create(['model' => 'gpt-4o-mini', 'messages' => [
                                ['role' => 'user', 'content' => [
                                    [
                                        'type' => 'text',
                                        'text' => "Generate the image caption for visually impaired people, focusing solely on evident visual elements such as colours, shapes, objects, and any discernible text. Do not include additional descriptions, interpretations, or assumptions not explicitly visible in the image. Limit the output to 300 characters. Write the caption in the following language: $language"
                                    ],
                                    [
                                        'type' => 'image_url',
                                        'image_url' => [
                                            'url' => 'data:image/jpeg;base64,' . base64_encode(File::get($item['path'])),
                                        ]
                                    ]
                                ]]
                            ]]);

                            Log::info($messageId . ': image description : ' . json_encode($response, JSON_UNESCAPED_UNICODE));

                            if (!empty($response->choices[0]->message->content)) {
                                $item['text'] = $response->choices[0]->message->content;
                            }
                        } catch (Exception $e) {
                            Log::error($messageId . ': image description fail: ' . $e->getMessage());
                        }

                        unset($response);
                        gc_collect_cycles();

                        if (!empty($item['text'])) {
                            break;
                        }
                    }
                }
            }

            Log::info($messageId . ': ' . $text);

            $reply = null;
            $quote = null;
            if (!empty($replyToPostId)) {
                /** @var Post $replyPost */
                $replyPost = Post::query()->where([
                    'connection' => 'telegram',
                    'connection_id' => $channelPost->getChat()->getId(),
                    'post_id' => $replyToPostId,
                ])->first();

                if (!empty($replyPost)) {
                    /** @var Post $lastPost */
                    $lastPost = Post::query()->where([
                        'connection' => 'telegram',
                        'connection_id' => $channelPost->getChat()->getId(),
                    ])->orderBy('post_id', 'DESC')->first();

                    if ($lastPost->root_post_id == $replyPost->root_post_id) {
                        $reply = $replyPost;
                    } else {
                        $quote = $replyPost;
                    }
                }
            }

            foreach ($this->forwards as $forward) {
                Log::info($messageId . ': ' . json_encode($forward->getAttributes()));

                PostToSocial::dispatch(
                    $messageId,
                    $forward,
                    ['text' => $text ?? '', 'language' => $language],
                    $media,
                    $reply,
                    $quote,
                );
            }
        } catch (Exception $e) {
            Log::error($messageId . ': ' . $e->getMessage());
        }
    }
}
