<?php

use App\Models\TwitterConnection;
use Atymic\Twitter\Facade\Twitter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;

Route::get('login', ['as' => 'twitter.login', static function () {
    try {
        $twitter = Twitter::forApiV1();
        $token = $twitter->getRequestToken(route('twitter.callback'));

        if (isset($token['oauth_token_secret'])) {
            $url = $twitter->getAuthenticateUrl($token['oauth_token']);

            Session::put('oauth_request_token', $token['oauth_token']);
            Session::put('oauth_request_token_secret', $token['oauth_token_secret']);

            Log::info('Twitter login token: ' . json_encode($token));

            return Redirect::to($url);
        }
    } catch (Exception $e) {
        Log::error('Twitter login error: ' . $e->getMessage());
        return $e->getMessage();
    }

    Log::error('Twitter login error: ' . json_encode($token));
    return 'Something went wrong while signing you up!';
}]);

Route::get('callback', ['as' => 'twitter.callback', static function () {
    Log::info('Twitter oauth_verifier: ' . request('oauth_verifier'));
    Log::info('Twitter session token: ' . Session::get('oauth_request_token'));

    if (Session::has('oauth_request_token')) {
        try {
            $t = Twitter::forApiV1();
            $twitter = $t->usingCredentials(Session::get('oauth_request_token'), Session::get('oauth_request_token_secret'));
            $token = $twitter->getAccessToken(request('oauth_verifier'));

            if (!isset($token['oauth_token_secret'])) {
                return 'We could not log you in on Twitter.';
            }

            // use new tokens
            $twitter = $t->usingCredentials($token['oauth_token'], $token['oauth_token_secret']);
            $credentials = $twitter->getCredentials();
        } catch (Exception $e) {
            Log::error('Twitter login error: ' . $e->getMessage());
            return $e->getMessage();
        }

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

            return 'Congrats! You\'ve successfully signed in!';
        } elseif (is_object($credentials)) {
            return $credentials->error;
        }
    }

    return 'Something went wrong while signing you up!';
}]);
