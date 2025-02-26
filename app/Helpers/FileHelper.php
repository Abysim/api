<?php

namespace App\Helpers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;
use OpenAI\Laravel\Facades\OpenAI;

class FileHelper
{
    /**
     * @param string $url
     * @param bool $isBinary
     *
     * @return string
     * @throws Exception
     */
    public static function getUrl(string $url, bool $isBinary = false): string
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

    public static function getMimeType(string $file): string
    {
        $fh = fopen('php://memory', 'w+b');
        fwrite($fh, $file);
        $result = mime_content_type($fh);
        fclose($fh);

        return $result;
    }

    public static function generateImageCaption(string $imagePath, string $language, $isDeep = false): string
    {
        $result = '';

        for ($i = 0; $i < 4; $i++) {
            try {
                $response = OpenAI::chat()->create(['model' => $isDeep ? 'chatgpt-4o-latest' : 'gpt-4o-mini', 'messages' => [
                    ['role' => 'user', 'content' => [
                        [
                            'type' => 'text',
                            'text' => "Generate the image caption for visually impaired people, focusing solely on evident visual elements such as colours, shapes, objects, and any discernible text without mentioning the image (do not use wording `at image`, `on picture`, etc.). Do not include additional descriptions, interpretations, what is missing, or assumptions not explicitly visible in the image. Limit the output to 300 characters. Write the caption in the following language: $language"
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => 'data:image/jpeg;base64,' . base64_encode(File::get($imagePath)),
                            ]
                        ],
                    ]]
                ]]);

                Log::info($imagePath . ': image description : ' . json_encode($response, JSON_UNESCAPED_UNICODE));

                if (!empty($response->choices[0]->message->content)) {
                    $result = $response->choices[0]->message->content;
                }
            } catch (Exception $e) {
                Log::error($imagePath . ': image description fail: ' . $e->getMessage());
            }

            if (!empty($result)) {
                break;
            }
        }

        return $result;
    }
}
