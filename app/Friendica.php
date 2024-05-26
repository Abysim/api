<?php
/**
 * @package App
 * @author Abysim <abysim@whitelion.me>
 */

namespace App;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class Friendica extends Social
{

    /**
     * @inheritDoc
     */
    public function post(string $text, array $media = []): mixed
    {
        $data = [
            'key' => config('friendica.key'),
            'type' => empty($media[0]['url']) ? 'status' : 'photo',
            'msg' => $text,
            'date' =>  Carbon::now()->toISOString(),
        ];

        if (!empty($media[0]['url'])) {
            $data['image'] = $media[0]['url'];
        }

        return Http::asForm()->post(config('friendica.url'), $data);
    }
}
