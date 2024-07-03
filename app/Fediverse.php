<?php
/**
 * @package App
 * @author Abysim <abysim@whitelion.me>
 */

namespace App;

use App\Models\FediverseConnection;
use App\Models\Post;
use App\Models\PostForward;
use Exception;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Fediverse extends Social
{
    /**
     * @var FediverseConnection
     */
    protected FediverseConnection $connection;

    /**
     * @param int $connection
     * @param string $apiUri
     *
     * @throws Exception
     */
    public function __construct(int $connection)
    {
        /* @var FediverseConnection $connection */
        $connection = FediverseConnection::query()->find($connection);

        if (empty($connection)) {
            throw new Exception('Connection not found');
        }

        $this->connection = $connection;
    }

    /**
     * @inheritDoc
     * @throws Exception
     */
    public function post(
        array $textData = [],
        array $media = [],
        mixed $reply = null,
        mixed $root = null,
        mixed $quote = null
    ): mixed {
        $data = [];
        if (!empty($textData['text'])) {
            $data['status'] = $textData['text'];
            $data['language'] = $textData['language'] ?? static::detectLanguage($textData['text']);
        }

        if (!empty($this->connection->cat)) {
            $data['friendica'] = ['category' => $this->connection->cat];
        }

        $mediaIds = [];
        foreach ($media as $item) {
            $result = $this->request('v2/media', ['description' => $item['text'] ?? ''], $item['path']);
            Log::info('Image uploaded: ' . json_encode($result, JSON_UNESCAPED_UNICODE));

            $mediaIds[] = $result->id;
        }

        if (!empty($mediaIds)) {
            $data['media_ids'] = $mediaIds;
        }

        if (!empty($reply)) {
            $data['in_reply_to_id'] = $reply;
        }

        if (!empty($quote)) {
            $data['quote_id'] = $quote;
        }


        $result = $this->request('v1/statuses', $data);

        if (!empty($result->id)) {
            /** @var Post $post */
            $post = Post::query()->updateOrCreate([
                'connection' => 'fediverse',
                'connection_id' => $this->connection->id,
                'post_id' => $result->id,
                'parent_post_id' => $reply ?? $result->id,
                'root_post_id' => $root ?? $result->id,
            ]);
            static::createPostForward($post->id, $textData['post_id'], array_column($media, 'post_id'));
        }

        return $result;
    }

    /**
     * @throws Exception
     */
    private function request(string $request, array $args = [], ?string $file = null): mixed
    {
        $url = $this->connection->url . 'api/' . $request;
        $request = Http::withToken($this->connection->token);

        if (!empty($file)) {
            $request = $request->attach('file', File::get($file), File::basename($file));
        }

        return $request->post($url, $args)->object();
    }
}
