<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Google and Microsoft 365 sync
|--------------------------------------------------------------------------
|
| Without a client id a provider refuses to connect. Scopes are read-only: nothing is written to a calendar or mailbox.
|
*/

return [
    'redirect_uri' => env('WORKSPACE_REDIRECT_URI'),
    'providers' => [
        'google' => [
            'client_id' => env('GOOGLE_WORKSPACE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_WORKSPACE_CLIENT_SECRET'),
        ],
        'microsoft' => [
            'client_id' => env('MICROSOFT_365_CLIENT_ID'),
            'client_secret' => env('MICROSOFT_365_CLIENT_SECRET'),
        ],
    ],
];
