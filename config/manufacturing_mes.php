<?php

declare(strict_types=1);

/*
| MES Core integration of the manufacturing module (GitHub issue #184).
|
| The MES Core calls the `mes-sync` and `mes-feedback` endpoints as a service, signed with an HMAC key. Every key is
| bound to the tenants it may address: MES_SYNC_KEYS is a JSON object {"<kid>": {"secret": "...", "tenants": ["<tenant uuid>", ...]}}.
| Two keys can be valid at the same time, which is how a key is rotated.
*/

/** @var mixed $keys */
$keys = json_decode((string) env('MES_SYNC_KEYS', '{}'), true);

return [
    'keys' => is_array($keys) ? $keys : [],

    // Tolerated clock difference between the MES Core and this API, in seconds, for the signature timestamp.
    'signature_tolerance_seconds' => 300,

    // Shift calendar materialisation: the weekly calendar and its dated exceptions only hold gross hours, so the
    // start of the shift and the time zone of the plant come from here.
    'shifts' => [
        'timezone' => env('MES_SHIFT_TIMEZONE', 'Europe/Rome'),
        'start_time' => env('MES_SHIFT_START_TIME', '06:00'),
        'code' => 'DAY',
        'horizon_days' => (int) env('MES_SHIFT_HORIZON_DAYS', 14),
    ],

    // Change log rows older than this are pruned (a cursor older than the pruned part gets 410 Gone).
    'changes_retention_days' => (int) env('MES_CHANGES_RETENTION_DAYS', 30),

    // Laravel -> MES Core push (`POST {base_url}/api/v1/mes/ref-events`), signed like every other call. Empty base_url
    // turns the push off; the MES Core then relies on its periodic pull. key_id/secret must be listed in the MES Core's
    // LaravelSync:InboundKeys.
    'core' => [
        'base_url' => (string) env('MES_CORE_BASE_URL', ''),
        'key_id' => (string) env('MES_CORE_KEY_ID', 'k1'),
        'secret' => (string) env('MES_CORE_SECRET', ''),
        'timeout_seconds' => (int) env('MES_CORE_TIMEOUT', 5),
        'push_tries' => 5,
    ],

    'sync' => [
        'default_limit' => 500,
        'max_limit' => 1000,
        'manifest_max_limit' => 5000,
    ],
];
