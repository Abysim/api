<?php

use App\Models\FediverseConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

Route::get('login', ['as' => 'fediverse.login', static function () {
    try {
        $url = request('url');
        if (Str::charAt($url, Str::length($url) - 1) != '/') {
            $url = $url . '/';
        }

        Log::info('Fediverse login url: ' . $url);

        $apps = Http::post($url . 'api/v1/apps', [
            'client_name' => request('name', config('name')),
            'redirect_uris' => route('fediverse.callback'),
            'scopes' => 'read write',
            'website' => request('website', '')
        ]);

        if (!empty($apps['client_id'])) {
            Session::put('fedivese_client_id', $apps['client_id']);
            Session::put('fediverse_client_secret', $apps['client_secret']);
            Session::put('fediverse_cat', request('cat', ''));
            Session::put('fediverse_url', $url);

            Log::info('Fediverse login apps: ' . json_encode($apps));

            return Redirect::away($url . 'oauth/authorize?' . http_build_query([
                'client_id' => $apps['client_id'],
                'redirect_uri' => route('fediverse.callback'),
                'scope' => 'read write',
                'response_type' => 'code'
            ]));
        }
    } catch (Exception $e) {
        Log::error('Fediverse login error: ' . $e->getMessage());
        return $e->getMessage();
    }

    Log::error('Fediverse login error: ' . json_encode($apps));
    return 'Something went wrong while signing you up!';
}]);

Route::get('callback', ['as' => 'fediverse.callback', static function () {
    Log::info('Fediverse code: ' . request('code'));
    Log::info('Fediverse session client_id: ' . Session::get('fedivese_client_id'));

    if (Session::has('fedivese_client_id')) {
        try {
            $url = Session::get('fediverse_url');

            $token = Http::post($url . 'oauth/token', [
                'grant_type' => 'authorization_code',
                'code' => request('code'),
                'client_id' => Session::get('fedivese_client_id'),
                'client_secret' => Session::get('fediverse_client_secret'),
                'redirect_uri' => route('fediverse.callback'),
                'scope' => 'read write',
            ]);

            if (!isset($token['access_token'])) {
                return 'We could not log you in on Fediverse.';
            }

            // use new tokens
            $credentials = Http::withToken($token['access_token'])->get($url . 'api/v1/accounts/verify_credentials');
        } catch (Exception $e) {
            Log::error('Fediverse login error: ' . $e->getMessage());
            return $e->getMessage();
        }

        if (!empty($credentials) && !isset($credentials['error'])) {
            $connection = FediverseConnection::updateOrCreate(
                ['account_id' => $credentials['id'], 'url' => $url, 'cat' => Session::get('fediverse_cat')],
                [
                    'handle' => $credentials['username'],
                    'client_id' => Session::get('fedivese_client_id'),
                    'client_secret' => Session::get('fediverse_client_secret'),
                    'token' => $token['access_token'],
                ]
            );

            Log::info('Fediverse connection: ' . json_encode($connection->getAttributes()));

            return 'Congrats! You\'ve successfully signed in!';
        } elseif (!empty($credentials)) {
            return $credentials['error'];
        }
    }

    return 'Something went wrong while signing you up!';
}]);
