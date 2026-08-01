<?php

declare(strict_types=1);

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

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reverb (server-to-server)
    |--------------------------------------------------------------------------
    |
    | REVERB_HOST is the address a BROWSER dials. Server-side calls into Reverb's
    | HTTP API — such as Doc 02 T-04's socket termination — need the internal
    | address instead, which in the container stack is the service name. Doc 18
    | sets these per environment.
    |
    */

    'reverb' => [
        'internal_host' => env('REVERB_INTERNAL_HOST', 'reverb'),
        'internal_port' => env('REVERB_INTERNAL_PORT', '8080'),
    ],

];
