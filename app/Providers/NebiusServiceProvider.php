<?php

declare(strict_types=1);

namespace App\Providers;

use Exception;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use OpenAI;
use OpenAI\Client;
use OpenAI\Contracts\ClientContract;

final class NebiusServiceProvider extends BaseServiceProvider implements DeferrableProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ClientContract::class, static function (): Client {
            $apiKey = config('services.nebius.api_key');

            if (!is_string($apiKey)) {
                throw new Exception('The Nebius API Key is missing. Please set the environment variable.');
            }

            return OpenAI::factory()
                ->withApiKey($apiKey)
                ->withBaseUri(config('services.nebius.api_endpoint'))
                ->withHttpClient(new \GuzzleHttp\Client(['timeout' => config('services.nebius.api_timeout', 30)]))
                ->make();
        });

        $this->app->alias(ClientContract::class, 'nebius');
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            'nebius',
        ];
    }
}
