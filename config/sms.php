<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SMS gateway
|--------------------------------------------------------------------------
|
| Used by the SMS second factor. `log` writes the message to the application log (local development and tests, the
| number is masked); `http` posts `{"to", "message", "sender"}` as JSON with a bearer token to any provider.
|
*/

return [
    'driver' => env('SMS_DRIVER', 'log'),

    'http' => [
        'url' => env('SMS_HTTP_URL'),
        'token' => env('SMS_HTTP_TOKEN'),
        'sender' => env('SMS_SENDER', env('APP_NAME', 'FatturaFacile')),
        'timeout' => (int) env('SMS_HTTP_TIMEOUT', 10),
    ],
];
