<?php

declare(strict_types=1);

return [
    // Interactive quotes and commercial proposals (Mod 036).
    'number_prefix' => env('QUOTING_NUMBER_PREFIX', 'OFF'),
    'default_validity_days' => (int) env('QUOTING_VALIDITY_DAYS', 30),
    'otp_ttl_minutes' => (int) env('QUOTING_OTP_TTL_MINUTES', 10),
    'otp_max_attempts' => (int) env('QUOTING_OTP_MAX_ATTEMPTS', 5),
    'pin_session_ttl_minutes' => (int) env('QUOTING_PIN_SESSION_TTL_MINUTES', 120),
    'portal_url' => env('QUOTING_PORTAL_URL', env('APP_URL', 'http://localhost').'/proposal'),
    'storage_disk' => env('QUOTING_STORAGE_DISK', 'local'),

    // PDF/A-1b: when a Ghostscript binary is configured the rendered PDF is converted to PDF/A-1b and the archive profile is
    // embedded; without it the plain PDF is stored and marked pdfa_compliant=false in the audit trail.
    'pdfa' => [
        'ghostscript_binary' => env('QUOTING_GHOSTSCRIPT_BINARY'),
        'icc_profile' => env('QUOTING_PDFA_ICC_PROFILE'),
        'pdfa_def' => env('QUOTING_PDFA_DEF'),
    ],

    // Trust service provider of the AES/QES levels (Swisscom Trust Services, Skribble, InfoCert, Namirial...).
    'tsp' => [
        'provider' => env('QUOTING_TSP_PROVIDER', 'skribble'),
        'ceremony_url' => env('QUOTING_TSP_CEREMONY_URL'),
        'webhook_secret' => env('QUOTING_TSP_WEBHOOK_SECRET'),
    ],
];
