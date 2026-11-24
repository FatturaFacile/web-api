<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Associations
|--------------------------------------------------------------------------
|
| Member area link in the mails, lifetime of its tokens, and the online payment provider of the fees. The drivers are
| the ones of the invoice payment links (Integrations module): `local`, which needs no external account and confirms a
| payment through a callback signed with the webhook secret, and `stripe`. Without a webhook secret the local driver
| signs with a secret derived from the application key.
|
*/

return [
    'area_url' => env('ASSOCIATIONS_AREA_URL', rtrim((string) env('FRONTEND_URL', 'https://localhost:4200'), '/').'/association-area'),
    'area_token_days' => (int) env('ASSOCIATIONS_AREA_TOKEN_DAYS', 30),
    'payment_driver' => env('ASSOCIATIONS_PAYMENT_DRIVER', 'local'),
    'payment_credentials' => [
        'secret_key' => env('ASSOCIATIONS_PAYMENT_SECRET_KEY'),
        'webhook_secret' => env('ASSOCIATIONS_PAYMENT_WEBHOOK_SECRET'),
    ],
    'payment_link_hours' => (int) env('ASSOCIATIONS_PAYMENT_LINK_HOURS', 48),
    'upload_max_kilobytes' => (int) env('ASSOCIATIONS_UPLOAD_MAX_KB', 10240),
    'import_max_rows' => (int) env('ASSOCIATIONS_IMPORT_MAX_ROWS', 5000),
];
