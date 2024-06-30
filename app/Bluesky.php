<?php
/**
 * @package App
 * @author Abysim <abysim@whitelion.me>
 */

namespace App;

use App\Models\BlueskyConnection;
use Aws\Comprehend\ComprehendClient;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use Exception;
use STS\JWT\ParsedToken;

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
     * @var string
     */
    private string $apiUri;

    /**
     * @param int|BlueskyConnection $connection
     * @param string $apiUri
     *
     * @throws Exception
     */
    public function __construct(int|BlueskyConnection $connection, string $apiUri = 'https://bsky.social/xrpc/')
    {
        $this->apiUri = $apiUri;

        if (is_numeric($connection)) {
            $connection = BlueskyConnection::query()->find($connection);

            if (empty($connection)) {
                throw new Exception('Connection not found');
            }
        }

        $this->connection = $connection;
        if (!$connection->did || !$connection->jwt) {
            $args = ['handle' => $connection->handle];
            $data = $this->apiRequest('GET', 'com.atproto.identity.resolveHandle', $args);
            $connection->did = $data->did;

            $this->createSession();
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
     * @param array $tags
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
     * @param object $data
     *
     * @return void
     */
    private function updateSession(object $data): void
    {
        if (isset($data->error)) {
            Log::error($data->error . ': '  . $data->message);

            return;
        }

        $this->connection->jwt = $data->accessJwt;
        $this->connection->refresh = $data->refreshJwt;
        $this->connection->handle = $data->handle;
        $this->connection->save();
    }

    /**
     * @throws Exception
     */
    private function createSession(): void
    {
        $keyArgs = [
            'identifier' => $this->connection->did,
            'password' => $this->connection->password,
        ];
        $data = $this->apiRequest('POST', 'com.atproto.server.createSession', $keyArgs);
        $this->updateSession($data);
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
     * @return object|null
     * @throws Exception
     */
    public function request(
        string $type,
        string $request,
        array $args = [],
        ?string $body = null,
        ?string $contentType = null
    ): ?object {
        if (
            !empty($this->connection->refresh)
            && ParsedToken::fromString($this->connection->jwt)->isExpired()
            && !ParsedToken::fromString($this->connection->refresh)->isExpired()
        ) {
            $this->connection->jwt = $this->connection->refresh;
            $data = $this->apiRequest('POST', 'com.atproto.server.refreshSession');
            $this->updateSession($data);
        }

        $response = $this->apiRequest($type, $request, $args, $body, $contentType);

        if (isset($response->error) && in_array($response->error, ['InvalidToken', 'ExpiredToken'])) {
            Log::warning($this->connection->updated_at . ': ' . $response->error . ': '  . $response->message);
            $this->createSession();
            $response = $this->apiRequest($type, $request, $args, $body, $contentType);
        }

        return $response;
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
     */
    private function addImages(array $args, ?array $media = []): array
    {
        $images = [];
        foreach ($media as $item) {
            $image = $item['path'] ?? $item['url'] ?? null;
            if (empty($image)) {
                continue;
            }

            try {
                $response = $this->uploadImage($image);
                $images[] = [
                    'alt' => $item['text'] ?? '',
                    'image' => $response->blob,
                ];
            } catch (Exception $e) {
                Log::error($e->getMessage());
            }
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
     * @param string $image
     *
     * @return object|null
     * @throws Exception
     */
    private function uploadImage(string $image): ?object
    {
        $body = '';
        for ($i = 0; $i <= 4; $i++) {
            try {
                $body = $this->getUrl($image, true);
                break;
            } catch (Exception $e) {
                if ($i == 4) {
                    continue;
                }

                Log::info('Image reading problem: ' . $e->getMessage());
                sleep($i * $i);
            }
        }

        if (File::isFile($image)) {
            $type = File::mimeType($image);
        } else {
            $fh = fopen('php://memory', 'w+b');
            fwrite($fh, $body);
            $type = mime_content_type($fh);
            fclose($fh);
        }

        Log::info('Image Type: ' . $type);
        if (!in_array($type, ['image/png', 'image/jpg', 'image/jpeg', 'image/gif', 'image/webp', 'image/heic', 'image/heif'])) {
            Log::info('Unsupported image type!');

            throw new Exception('Unsupported image type!');
        }

        $response = $this->request('POST', 'com.atproto.repo.uploadBlob', [], $body, $type);
        Log::info('Image: ' . json_encode($response));
        // retry if uploading failed
        if (!isset($response->blob)) {
            $response = $this->request('POST', 'com.atproto.repo.uploadBlob', [], $body, $type);
            Log::info('Retry Image: ' . json_encode($response));
        }

        if (!isset($response->blob)) {
            throw new Exception($response);
        }

        return $response;
    }

    /**
     * @param string $url
     * @param bool $isBinary
     *
     * @return string
     * @throws Exception
     */
    private function getUrl(string $url, bool $isBinary = false): string
    {
        if (File::isFile($url)) {
            return File::get($url);
        }

        try {
            $res = Http::timeout(4)->get($url);
            if ($res->status() >= 400) {
                throw new Exception($res->body());
            }
        } catch (Exception $e) {
            Log::warning('Failed get URL without scraper: ' . $e->getMessage());

            $params = [
                'api_key' => config('scraper.key'),
                'url' => $url,
                'country_code' => 'eu',
                'device_type' => 'desktop',
            ];
            if ($isBinary) {
                $params['binary_target'] = true;
            }

            $res = Http::timeout(8)->get(config('scraper.url'), $params);
            Log::info('Response code: ' . $res->status());
            if ($res->status() >= 400) {
                throw new Exception($res->body());
            }
        }

        return $res->body();
    }

    /**
     * @param string $text
     * @param array $media
     * @param mixed|null $reply
     *
     * @return array|object|null
     * @throws Exception
     */
    public function post(string $text, array $media = [], mixed $reply = null): array|null|object
    {
        if (!Str::contains($text, '#фільм', true) && !empty($media)) {
            $text = Str::replaceFirst(' фільм', ' #фільм', $text);
        }

        if (!Str::contains($text, '#подкаст', true)) {
            $text = Str::replaceFirst('https://www.podcastics.com', '#подкаст https://www.podcastics.com', $text);
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
        $lang = static::detectLanguage($text);

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

                                $content = $this->getUrl($url);
                                Log::info('Content length: ' . strlen($content));

                                $dom = new DOMDocument();
                                libxml_use_internal_errors(true);
                                $dom->loadHTML($content);
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
                                        if (!empty($node->textContent)) {
                                            $card['title'] = $node->textContent;
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
                                if (empty($imageUrl)) {
                                    $query = '//img/@src';
                                    foreach ($xpath->query($query) as $node) {
                                        if (!empty($node->value)) {
                                            $imageUrl = $node->value;
                                            break;
                                        }
                                    }
                                }

                                if (!empty($imageUrl)) {
                                    if (
                                        Str::substr($imageUrl, 0, 2) != '//'
                                        && !str_contains($imageUrl, '://')
                                    ) {
                                        $imageUrl = $url . $imageUrl;
                                    }

                                    try {
                                        $response = $this->uploadImage($imageUrl);
                                        $card['thumb'] = $response->blob;
                                    } catch (Exception $e) {
                                        Log::error($e->getMessage());
                                    }
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

    /**
     * @param string $type
     * @param string $request
     * @param array $args
     * @param string|null $body
     * @param string|null $contentType
     *
     * @return object|null
     * @throws Exception
     */
    private function apiRequest(
        string $type,
        string $request,
        array $args = [],
        ?string $body = null,
        ?string $contentType = null,
    ): ?object {
        $url = $this->apiUri . $request;

        $request = Http::asJson();
        if ($this->connection->jwt) {
            $request = $request->withToken($this->connection->jwt);
        }
        if (count($args)) {
            if ($type == 'GET') {
                $args = ['query' => $args];
            } else {
                $args = ['json' => $args];
            }
        }
        if ($body) {
            $request = $request->withBody($body, $contentType);
        }

        return $request->send($type, $url, $args)->object();
    }
}
