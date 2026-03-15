<?php

namespace App;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OpenAI;
use OpenAI\Client;
use Psr\Http\Message\ResponseInterface;

class AI
{
    private static array $clients;

    public static function client(string $name): Client
    {
        if (!isset(self::$clients[$name])) {
            $httpClient = $name === 'openrouter'
                ? self::makeOpenRouterHttpClient()
                : new \GuzzleHttp\Client(['timeout' => config("services.$name.api_timeout", 30)]);

            self::$clients[$name] = OpenAI::factory()
                ->withApiKey(config("services.$name.api_key"))
                ->withBaseUri(config("services.$name.api_endpoint"))
                ->withHttpClient($httpClient)
                ->make();
        }

        return self::$clients[$name];
    }

    /**
     * OpenRouter returns completion_tokens_details without accepted_prediction_tokens/rejected_prediction_tokens,
     * which crashes openai-php/client v0.10.3. Strip that field from responses until SDK is upgraded.
     */
    private static function makeOpenRouterHttpClient(): \GuzzleHttp\Client
    {
        $stack = HandlerStack::create();
        $stack->push(Middleware::mapResponse(function (ResponseInterface $response): ResponseInterface {
            $body = (string) $response->getBody();
            $data = json_decode($body, true);
            if (is_array($data) && isset($data['usage']['completion_tokens_details'])) {
                unset($data['usage']['completion_tokens_details']);
                return new Response(
                    $response->getStatusCode(),
                    $response->getHeaders(),
                    json_encode($data),
                );
            }
            return $response;
        }));

        return new \GuzzleHttp\Client([
            'handler' => $stack,
            'timeout' => config('services.openrouter.api_timeout', 30),
        ]);
    }
}
