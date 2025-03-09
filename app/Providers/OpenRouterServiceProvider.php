<?php

declare(strict_types=1);

namespace App\Providers;

use Exception;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use OpenAI;
use OpenAI\Client;
use OpenAI\Contracts\ClientContract;

final class OpenRouterServiceProvider extends BaseServiceProvider implements DeferrableProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ClientContract::class, static function (): Client {
            $apiKey = config('services.openrouter.api_key');

            if (!is_string($apiKey)) {
                throw new Exception('The OpenRouter API Key is missing. Please set the environment variable.');
            }

            return OpenAI::factory()
                ->withApiKey($apiKey)
                ->withBaseUri(config('services.openrouter.api_endpoint'))
                ->withHttpClient(new \GuzzleHttp\Client(['timeout' => config('services.openrouter.api_timeout', 30)]))
                ->make();
        });

        $this->app->alias(ClientContract::class, 'openrouter');
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            'openrouter',
        ];
    }
}
