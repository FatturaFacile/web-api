<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Swiss customs
|--------------------------------------------------------------------------
|
| The local channel never leaves the application. The e-dec channel speaks to a real BAZG system: it refuses to run
| until `live_transmission` is true and the endpoint and the client certificate are all given here. There is no
| default endpoint on purpose. BAZG issues the client certificate, and the channel has never been run against BAZG.
|
*/

return [
    'default_channel' => env('CUSTOMS_DEFAULT_CHANNEL', 'local'),

    /** Disk of the assessment archive. It must never be a publicly served disk. */
    'archive_disk' => env('CUSTOMS_ARCHIVE_DISK', 'private'),

    /** Days read back by the daily retrieval. e-dec refuses a list request over more than 10 days (error V12). */
    'retrieval_window_days' => (int) env('CUSTOMS_RETRIEVAL_WINDOW_DAYS', 3),

    'edec' => [
        'live_transmission' => (bool) env('CUSTOMS_EDEC_LIVE_TRANSMISSION', false),
        'declaration_endpoint' => env('CUSTOMS_EDEC_DECLARATION_ENDPOINT'),
        'receipt_endpoint' => env('CUSTOMS_EDEC_RECEIPT_ENDPOINT'),
        'client_certificate' => env('CUSTOMS_EDEC_CLIENT_CERTIFICATE'),
        'client_key' => env('CUSTOMS_EDEC_CLIENT_KEY'),
        'client_key_passphrase' => env('CUSTOMS_EDEC_CLIENT_KEY_PASSPHRASE'),
        'timeout_seconds' => (int) env('CUSTOMS_EDEC_TIMEOUT_SECONDS', 120),

        /** BAZG roadmap of 22 June 2026: the last import declaration through e-dec is possible on this day. */
        'last_import_declaration_on' => env('CUSTOMS_EDEC_LAST_IMPORT_DECLARATION_ON', '2027-09-30'),
    ],
];
