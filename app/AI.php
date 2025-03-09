<?php

namespace App;

use OpenAI;
use OpenAI\Client;

class AI
{
    private static array $clients;

    public static function client(string $name): Client
    {
        if (!isset(self::$clients[$name])) {
            self::$clients[$name] = OpenAI::factory()
                ->withApiKey(config("services.$name.api_key"))
                ->withBaseUri(config("services.$name.api_endpoint"))
                ->withHttpClient(new \GuzzleHttp\Client(['timeout' => config("services.$name.api_timeout", 30)]))
                ->make();
        }

        return self::$clients[$name];
    }
}
