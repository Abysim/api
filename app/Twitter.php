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
        if (!empty($media)) {
            /** @var TwitterV1 $twitter */
            $twitter = TwitterFacade::forApiV1();
            $uploadedMedia = $twitter->uploadMedia(['media' => File::get($media[0]['path'])]);
        }

        /** @var Querier $querier */
        $querier = TwitterFacade::forApiV2()->getQuerier();
        $params = [
            Client::KEY_REQUEST_FORMAT => Client::REQUEST_FORMAT_JSON,
            'text' => $text . '‌',
        ];
        if (!empty($uploadedMedia)) {
            $params['media'] = ['media_ids' => [$uploadedMedia->media_id_string]];
        }

        return $querier->post('tweets', $params);
    }
}
