<?php
/**
 * @package App
 * @author Abysim <abysim@whitelion.me>
 */

namespace App;

use App\Models\FediverseConnection;
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
    public function post(string $text, array $media = [], mixed $reply = null, ?string $cat = null): mixed
    {
        $data = [];
        if (!empty($text)) {
            $data['status'] = $text;
            $data['text'] = $text;
            $data['language'] = static::detectLanguage($text);
        }

        if (!empty($this->connection->cat)) {
            $data['friendica'] = ['category' => $this->connection->cat];
        }

        $mediaIds = [];
        foreach ($media as $item) {
            $result = $this->request('v2/media', ['description' => $item['text'] ?? ''], $item['path']);
            Log::info('Image uploaded: ' . json_encode($result, JSON_UNESCAPED_UNICODE));

            $mediaIds[] = $result['id'];
        }

        if (!empty($mediaIds)) {
            $data['media_ids'] = $mediaIds;
        }

        return $this->request('v1/statuses', $data);
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

        return $request->post($url, $args)->json();
    }
}
