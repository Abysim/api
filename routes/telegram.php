<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Longman\TelegramBot\Telegram;

Route::post('/telegram/webhook/{token}', static function (Telegram $bot, $token) {
    if ($token != config('telegram.bot.api_token')) {
        abort(400);
    }

    try {
        return $bot->handle();
    } catch (Throwable $e) {
        if ($e->getMessage() == 'Call to a member function getId() on null') {
            Log::error($e->getMessage());

            return true;
        }

        throw $e;
    }
})->middleware('telegram.network')->name('telegram.webhook');
