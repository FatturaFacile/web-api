<?php

/*
|--------------------------------------------------------------------------
| Broadcasting (realtime notifications)
|--------------------------------------------------------------------------
|
| The unread-notification counter is pushed to the web and desktop clients over WebSocket. `reverb` is the first-party
| server (Pusher protocol); `log` and `null` keep development and tests free of a running socket server.
*/

return [

    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [],
        ],

        'log' => ['driver' => 'log'],

        'null' => ['driver' => 'null'],

    ],

];
