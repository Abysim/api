<?php
/**
 * @package App
 * @author Abysim <abysim@whitelion.me>
 */

namespace App;

use App\Models\PostForward;
use Aws\Comprehend\ComprehendClient;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SteppingHat\EmojiDetector\EmojiDetector;

abstract class Social
{
    const MAX_TEXT_LENGTH = 20000;
    const MAX_MEDIA_COUNT = 20;
    const MAX_LINK_LENGTH = 256;

    /**
     * @param array $textData
     * @param array $media
     * @param mixed|null $reply
     * @param mixed|null $root
     * @param mixed|null $quote
     *
     * @return mixed
     */
    abstract public function post(
        array $textData = [],
        array $media = [],
        mixed $reply = null,
        mixed $root = null,
        mixed $quote = null
    ): mixed;

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
        $regex = '^\b(https?://(www\.)?[-a-zA-Z0-9@:%._+~#=]{1,256}\.[a-zA-Z0-9()]{1,6}\b([-a-zA-Z0-9()@:%_+.~#?&//=!]*[-a-zA-Z0-9()@%_+~#//=!])?)^u';
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

        if (Str::length($text) > $this->getMaxTextLength() || count($media) > $this->getMaxMediaCount()) {
            $detector = new EmojiDetector();
            Log::info('real splitting');
            $posts = [];

            $count = 0;
            while (Str::length($text) > $this->getMaxTextLength()) {
                for ($i = $this->getMaxTextLength() - 1; $i > 0; $i--) {
                    $isNextCapital = false;
                    if (trim(Str::charAt($text, $i + 1)) == '') {
                        for ($j = $i + 2; $j < Str::length($text); $j++) {
                            if (trim(Str::charAt($text, $j)) == '' || $detector->isEmojiString(Str::charAt($text, $j))) {
                                continue;
                            } elseif (Str::charAt($text, $j) != Str::lower(Str::charAt($text, $j))) {
                                $isNextCapital = true;
                            }

                            break;
                        }
                    }

                    if (
                        Str::charAt($text, $i + 1) == "\n"
                        || (
                            in_array(Str::charAt($text, $i), ['.', '!', '?', '…', '…'])
                            && trim(Str::charAt($text, $i + 1)) == ''
                        )
                        || (
                            (
                                in_array(Str::charAt($text, $i), ['(', ')', '[', ']', '<', '>', '{', '}'])
                                || $detector->isEmojiString(Str::charAt($text, $i))
                            )
                            && trim(Str::charAt($text, $i + 1)) == ''
                            && $isNextCapital
                        )
                    ) {
                        break;
                    }
                }

                if ($i == 0) {
                    for ($i = $this->getMaxTextLength() - 1; $i > 0; $i--) {
                        if (
                            (
                                in_array(
                                    Str::charAt($text, $i),
                                    ['(', ')', '[', ']', '<', '>', '{', '}', ',', ':', ';', '-', '‐', '‒', '–', '—', '―']
                                )
                                || $detector->isEmojiString(Str::charAt($text, $i))
                            )
                            && trim(Str::charAt($text, $i + 1)) == ''
                        ) {
                            break;
                        }
                    }
                }

                if ($i == 0) {
                    for ($i = $this->getMaxTextLength() - 1; $i > 0; $i--) {
                        if (trim(Str::charAt($text, $i + 1)) == '') {
                            break;
                        }
                    }
                }

                if ($i == 0) {
                    $i = $this->getMaxTextLength() - 1;
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
                    'media' => array_splice($media, 0, $this->getMaxMediaCount()),
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

    /**
     * @param string|null $text
     *
     * @return string
     */
    public static function detectLanguage(?string $text): string
    {
        $lang = 'uk';
        if (empty($text)) {
            return $lang;
        }

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
            if (in_array($lang, ['ru', 'kk', 'be', 'sr', 'ky', 'tg', 'mn', 'uz']) || $score < 0.7) {
                Log::warning('strange lang detected: ' . $text);
                if ($lang == 'mk') {
                    $lang = 'bg';
                } else {
                    $lang = 'uk';
                }
            }
        } catch (Exception $e) {
            Log::error($e->getMessage());
        }

        return $lang;
    }

    /**
     * @param int $toId
     * @param int $fromId
     * @param int[] $fromIds
     *
     * @return void
     */
    protected static function createPostForward(int $toId, int $fromId, array $fromIds): void
    {
        if (!empty($fromIds)) {
            foreach ($fromIds as $item) {
                if (empty($item)) {
                    continue;
                }
                PostForward::query()->updateOrCreate([
                    'from_id' => $item,
                    'to_id' => $toId,
                ]);
            }
        } elseif (!empty($fromId)) {
            PostForward::query()->updateOrCreate([
                'from_id' => $fromId,
                'to_id' => $toId,
            ]);
        }
    }
}
