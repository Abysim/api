<?php
/**
 * @package App
 * @author Abysim <abysim@whitelion.me>
 */

namespace App;

use App\Models\BlueskyConnection;
use cjrasmussen\BlueskyApi\BlueskyApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use JsonException;
use Exception;

class Bluesky extends Social
{
    const MAX_TEXT_LENGTH = 300;
    const MAX_MEDIA_COUNT = 4;
    const MAX_LINK_LENGTH = 24;


    /**
     * @var BlueskyConnection
     */
    protected BlueskyConnection $connection;

    /**
     * @var BlueskyApi
     */
    protected BlueskyApi $api;

    /**
     * @param int|BlueskyConnection $connection
     *
     * @throws Exception
     */
    public function __construct(int|BlueskyConnection $connection)
    {
        if (is_numeric($connection)) {
            $connection = BlueskyConnection::query()->find($connection);

            if (empty($connection)) {
                throw new Exception('Connection not found');
            }
        }

        $this->connection = $connection;
        if ($connection->did && $connection->jwt) {
            $this->api = new BlueskyApi();
            $this->api->setAccountDid($connection->did);
            $this->api->setApiKey($connection->jwt);
        } else {
            $this->api = new BlueskyApi($connection->handle, $connection->password);

            $connection->jwt = $this->getApiKey();
            $connection->did = $this->api->getAccountDid();
            $connection->save();
        }
    }

    /**
     * @param array $args
     * @param array $urls
     *
     * @return array
     */
    private function addUrls(array $args, array $urls): array
    {
        if (empty($urls)) {
            return $args;
        }

        if (!isset($args['record']['facets'])) {
            $args['record']['facets'] = [];
        }

        foreach ($urls as $url) {
            $args['record']['facets'][] = [
                'index' => [
                    'byteStart' => $url['start'],
                    'byteEnd' => $url['end'],
                ],
                'features' => [
                    [
                        '$type' => 'app.bsky.richtext.facet#link',
                        'uri' => $url['url'],
                    ],
                ],
            ];
        }

        return $args;
    }

    /**
     * Tries retrieve a new token is the old token invalid during request
     *
     * @param string $type
     * @param string $request
     * @param array $args
     * @param string|null $body
     * @param string|null $contentType
     *
     * @return mixed|object
     * @throws JsonException
     */
    public function request(
        string $type,
        string $request,
        array $args = [],
        ?string $body = null,
        ?string $contentType = null
    ): mixed {
        $response = $this->api->request($type, $request, $args, $body, $contentType);

        if (isset($response->error) && in_array($response->error, ['InvalidToken', 'ExpiredToken'])) {
            $keyArgs = [
                'identifier' => $this->connection->did,
                'password' => $this->connection->password,
            ];
            $data = $this->api->request('POST', 'com.atproto.server.createSession', $keyArgs);

            $this->connection->jwt = $data->accessJwt;
            $this->api->setApiKey($data->accessJwt);
            $this->connection->save();

            $response = $this->api->request($type, $request, $args, $body, $contentType);
        }

        return $response;
    }

    /**
     * @todo: remove hacky logic of retrieving private property value
     * @return mixed
     */
    public function getApiKey(): string
    {
        $array = (array) $this->api;
        foreach ($array as $key => $value) {
            $propertyNameParts = explode("\0", $key);
            $propertyName = end($propertyNameParts);
            if ($propertyName === 'apiKey') {
                return $value;
            }
        }
    }

    /**
     * @param string $text
     *
     * @return array
     */
    public static function parseUrl(string $text): array
    {
        $spans = [];

        $matches = static::getParsedUrls($text);

        foreach($matches as $match) {
            $spans[] = [
                "start" => $match[1],
                "end" => $match[1] + strlen($match[0]),
                "url" => $match[0]
            ];
        }

        return $spans;
    }

    /**
     * @param array $args
     * @param string|null $imageUrl
     *
     * @return array
     * @throws JsonException
     */
    private function addImages(array $args, ?array $media = []): array
    {
        $images = [];
        foreach ($media as $item) {
            $image = $item['path'] ?? $item['url'] ?? null;
            if (empty($image)) {
                continue;
            }

            $body = '';
            for ($i = 0; $i < 5; $i++) {
                try {
                    $body = file_get_contents($image);
                    break;
                } catch (Exception $e) {
                    if ($i == 4) {
                        continue;
                    }

                    Log::info('Image reading problem: ' . $e->getMessage());
                    sleep($i * $i);
                }
            }

            $fh = fopen('php://memory', 'w+b');
            fwrite($fh, $body);
            $type = mime_content_type($fh);
            fclose($fh);

            Log::info('Image Type: ' . $type);
            if (!in_array($type, ['image/png', 'image/jpg', 'image/jpeg'])) {
                Log::info('Unsupported image type!');

                return $args;
            }

            $response = $this->request('POST', 'com.atproto.repo.uploadBlob', [], $body, $type);
            Log::info('Image: ' . json_encode($response));
            // retry if uploading failed
            if (!isset($response->blob)) {
                $response = $this->request('POST', 'com.atproto.repo.uploadBlob', [], $body, $type);
                Log::info('Retry Image: ' . json_encode($response));
            }

            $images[] = [
                'alt' => $media['text'] ?? '',
                'image' => $response->blob,
            ];
        }

        if (!empty($images)) {
            $args['record']['embed'] = [
                '$type' => 'app.bsky.embed.images',
                'images' => $images,
            ];
        }

        return $args;
    }

    /**
     * @param string $text
     * @param array $media
     *
     * @return mixed|object
     * @throws JsonException
     */
    public function post(string $text, array $media = [], mixed $reply = null): mixed
    {
        $posts = $this->splitPost($text, $media);
        if (!empty($posts)) {
            $results = [];
            $rootResult = null;
            foreach ($posts as $post) {
                if (!empty($result)) {
                    $reply = [
                        'root' => $rootResult,
                        'parent' => $result,
                    ];
                }

                $result = $this->post($post['text'], $post['media'], $reply);
                if (empty($rootResult)) {
                    $rootResult = $result;
                }
                $results[] = $result;
            }
            return $results;
        }

        Log::info('posting: ' . $text);

        $args = [
            'collection' => 'app.bsky.feed.post',
            'repo' => $this->connection->did,
            'record' => [
                'text' => $text ?? '',
                'langs' => ['uk-UA'], // TODO: autodetect or retrieve language from the request
                'createdAt' => date('c'),
                '$type' => 'app.bsky.feed.post',
            ],
        ];

        $urls = self::parseUrl($args['record']['text']);

        // TODO: move into separate function
        foreach ($urls as $index => $url) {
            $newUrl = preg_replace("(^https?://)", "", $url['url']);
            if (strlen($newUrl) > $this->getMaxLinkLength()) {
                $newUrl = substr($newUrl, 0, $this->getMaxLinkLength() - 3) . '...';
            }

            if (strlen($newUrl) != strlen($url['url'])) {
                $diff = strlen($url['url']) - strlen($newUrl);
                $urls[$index]['end'] -= $diff;
                $args['record']['text'] = str_replace($url['url'], $newUrl, $args['record']['text']);

                for ($i = $index + 1; $i < count($urls); $i++) {
                    $urls[$i]['start'] -= $diff;
                    $urls[$i]['end'] -= $diff;
                }
            }
        }

        $args = $this->addUrls($args, $urls);
        $args = $this->addImages($args, $media);

        if (!empty($reply)) {
            $args['record']['reply'] = $reply;
        }

        return $this->request('POST', 'com.atproto.repo.createRecord', $args);
    }
}
