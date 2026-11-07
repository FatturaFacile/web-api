<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Associations
|--------------------------------------------------------------------------
|
| Member area link in the mails, lifetime of its tokens, and the online payment provider of the fees. Only the local
| driver exists for now: it needs no external account and confirms a payment through a signed callback.
|
*/

return [
    'area_url' => env('ASSOCIATIONS_AREA_URL', rtrim((string) env('FRONTEND_URL', 'http://localhost:4200'), '/').'/association-area'),
    'area_token_days' => (int) env('ASSOCIATIONS_AREA_TOKEN_DAYS', 30),
    'payment_driver' => env('ASSOCIATIONS_PAYMENT_DRIVER', 'local'),
    'payment_link_hours' => (int) env('ASSOCIATIONS_PAYMENT_LINK_HOURS', 48),
    'upload_max_kilobytes' => (int) env('ASSOCIATIONS_UPLOAD_MAX_KB', 10240),
    'import_max_rows' => (int) env('ASSOCIATIONS_IMPORT_MAX_ROWS', 5000),
];
