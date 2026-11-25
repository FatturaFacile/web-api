<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Open banking (PSD2 / SIX bLink)
|--------------------------------------------------------------------------
|
| Drivers:
|  - refused: every call answers with an error (default: no provider contract exists yet);
|  - local:   minimal simulator for development and tests, never runs in production;
|  - sandbox: deterministic sample accounts and movements, so the whole import and reconciliation workflow can be tried
|              without credentials (never runs in production);
|  - psd2:    a NextGenPSD2 (Berlin Group) account information and payment initiation API of a bank or aggregator;
|  - blink:   SIX bLink account information API.
|
| Credentials of a real connection are read from the environment, never stored in code. Paths are relative to `base_url`
| and can be adapted to the provider without touching the gateway.
|
*/

return [
    'driver' => env('OPEN_BANKING_DRIVER', 'refused'),

    // Days of movements fetched by a sync that does not name a period.
    'sync_lookback_days' => (int) env('OPEN_BANKING_SYNC_LOOKBACK_DAYS', 14),

    'psd2' => [
        'base_url' => env('OPEN_BANKING_PSD2_BASE_URL'),
        'token_url' => env('OPEN_BANKING_PSD2_TOKEN_URL'),
        'client_id' => env('OPEN_BANKING_PSD2_CLIENT_ID'),
        'client_secret' => env('OPEN_BANKING_PSD2_CLIENT_SECRET'),
        'timeout' => (int) env('OPEN_BANKING_PSD2_TIMEOUT', 20),
        'paths' => [
            'consents' => '/v1/consents',
            'accounts' => '/v1/accounts',
            'balances' => '/v1/accounts/{account}/balances',
            'transactions' => '/v1/accounts/{account}/transactions',
            'payments' => '/v1/payments/sepa-credit-transfers',
        ],
    ],

    'blink' => [
        'base_url' => env('OPEN_BANKING_BLINK_BASE_URL'),
        'token_url' => env('OPEN_BANKING_BLINK_TOKEN_URL'),
        'client_id' => env('OPEN_BANKING_BLINK_CLIENT_ID'),
        'client_secret' => env('OPEN_BANKING_BLINK_CLIENT_SECRET'),
        'timeout' => (int) env('OPEN_BANKING_BLINK_TIMEOUT', 20),
        'paths' => [
            'consents' => '/consents',
            'accounts' => '/accounts',
            'balances' => '/accounts/{account}/balances',
            'transactions' => '/accounts/{account}/transactions',
            'payments' => '/payments',
        ],
    ],
];
