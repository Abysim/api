<?php


namespace App\Telegram\Commands;


use App\Models\Forward;
use App\Social;
use Exception;
use Illuminate\Support\Facades\Storage;
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

        $forwards = Forward::query()
            ->where('from_connection', 'telegram')
            ->where('from_id', (string) $channelPost->getChat()->getId())
            ->get();

        $data = ['chat_id' => $this->telegram->getAdminList()[0]];
        $data['text'] = $forwards->count() . ' ' . $channelPost->getChat()->getId() . "\n";
        if (!$forwards->count()) {
            return Request::sendMessage($data);
        }

        $media = [];

        try {
            switch ($channelPost->getType()) {
                case 'photo':
                    $this->telegram->setDownloadPath(storage_path('app/public/telegram'));
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
                                $media[] = [
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

            $data['text'] .= $text . "\n";

            foreach ($forwards as $forward) {
                $data['text'] .= json_encode($forward->getAttributes());
                $socialClass = Forward::CONNECTIONS[$forward->to_connection];
                /** @var Social $social */
                $social = new $socialClass($forward->to_id);
                $data['text'] .= ': ' . json_encode($social->post($text, $media)) . "\n";
            }
        } catch (Exception $e) {
            $data['text'] = $e->getMessage();
        }

        return Request::sendMessage($data);
    }
}
