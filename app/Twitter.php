<?php
/**
 * @package App
 * @author Abysim <abysim@whitelion.me>
 */

namespace App;

use Atymic\Twitter\ApiV1\Service\Twitter as TwitterV1;
use Atymic\Twitter\Contract\Http\Client;
use Atymic\Twitter\Facade\Twitter as TwitterFacade;
use Atymic\Twitter\Service\Querier;
use Illuminate\Support\Facades\File;

class Twitter extends Social
{
    /**
     * @param string $text
     * @param array $media
     *
     * @return mixed
     */
    public function post(string $text, array $media = []): mixed
    {
        $mediaIds = [];
        if (!empty($media)) {
            /** @var TwitterV1 $twitter */
            $twitter = TwitterFacade::forApiV1();
            foreach ($media as $item) {
                $uploadedMedia = $twitter->uploadMedia(['media' => File::get($item['path'])]);
                $mediaIds[] = $uploadedMedia->media_id_string;
            }

        }

        /** @var Querier $querier */
        $querier = TwitterFacade::forApiV2()->getQuerier();
        $params = [
            Client::KEY_REQUEST_FORMAT => Client::REQUEST_FORMAT_JSON,
            'text' => $text . '‌',
        ];
        if (!empty($uploadedMedia)) {
            $params['media'] = ['media_ids' => $mediaIds];
        }

        return $querier->post('tweets', $params);
    }
}
