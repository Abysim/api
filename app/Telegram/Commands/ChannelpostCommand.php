<?php


namespace App\Telegram\Commands;


use Atymic\Twitter\ApiV1\Service\Twitter as TwitterV1;
use Atymic\Twitter\Service\Querier;
use Atymic\Twitter\Facade\Twitter;
use Atymic\Twitter\Contract\Http\Client;
use Exception;
use Illuminate\Support\Facades\File;
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

    public function execute(): ServerResponse
    {
        // Get the channel post
        $channelPost = $this->getChannelPost();
        $data = ['chat_id' => $this->telegram->getAdminList()[0]];

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
                                $publicPath = asset(Storage::url('telegram/' . $file->getResult()->getFilePath()));
                                $path = storage_path('app/public/telegram/' . $file->getResult()->getFilePath());
                                break;
                            }
                        }
                    }
                    if (empty($path)) {
                        throw new TelegramException('File not found');
                    }

                    break;
                case 'text':
                    $text = $channelPost->getText();
                    break;
                default:
                    throw new TelegramException('Unsupported type');
            }

            if (!empty($path)) {
                /** @var TwitterV1 $twitter */
                $twitter = Twitter::forApiV1();
                $uploadedMedia = $twitter->uploadMedia(['media' => File::get($path)]);
            }

            /** @var Querier $querier */
            $querier = Twitter::forApiV2()->getQuerier();
            $params = [
                Client::KEY_REQUEST_FORMAT => Client::REQUEST_FORMAT_JSON,
                'text' => $text,
            ];
            if (!empty($uploadedMedia)) {
                $params['media'] = ['media_ids' => [$uploadedMedia->media_id_string]];
            }
            $result = $querier->post('tweets', $params);
            $data['text'] = json_encode($result);
        } catch (Exception $e) {
            $data['text'] = $e->getMessage();
        }

        return Request::sendMessage($data);
    }
}
