<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'flickr' => [
        'key' => env('FLICKR_KEY'),
    ],

    'ifttt' => [
        'webhook_key' => env('IFTTT_WEBHOOK_KEY'),
    ],

    'bluesky' => [
        'handle' => env('BLUESKY_HANDLE'),
    ],

    'newscatcher' => [
        'key' => env('NEWSCATCHER_KEY'),
    ],

    'bigcats' => [
        'url' => env('BIGCATS_URL'),
        'key' => env('BIGCATS_KEY'),
    ],

    'nebius' => [
        'url' => env('NEBIUS_API_ENDPOINT'),
        'key' => env('NEBIUS_API_KEY'),
    ],
];
