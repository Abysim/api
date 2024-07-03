<?php
/**
 * @package App
 * @author Abysim <abysim@whitelion.me>
 */

namespace App;

use App\Models\Post;
use App\Models\PostForward;
use App\Models\TwitterConnection;
use Atymic\Twitter\ApiV1\Service\Twitter as TwitterV1;
use Atymic\Twitter\Contract\Http\Client;
use Atymic\Twitter\Facade\Twitter as TwitterFacade;
use Atymic\Twitter\Service\Querier;
use Exception;
use Illuminate\Support\Facades\File;

class Twitter extends Social
{
    const MAX_TEXT_LENGTH = 280;
    const MAX_MEDIA_COUNT = 4;
    const MAX_LINK_LENGTH = 24;

    /**
     * @var TwitterConnection
     */
    protected TwitterConnection $connection;

    /**
     * @param int $id
     *
     * @throws Exception
     */
    public function __construct(int $id)
    {
        /** @var TwitterConnection $connection */
        $connection = TwitterConnection::query()->find($id);

        if (empty($connection)) {
            throw new Exception('Connection not found');
        }

        $this->connection = $connection;
    }

    /**
     * @param array $text
     * @param array $media
     *
     * @return mixed
     */
    public function post(array $textData = [], array $media = [], mixed $reply = null, mixed $root = null): mixed
    {
        $text = $textData['text'] ?? '';
        $posts = $this->splitPost($text, $media);
        if (!empty($posts)) {
            $results = [];
            foreach ($posts as $post) {
                if (!empty($result->data->id)) {
                    $reply = $result->data->id;
                }

                $textData['text'] = $post['text'];
                $result = $this->post($textData, $post['media'], $reply, $root);
                if (empty($root) && !empty($result->data->id)) {
                    $root = $result->data->id;
                }
                $results[] = $result;
            }
            return $results;
        }

        $mediaIds = [];
        if (!empty($media)) {
            /** @var TwitterV1 $twitter */
            $t = TwitterFacade::forApiV1();
            $twitter = $t->usingCredentials($this->connection->token, $this->connection->secret);
            foreach ($media as $item) {
                $uploadedMedia = $twitter->uploadMedia(['media' => File::get($item['path'])]);
                if (!empty($item['text'])) {
                    $twitter->post('media/metadata/create', [
                        Client::KEY_REQUEST_FORMAT => Client::REQUEST_FORMAT_JSON,
                        Client::KEY_RESPONSE_FORMAT => Client::RESPONSE_FORMAT_JSON,
                        'media_id' => $uploadedMedia->media_id_string,
                        'alt_text' => [
                            'text' => $item['text'],
                        ],
                    ]);
                }
                $mediaIds[] = $uploadedMedia->media_id_string;
            }
        }

        $t = TwitterFacade::forApiV2();
        /** @var Querier $querier */
        $querier = $t->usingCredentials($this->connection->token, $this->connection->secret)->getQuerier();
        $params = [
            Client::KEY_REQUEST_FORMAT => Client::REQUEST_FORMAT_JSON,
            'text' => $text,// . '‌',
        ];
        if (!empty($uploadedMedia)) {
            $params['media'] = ['media_ids' => $mediaIds];
        }
        if (!empty($reply)) {
            $params['reply'] = ['in_reply_to_tweet_id' => $reply];
        }

        $result = $querier->post('tweets', $params);

        if (!empty($result->data->id)) {
            /** @var Post $post */
            $post = Post::query()->updateOrCreate([
                'connection' => 'twitter',
                'connection_id' => $this->connection->id,
                'post_id' => $result->data->id,
                'parent_post_id' => $reply ?? $result->data->id,
                'root_post_id' => $root ?? $result->data->id,
            ]);
            static::createPostForward($post->id, $textData['post_id'], array_column($media, 'post_id'));
        }

        return $result;
    }
}
