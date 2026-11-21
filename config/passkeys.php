<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Passkeys (WebAuthn / FIDO2)
|--------------------------------------------------------------------------
|
| The relying party is the SPA host: credentials are bound to `rp_id` and an assertion is accepted only from one of
| `origins`. Native clients (desktop, mobile) add their own origins (for example `android:apk-key-hash:...`) through
| PASSKEY_ORIGINS. A ceremony (challenge) lives `ceremony_ttl` seconds and can be used once.
|
*/

$frontend = (string) env('FRONTEND_URL', 'http://localhost:4200');
$frontendHost = (string) (parse_url($frontend, PHP_URL_HOST) ?: 'localhost');
$extraOrigins = array_values(array_filter(array_map('trim', explode(',', (string) env('PASSKEY_ORIGINS', '')))));

return [
    'rp_id' => env('PASSKEY_RP_ID', $frontendHost),
    'rp_name' => env('PASSKEY_RP_NAME', env('APP_NAME', 'FatturaFacile')),
    'origins' => array_values(array_unique([rtrim($frontend, '/'), ...$extraOrigins])),
    'ceremony_ttl' => (int) env('PASSKEY_CEREMONY_TTL', 300),
    'timeout_ms' => 60000,
];
