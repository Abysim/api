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

class Bluesky
{
    /**
     * @var BlueskyConnection
     */
    protected BlueskyConnection $connection;

    /**
     * @var BlueskyApi
     */
    protected BlueskyApi $api;

    /**
     * @param BlueskyConnection $connection
     */
    public function __construct(BlueskyConnection $connection)
    {
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

        // https://atproto.com/blog/create-post#mentions-and-links
        $regex = '^\b(https?://(www\.)?[-a-zA-Z0-9@:%._+~#=]{1,256}\.[a-zA-Z0-9()]{1,6}\b([-a-zA-Z0-9()@:%_+.~#?&//=]*[-a-zA-Z0-9@%_+~#//=])?)^u';
        preg_match_all($regex, $text, $matches, PREG_OFFSET_CAPTURE);

        foreach($matches[0] as $match) {
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
    private function addImages(array $args, ?string $imageUrl = null): array
    {
        if (empty($imageUrl)) {
            return $args;
        }

        $body = file_get_contents($imageUrl);

        $fh = fopen('php://memory', 'w+b');
        fwrite($fh, $body);
        $type = mime_content_type($fh);
        fclose($fh);

        Log::info('Image Type: ' . $type);
        if (!in_array($type, ['image/png', 'image/jpg', 'image/jpeg'])) {
            return $args;
        }

        $response = $this->request('POST', 'com.atproto.repo.uploadBlob', [], $body, $type);
        Log::info('Image: ' . json_encode($response));
        // retry if uploading failed
        if (!isset($response->blob)) {
            $response = $this->request('POST', 'com.atproto.repo.uploadBlob', [], $body, $type);
            Log::info('Retry Image: ' . json_encode($response));
        }

        $args['record']['embed'] = [
            '$type' => 'app.bsky.embed.images',
            'images' => [
                [
                    'alt' => '',
                    'image' => $response->blob,
                ],
            ],
        ];

        return $args;
    }

    /**
     * @param Request $request
     *
     * @return mixed|object
     * @throws JsonException
     */
    public function post(Request $request): mixed
    {
        $args = [
            'collection' => 'app.bsky.feed.post',
            'repo' => $this->connection->did,
            'record' => [
                'text' => $request->text ?? '',
                'langs' => ['uk-UA'], // TODO: autodetect or retrieve language from the request
                'createdAt' => date('c'),
                '$type' => 'app.bsky.feed.post',
            ],
        ];

        $urls = self::parseUrl($args['record']['text']);

        // TODO: move into separate function
        foreach ($urls as $index => $url) {
            $newUrl = preg_replace("(^https?://)", "", $url['url']);
            if (strlen($newUrl) > 23) {
                $newUrl = substr($newUrl, 0, 20) . '...';
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

        $args = $this->addImages($args, $request->image ?? null);

        return $this->request('POST', 'com.atproto.repo.createRecord', $args);
    }
}
