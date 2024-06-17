<?php

use App\Models\TwitterConnection;
use Atymic\Twitter\Facade\Twitter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Route;

Route::get('twitter/login', ['as' => 'twitter.login', static function () {
    $twitter = Twitter::forApiV1();
    $token = $twitter->getRequestToken(route('twitter.callback'));

    if (isset($token['oauth_token_secret'])) {
        $url =  $twitter->getAuthenticateUrl($token['oauth_token']);

        Cache::put('oauth_request_token', $token['oauth_token'], 10);
        Cache::put('oauth_request_token_secret', $token['oauth_token_secret'], 10);

        Log::info('Twitter login token: ' . json_encode($token));
        return Redirect::to($url);
    }

    Log::info('Twitter login error: ' . json_encode($token));
    return Redirect::route('twitter.error');
}]);

Route::get('twitter/callback', ['as' => 'twitter.callback', static function () {
    Log::info('Twitter oauth_verifier: ' . request('oauth_verifier'));
    Log::info('Twitter session token: ' . Cache::get('oauth_request_token'));

    if (Cache::has('oauth_request_token')) {
        $t = Twitter::forApiV1();
        $twitter = $t->usingCredentials(Cache::get('oauth_request_token'), Cache::get('oauth_request_token_secret'));
        $token = $twitter->getAccessToken(request('oauth_verifier'));

        if (!isset($token['oauth_token_secret'])) {
            return Redirect::route('twitter.error')->with('flash_error', 'We could not log you in on Twitter.');
        }

        // use new tokens
        $twitter = $t->usingCredentials($token['oauth_token'], $token['oauth_token_secret']);
        $credentials = $twitter->getCredentials();

        if (is_object($credentials) && !isset($credentials->error)) {
            $connection = TwitterConnection::updateOrCreate(
                ['id' => $credentials->id],
                [
                    'handle' => $credentials->screen_name,
                    'token' => $token['oauth_token'],
                    'secret' => $token['oauth_token_secret']
                ]
            );

            Log::info('Twitter connection: ' . json_encode($connection->getAttributes()));

            return Redirect::to('/')->with('notice', 'Congrats! You\'ve successfully signed in!');
        }
    }


    return Redirect::route('twitter.error')
        ->with('error', 'Something went wrong while signing you up!');
}]);

Route::get('twitter/error', ['as' => 'twitter.error', function () {
    // Something went wrong, add your own error handling here
}]);
