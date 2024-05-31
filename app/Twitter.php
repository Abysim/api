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
    const MAX_TEXT_LENGTH = 280;
    const MAX_MEDIA_COUNT = 4;
    const MAX_LINK_LENGTH = 24;

    /**
     * @param string $text
     * @param array $media
     *
     * @return mixed
     */
    public function post(string $text, array $media = [], mixed $reply = null): mixed
    {
        $posts = $this->splitPost($text, $media);
        if (!empty($posts)) {
            $results = [];
            foreach ($posts as $post) {
                if (!empty($result->data->id)) {
                    $reply = ['in_reply_to_tweet_id' => $result->data->id];
                }

                $result = $this->post($post['text'], $post['media'], $reply);
                $results[] = $result;
            }
            return $results;
        }

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
            'text' => $text,// . '‌',
        ];
        if (!empty($uploadedMedia)) {
            $params['media'] = ['media_ids' => $mediaIds];
        }
        if (!empty($reply)) {
            $params['reply'] = $reply;
        }

        return $querier->post('tweets', $params);
    }
}
