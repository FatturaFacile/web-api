<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Swiss eBill (SIX Paynet / PostFinance) — Mod 025, Issue #283
|--------------------------------------------------------------------------
*/

return [
    // Largest XML payload (including the Base64 courtesy PDF) the module hands to the network. SIX access points refuse bigger invoices.
    'max_payload_bytes' => (int) env('SWISS_EBILL_MAX_PAYLOAD_BYTES', 5_000_000),

    // How long a participant directory answer is reused before the gateway is asked again (a forced refresh ignores it).
    'lookup_cache_hours' => (int) env('SWISS_EBILL_LOOKUP_CACHE_HOURS', 24),

    // Delivery retries after a transient gateway error (HTTP 429, 5xx, network): seconds to wait before attempt 2, 3, ...
    'retry_backoff_seconds' => [30, 120, 600, 1800, 3600],

    // The polling fallback only looks at transmissions that did not hear from the gateway for this many minutes.
    'poll_stale_after_minutes' => (int) env('SWISS_EBILL_POLL_STALE_MINUTES', 15),

    // Tolerance of the webhook timestamp header against replays, in seconds.
    'webhook_tolerance_seconds' => (int) env('SWISS_EBILL_WEBHOOK_TOLERANCE', 300),

    // Reverse proxy header that carries the verdict of the mutual TLS handshake, and the one with the client certificate fingerprint.
    'mtls_verify_header' => env('SWISS_EBILL_MTLS_VERIFY_HEADER', 'X-SSL-Client-Verify'),
    'mtls_fingerprint_header' => env('SWISS_EBILL_MTLS_FINGERPRINT_HEADER', 'X-SSL-Client-Fingerprint'),

    // VAT rates (percent) a Swiss invoice may carry; the older rates are accepted for invoices dated before 2024-01-01.
    'vat_rates' => [8.1, 2.6, 3.8, 0.0],
    'legacy_vat_rates' => [7.7, 2.5, 3.7],
];
