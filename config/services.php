<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'sdi' => [
        // Shared secret expected in the X-Webhook-Secret header of SDI notification webhooks.
        'webhook_secret' => env('SDI_WEBHOOK_SECRET'),
    ],

    // Registry lookups used to autofill and certify the customer registry (Customer 360).
    'vies' => [
        'base_url' => env('VIES_BASE_URL', 'https://ec.europa.eu/taxation_customs/vies/rest-api'),
        'timeout' => (int) env('VIES_TIMEOUT', 8),
    ],

    'zefix' => [
        'base_url' => env('ZEFIX_BASE_URL', 'https://www.zefix.admin.ch/ZefixPublicREST/api/v1'),
        // Zefix PublicREST requires registered Basic Auth credentials; without them only the UID checksum is checked.
        'username' => env('ZEFIX_USERNAME'),
        'password' => env('ZEFIX_PASSWORD'),
        'timeout' => (int) env('ZEFIX_TIMEOUT', 8),
    ],

];
