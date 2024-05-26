<?php

use Illuminate\Support\Facades\Route;
use Longman\TelegramBot\Telegram;

Route::post('/telegram/webhook/{token}', static function (Telegram $bot, $token) {
    if ($token != config('telegram.bot.api_token')) {
        abort(400);
    }

    $bot->handle();
})->middleware('telegram.network')->name('telegram.webhook');
