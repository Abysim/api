<?php
/**
 * @package App
 * @author Abysim <abysim@whitelion.me>
 */

namespace App;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use kamermans\OAuth2\Signer\AccessToken\SignerInterface;

abstract class Social
{
    const MAX_TEXT_LENGTH = 20000;
    const MAX_MEDIA_COUNT = 20;
    const MAX_LINK_LENGTH = 256;

    /**
     * @param string $text
     * @param array $media
     * @param mixed|null $reply
     *
     * @return mixed
     */
    abstract public function post(string $text, array $media = [], mixed $reply = null): mixed;

    protected function getMaxTextLength(): int
    {
        return static::MAX_TEXT_LENGTH;
    }

    protected function getMaxMediaCount(): int
    {
        return static::MAX_MEDIA_COUNT;
    }

    protected function getMaxLinkLength(): int
    {
        return static::MAX_LINK_LENGTH;
    }

    protected static function getParsedUrls(string $text): array
    {
        // https://atproto.com/blog/create-post#mentions-and-links
        $regex = '^\b(https?://(www\.)?[-a-zA-Z0-9@:%._+~#=]{1,256}\.[a-zA-Z0-9()]{1,6}\b([-a-zA-Z0-9()@:%_+.~#?&//=]*[-a-zA-Z0-9@%_+~#//=])?)^u';
        preg_match_all($regex, $text, $matches, PREG_OFFSET_CAPTURE);
        return $matches[0] ?? [];
    }

    protected function getLinkPlaceholder(): string
    {
        return str_repeat('.', $this->getMaxLinkLength());
    }

    protected function splitPost(string $text, array $media = []): array
    {
        Log::info('splitting');
        $linkPlaceholder = $this->getLinkPlaceholder();
        if ($this->getMaxLinkLength() != self::MAX_TEXT_LENGTH) {
            $matches = static::getParsedUrls($text);

            foreach ($matches as $match) {
                $text = Str::replaceFirst($match[0], $linkPlaceholder, $text);
            }
        }

        if (Str::length($text) >= $this->getMaxTextLength() || count($media) > $this->getMaxMediaCount()) {
            Log::info('real splitting');
            $posts = [];

            $count = 0;
            while (Str::length($text) > $this->getMaxTextLength()) {
                for ($i = $this->getMaxTextLength() - 2; $i > 0; $i--) {

                    if (
                        Str::charAt($text, $i) == '.'
                        && Str::charAt($text, $i - 1) != '.'
                        && trim(Str::charAt($text, $i + 1)) == ''
                    ) {
                        break;
                    }
                }

                if ($i == 0) {
                    for ($i = $this->getMaxTextLength() - 2; $i > 0; $i--) {
                        if (Str::charAt($text, $i) == ',' && rim(Str::charAt($text, $i + 1)) == '') {
                            break;
                        }
                    }
                }

                if ($i == 0) {
                    $i = $this->getMaxTextLength() - 2;
                }

                $posts[$count] = [
                    'text' => trim(Str::substr($text, 0, $i + 1)),
                    'media' => [],
                ];

                $text = trim(Str::substr($text, $i + 1));
                $count++;
            }

            $posts[$count] = [
                'text' => trim($text),
                'media' => [],
            ];

            $count = 0;
            while (count($media) > $this->getMaxMediaCount()) {
                $posts[$count] = [
                    'text' => $posts[$count]['text'] ?? '',
                    'media' => array_splice($media, 0,  $this->getMaxMediaCount()),
                ];
                $count++;
            }
            $posts[$count] = [
                'text' => $posts[$count]['text'] ?? '',
                'media' => $media,
            ];

            foreach ($posts as &$post) {
                $post['text'] = $post['text'] ?? '';
                $post['media'] = $post['media'] ?? [];
            }

            if (!empty($matches)) {
                foreach ($matches as $match) {
                    foreach ($posts as &$post) {
                        $position = Str::position($post['text'], $linkPlaceholder);
                        if ($position !== false) {
                            $post['text'] = Str::replaceFirst($linkPlaceholder, $match[0], $post['text']);

                            break;
                        }
                    }
                }
            }

            return $posts;
        }

        return [];
    }
}
