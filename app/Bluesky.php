<?php
/**
 * @package App
 * @author Abysim <abysim@whitelion.me>
 */

namespace App;

use App\Helpers\FileHelper;
use App\Models\BlueskyConnection;
use App\Models\Post;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;
use Exception;
use STS\JWT\ParsedToken;

class Bluesky extends Social
{
    const MAX_TEXT_LENGTH = 300;
    const MAX_MEDIA_COUNT = 4;
    const MAX_LINK_LENGTH = 24;
    const MAX_IMAGE_SIZE = 1000000;

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
            $args = $this->addEmbed($args, [
                '$type' => 'app.bsky.embed.images',
                'images' => $images,
            ]);
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
                $body = FileHelper::getUrl($image, true);
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
            $type = FileHelper::getMimeType($body);
        }

        Log::info('Image Type: ' . $type);
        if (!in_array($type, ['image/png', 'image/jpg', 'image/jpeg', 'image/gif', 'image/webp', 'image/heic', 'image/heif'])) {
            Log::info('Unsupported image type!');

            throw new Exception('Unsupported image type!');
        }

        if (strlen($body) >= static::MAX_IMAGE_SIZE) {
            $body = (string) Image::read($body)->encodeByMediaType(quality: 80);
        }
        if (strlen($body) >= static::MAX_IMAGE_SIZE && !in_array($type, ['image/jpeg', 'image/jpg'])) {
            $body = (string) Image::read($body)->toJpeg(80);
            $type = 'image/jpeg';
        }
        if (strlen($body) >= static::MAX_IMAGE_SIZE) {
            $body = (string) Image::read($body)->scaleDown(2000, 2000)->toJpeg(80);
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
     * @inheritDoc
     */
    public function post(
        array $textData = [],
        array $media = [],
        mixed $reply = null,
        mixed $root = null,
        mixed $quote = null
    ): array|null|object {
        $text = $textData['text'];

        if (!Str::contains($text, '#фільм', true) && !empty($media)) {
            $text = Str::replaceFirst(' фільм', ' #фільм', $text);
        }

        if (!Str::contains($text, '#подкаст', true)) {
            $text = Str::replaceFirst('https://www.podcastics.com', '#подкаст https://www.podcastics.com', $text);
        }

        $posts = $this->splitPost($text, $media);
        if (!empty($posts)) {
            $results = [];

            foreach ($posts as $post) {
                if (!empty($result)) {
                    $reply = $result;
                }

                $textData['text'] = $post['text'];
                $result = $this->post($textData, $post['media'], $reply, $root, $quote);
                sleep(1);
                if (empty($root)) {
                    $root = $result;
                }
                if (!empty($quote)) {
                    $quote = null;
                }
                $results[] = $result;
            }
            return $results;
        }

        Log::info('posting: ' . $text);

        $text = $text ?? '';
        $lang = $textData['language'] ?? static::detectLanguage($text);

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
                $newUrl = substr($newUrl, 0, $this->getMaxLinkLength() - 1) . '…';
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

        if (!empty($quote)) {
            $args['record']['embed'] = [
                '$type' => 'app.bsky.embed.record',
                'record' => $quote,
            ];
        }
        $args = $this->addUrls($args, $urls);
        $args = $this->addImages($args, $media);

        if (
            (empty($args['record']['embed']) || $args['record']['embed']['$type'] == 'app.bsky.embed.record')
            && !empty($args['record']['facets'])
        ) {
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

                                try {
                                    $data = Http::get('https://cardyb.bsky.app/v1/extract', ['url' => $url])->json();
                                    // get text after ?url= using string function
                                    if (!empty($data['image'])) {
                                        $data['image'] = Str::after($data['image'], '?url=');
                                    }
                                } catch (Exception $e) {
                                    Log::error('Failed to get card data: ' . $e->getMessage());
                                }
                                if (empty($data['title']) || empty($data['description']) || empty($data['image'])) {
                                    [$title, $description, $imageUrl] = $this->parseCard($url);
                                }
                                $card['title'] = html_entity_decode(($data['title'] ?? '') ?: ($title ?? ''));
                                $card['description'] = html_entity_decode(($data['description'] ?? '') ?: ($description ?? ''));
                                $imageUrl = urldecode(($data['image'] ?? '') ?: ($imageUrl ?? ''));

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
                                        if (isset($media[0]['thumb'])) {
                                            try {
                                                $response = $this->uploadImage($media[0]['thumb']);
                                                $card['thumb'] = $response->blob;
                                            } catch (Exception $e) {
                                                Log::error($media[0]['thumb'] . ': ' . $e->getMessage());
                                            }
                                        }

                                        Log::error($imageUrl . ': ' . $e->getMessage());
                                    }
                                }

                                $args = $this->addEmbed($args, [
                                    '$type' => 'app.bsky.embed.external',
                                    'external' => $card,
                                ]);

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

         //make links longer when possible
        if (!empty($args['record']['facets']) && Str::length($args['record']['text']) < $this->getMaxTextLength()) {
            $linkCount = count($args['record']['facets']);
            $availableChars = $this->getMaxTextLength() - Str::length($args['record']['text']);
            $availableCharsPerLink = (int) ($availableChars / $linkCount);

            if ($availableCharsPerLink > 0) {
                foreach ($args['record']['facets'] as $index => $facet) {
                    // at this step, only links should be present in facets, but let's be sure
                    if (
                        empty($facet['features'][0]['$type'])
                        || $facet['features'][0]['$type'] != 'app.bsky.richtext.facet#link'
                    ) {
                        continue;
                    }

                    $newUrl = preg_replace("(^https?://)", "", $facet['features'][0]['uri']);
                    $linkVisibleLengthBytes = $args['record']['facets'][$index]['index']['byteEnd']
                        - $args['record']['facets'][$index]['index']['byteStart'];
                    $linkVisibleLength = Str::length(substr(
                        $args['record']['text'],
                        $args['record']['facets'][$index]['index']['byteStart'],
                        $linkVisibleLengthBytes
                    ));
                    $neededCharsForLink = Str::length($newUrl) - $linkVisibleLength;

                    if ($neededCharsForLink > 0) {
                        $addedChars = min($neededCharsForLink, $availableCharsPerLink);

                        if (Str::length($newUrl) > $linkVisibleLength + $addedChars) {
                            $newUrl = substr($newUrl, 0, $linkVisibleLength + $addedChars - 1) . '…';
                        }

                        $args['record']['text'] = substr_replace(
                            $args['record']['text'],
                            $newUrl,
                            $args['record']['facets'][$index]['index']['byteStart'],
                            $linkVisibleLengthBytes
                        );
                        $addedLength = strlen($newUrl) - $linkVisibleLengthBytes;

                        $args['record']['facets'][$index]['index']['byteEnd'] += $addedLength;
                        foreach ($args['record']['facets'] as $i => $f) {
                            if ($i <= $index) {
                                continue;
                            }
                            $args['record']['facets'][$i]['index']['byteStart'] += $addedLength;
                            $args['record']['facets'][$i]['index']['byteEnd'] += $addedLength;
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
            $args['record']['reply'] =  [
                'root' => $root,
                'parent' => $reply,
            ];
        }

        Log::info('text len: '. Str::length($args['record']['text']));
        $result = $this->request('POST', 'com.atproto.repo.createRecord', $args);

        if (!empty($result) && empty($result->error)) {
            $postId = json_encode([
                'uri' => $result->uri,
                'cid' => $result->cid,
            ]);
            if (is_object($reply)) {
                $reply = [
                    'uri' => $reply->uri,
                    'cid' => $reply->cid,
                ];
            }

            /** @var Post $post */
            $post = Post::query()->updateOrCreate([
                'connection' => 'bluesky',
                'connection_id' => $this->connection->id,
                'post_id' => $postId,
                'parent_post_id' => $reply ? json_encode($reply) : $postId,
                'root_post_id' => $root ? json_encode($root) : $postId,
            ]);
            static::createPostForward($post->id, $textData['post_id'] ?? 0, array_column($media, 'post_id'));
        }

        return $result;
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

    /**
     * @param array $args
     * @param array $embed
     *
     * @return array
     */
    private function addEmbed(array $args, array $embed): array
    {
        if (empty($args['record']['embed'])) {
            $args['record']['embed'] = $embed;
        } else {
            $args['record']['embed']['record'] = $args['record']['embed'];
            $args['record']['embed']['$type'] = 'app.bsky.embed.recordWithMedia';
            $args['record']['embed']['media'] = $embed;
        }

        return $args;
    }

    /**
     * @throws Exception
     */
    private function parseCard($url): array
    {
        $title = null;
        $description = null;
        $imageUrl = null;

        $content = '';
        for ($i = 0; $i <= 4; $i++) {
            try {
                $content = FileHelper::getUrl($url);
                break;
            } catch (Exception $e) {
                if ($i == 4) {
                    throw $e;
                }
            }
        }
        Log::info('Content length: ' . strlen($content));

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($content);
        $xpath = new DOMXPath($dom);
        $query = '//meta[@property="og:title"]/@content';
        foreach ($xpath->query($query) as $node) {
            if (!empty($node->value)) {
                $title = $node->value;
                break;
            }
        }
        if (empty($title)) {
            $query = '//title';
            foreach ($xpath->query($query) as $node) {
                if (!empty($node->textContent)) {
                    $card['title'] = $node->textContent;
                    break;
                }
            }
        }
        if (empty($title)) {
            $query = '//meta[@name="twitter:title"]/@content';
            foreach ($xpath->query($query) as $node) {
                if (!empty($node->value)) {
                    $title = $node->value;
                    break;
                }
            }
        }

        $query = '//meta[@property="og:description"]/@content';
        foreach ($xpath->query($query) as $node) {
            if (!empty($node->value)) {
                $description = $node->value;
                break;
            }
        }
        if (empty($description)) {
            $query = '//meta[@name="description"]/@content';
            foreach ($xpath->query($query) as $node) {
                if (!empty($node->value)) {
                    $description = $node->value;
                    break;
                }
            }
        }
        if (empty($description)) {
            $query = '//meta[@name="twitter:description"]/@content';
            foreach ($xpath->query($query) as $node) {
                if (!empty($node->value)) {
                    $description = $node->value;
                    break;
                }
            }
        }

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

        return [$title, $description, $imageUrl];
    }
}
