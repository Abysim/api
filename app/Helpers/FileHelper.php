<?php

namespace App\Helpers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

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
}
