<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Multi-currency engine (Mod 003, issue #249)
|--------------------------------------------------------------------------
|
| Exchange-rate providers (ECB, SNB, ESTV), the look-back window of the rate selection and the currencies tracked for derived
| cross rates. The providers never answer with built-in rates: a feed that cannot be fetched or validated fails the run.
|
*/

return [
    // Currency the cross rates go through (the ECB publishes everything against EUR).
    'pivot' => 'EUR',

    // A document day without a rate takes the latest earlier rate up to this many days back; beyond that the document is refused.
    'window_days' => 5,

    // Currencies for which official rates (and the derived cross rates between them) are stored by the daily sync. The active
    // currencies of every company are added to this list.
    'tracked_currencies' => ['CHF', 'EUR', 'USD', 'GBP'],

    // Active currencies of a company without settings.
    'default_active_currencies' => ['CHF', 'EUR', 'USD', 'GBP'],

    // Roles of a company that are told when a synchronisation fails or a currency moves beyond its threshold.
    'notify_roles' => ['owner', 'admin', 'accountant', 'cfo'],

    // Time of the day (Europe/Zurich) of the daily sync, after the ECB publication around 16:00 CET.
    'daily_sync_at' => '16:30',

    'providers' => [
        'ecb' => [
            'url' => env('CURRENCY_ECB_URL', 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml'),
            'timeout' => 5,
            'backoff_ms' => [300, 900, 2700],
        ],
        // SNB data portal (CSV cubes: date; series such as EUR1 or JPY100; value). `daily_url` carries the daily rates,
        // `monthly_url` the month-end rates stored as closing rates. Confirm the cube identifiers against the SNB portal.
        'snb' => [
            'daily_url' => env('CURRENCY_SNB_DAILY_URL', 'https://data.snb.ch/api/cube/devkua/data/csv/en'),
            'monthly_url' => env('CURRENCY_SNB_MONTHLY_URL', 'https://data.snb.ch/api/cube/devkum/data/csv/en'),
            'lookback_days' => 10,
            'timeout' => 10,
            'backoff_ms' => [300, 900, 2700],
        ],
        // ESTV monthly average rates for Swiss VAT (Info IVA 16), published as XML: one entry per currency with its code, unit
        // and rate for a month. Confirm the endpoint and its schema against the ESTV / customs publication.
        'estv' => [
            'url' => env('CURRENCY_ESTV_URL', 'https://www.backend-rates.bazg.admin.ch/api/xmlavgmonth'),
            'timeout' => 10,
            'backoff_ms' => [300, 900, 2700],
        ],
    ],
];
