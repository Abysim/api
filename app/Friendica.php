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

        $count = 0;
        foreach ($media as $item) {
            if (empty($item['url'])) {
                continue;
            }

            if ($count == 0) {
                $data['msg'] .= "\n\n";
            }

            if (!empty($item['text'])) {
                $data['msg'] .= '[img=' . $item['url'] . ']' . $item['text'] . '[/img]';
            } else {
                $data['msg'] .= '[img]' . $item['url'] . '[/img]';
            }

            $count++;
        }

        return Http::asForm()->post(config('friendica.url'), $data);
    }
}
