<?php

namespace App;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use mpstenson\CloudflareAI\CloudflareAI;

class MyCloudflareAI extends CloudflareAI
{
    /**
     * @inheritDoc
     * @param int $timeout
     */
    public static function runModel(array $input, ?string $modelName = null, int $timeout = 8): array|JsonResponse
    {
        $modelName ??= config('cloudflare-ai.default_model');
        $url = config('cloudflare-ai.api_url').'/accounts/'.config('cloudflare-ai.account_id').'/ai/run/@cf/'.$modelName;

        try {
            $response = Http::withToken(config('cloudflare-ai.api_token'))
                ->timeout($timeout)
                ->contentType('application/json')
                ->post($url, $input);

            return $response->json() ?? [];
        } catch (\Illuminate\Http\Client\RequestException $e) {
            return response()->json(['error' => 'Request to Cloudflare API failed', 'details' => $e->getMessage()], 500);
        }
    }
}
