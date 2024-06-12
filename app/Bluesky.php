<?php
/**
 * @package App
 * @author Abysim <abysim@whitelion.me>
 */

namespace App;

use App\Models\BlueskyConnection;
use Aws\Comprehend\ComprehendClient;
use cjrasmussen\BlueskyApi\BlueskyApi;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
     * @param array $args
     * @param array $urls
     *
     * @return array
     */
    private function addTags(array $args, array $tags): array
    {
        if (empty($tags)) {
            return $args;
        }

        if (!isset($args['record']['facets'])) {
            $args['record']['facets'] = [];
        }

        foreach ($tags as $tag) {
            $args['record']['facets'][] = [
                'index' => [
                    'byteStart' => $tag['start'],
                    'byteEnd' => $tag['end'],
                ],
                'features' => [
                    [
                        '$type' => 'app.bsky.richtext.facet#tag',
                        'tag' => $tag['tag'],
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
     * @param string $text
     *
     * @return array
     */
    public static function parseTags(string $text): array
    {
        $spans = [];

        $regex = '/(?:^|\s)(#[^\d\s]\S*)(?=\s)?/u';
        preg_match_all($regex, $text, $matches, PREG_OFFSET_CAPTURE);

        foreach($matches[0] as $match) {
            $tag = $match[0];
            $hasLeadingSpace = preg_match('/^\s/', $tag);
            $tag = preg_replace('/\p{P}+$/u', '', trim($tag));

            if(Str::length($tag) > 66) {
                continue;
            }
            $index = $match[1] + ($hasLeadingSpace ? 1 : 0);

            $spans[] = [
                'start' => $index,
                'end' => $index + strlen($tag),
                'tag' =>  preg_replace('/^#/', '', $tag),
            ];
        }

        return $spans;
    }

    /**
     * @param array $args
     * @param array|null $media
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
        if (!Str::contains($text, '#фільм', true)) {
            $text = Str::replaceFirst('фільм', '#фільм', $text);
        }

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
                sleep(1);
                if (empty($rootResult)) {
                    $rootResult = $result;
                }
                $results[] = $result;
            }
            return $results;
        }

        Log::info('posting: ' . $text);

        $text = $text ?? '';
        $lang = 'uk';
        try {
            $comprehend = new ComprehendClient([
                'region' => config('comprehend.region'),
                'version' => 'latest',
                'credentials' => [
                    'key' => config('comprehend.key'),
                    'secret' => config('comprehend.secret'),
                ]
            ]);
            $languages = $comprehend->detectDominantLanguage(['Text' => $text])->get('Languages');
            $lang = $languages[0]['LanguageCode'] ?? 'uk';
            $score = $languages[0]['Score'] ?? 0;
            if ($lang == 'ru' || $score < 0.7) {
                Log::warning('strange lang detected: ' . $text);
                $lang = 'uk';
            }
        } catch (Exception $e) {
            Log::error($e->getMessage());
        }

        $args = [
            'collection' => 'app.bsky.feed.post',
            'repo' => $this->connection->did,
            'record' => [
                'text' => $text,
                'langs' => [$lang],
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

        if (empty($args['record']['embed']) && !empty($args['record']['facets'])) {
            foreach ($args['record']['facets'] as $index => $facet) {
                if (
                    $facet['index']['byteEnd'] == strlen($args['record']['text'])
                    || $index == count($args['record']['facets']) - 1
                ) {
                    foreach ($facet['features'] as $feature) {
                        if ($feature['$type'] == 'app.bsky.richtext.facet#link') {
                            try {
                                $url = $feature['uri'];
                                $card = [
                                    'uri' => $url,
                                    'title' => '',
                                    'description' => '',
                                ];
                                $dom = new DOMDocument();
                                libxml_use_internal_errors(true);
                                $dom->loadHTMLFile($url);
                                $xpath = new DOMXPath($dom);
                                $query = '//meta[@property="og:title"]/@content';
                                foreach ($xpath->query($query) as $node) {
                                    if (!empty($node->value)) {
                                        $card['title'] = $node->value;
                                        break;
                                    }
                                }
                                if (empty($card['title'])) {
                                    $query = '//title';
                                    foreach ($xpath->query($query) as $node) {
                                        if (!empty($node->value)) {
                                            $card['title'] = $node->value;
                                            break;
                                        }
                                    }
                                }
                                if (empty($card['title'])) {
                                    $query = '//meta[@name="twitter:title"]/@content';
                                    foreach ($xpath->query($query) as $node) {
                                        if (!empty($node->value)) {
                                            $card['title'] = $node->value;
                                            break;
                                        }
                                    }
                                }
                                $query = '//meta[@property="og:description"]/@content';
                                foreach ($xpath->query($query) as $node) {
                                    if (!empty($node->value)) {
                                        $card['description'] = $node->value;
                                        break;
                                    }
                                }
                                if (empty($card['description'])) {
                                    $query = '//meta[@name="description"]/@content';
                                    foreach ($xpath->query($query) as $node) {
                                        if (!empty($node->value)) {
                                            $card['description'] = $node->value;
                                            break;
                                        }
                                    }
                                }
                                if (empty($card['description'])) {
                                    $query = '//meta[@name="twitter:description"]/@content';
                                    foreach ($xpath->query($query) as $node) {
                                        if (!empty($node->value)) {
                                            $card['description'] = $node->value;
                                            break;
                                        }
                                    }
                                }
                                $imageUrl = null;
                                $query = '//meta[@property="og:image"]/@content';
                                foreach ($xpath->query($query) as $node) {
                                    if (!empty($node->value)) {
                                        $imageUrl = $node->value;
                                        break;
                                    }
                                }
                                if (empty($imageUrl)) {
                                    $query = '//meta[@name="twitter:image"]/@content';
                                    foreach ($xpath->query($query) as $node) {
                                        if (!empty($node->value)) {
                                            $imageUrl = $node->value;
                                            break;
                                        }
                                    }
                                }

                                if (!empty($imageUrl)) {
                                    if (!str_contains($imageUrl, '://')) {
                                        $imageUrl = $url . $imageUrl;
                                    }

                                    $body = '';
                                    for ($i = 0; $i < 5; $i++) {
                                        try {
                                            $body = file_get_contents($imageUrl);
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

                                    $card['thumb'] = $response->blob;
                                }

                                $args['record']['embed'] = [
                                    '$type' => 'app.bsky.embed.external',
                                    'external' => $card,
                                ];

                                if ($facet['index']['byteEnd'] == strlen($args['record']['text'])) {
                                    $args['record']['text'] = trim(substr(
                                        $args['record']['text'],
                                        0,
                                        $facet['index']['byteStart']
                                    ));
                                    unset($args['record']['facets'][$index]);
                                }
                            } catch (Exception $e) {
                                Log::warning('Failed to parse ' . $url . ': ' . $e->getMessage());
                            } finally {
                                libxml_use_internal_errors(false);
                            }
                            break;
                        }
                    }
                }
            }
        }

        $tags = static::parseTags($args['record']['text']);
        $args = $this->addTags($args, $tags);

        if (isset($args['record']['facets'])) {
            $args['record']['facets'] = array_values($args['record']['facets']);
        }

        if (!empty($reply)) {
            $args['record']['reply'] = $reply;
        }

        return $this->request('POST', 'com.atproto.repo.createRecord', $args);
    }
}
