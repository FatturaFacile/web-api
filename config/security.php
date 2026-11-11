<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | HTTPS only
    |--------------------------------------------------------------------------
    |
    | The system works over HTTPS only, in every environment (local development and tests included): there is no switch
    | to turn it off. A plain-HTTP GET/HEAD is redirected (308) to the same path on the host of APP_URL; any other method
    | is refused with 426 and its body is never processed. Behind a reverse proxy the scheme comes from X-Forwarded-Proto,
    | and only from the proxies listed in TRUSTED_PROXIES.
    |
    | `insecure_allowed_paths` lists the only paths answered over plain HTTP (GET/HEAD only, nothing read from the
    | request): the liveness probe of the container or load balancer, which calls the application directly.
    |
    | HSTS is sent on every secure response. `preload` is off by default: the preload list is a one-way door, enable it
    | only after the whole domain (subdomains included) is HTTPS-only and the domain was submitted on purpose.
    |
    */

    'https' => [
        'insecure_allowed_paths' => array_values(array_filter(array_map('trim', explode(',', (string) env('SECURITY_HTTP_ALLOWED_PATHS', 'up'))))),
    ],

    'hsts' => [
        'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
        'include_subdomains' => filter_var(env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', true), FILTER_VALIDATE_BOOL),
        'preload' => filter_var(env('SECURITY_HSTS_PRELOAD', false), FILTER_VALIDATE_BOOL),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trusted Reverse Proxies
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of reverse proxy IP addresses or CIDR ranges trusted
    | to forward X-Forwarded-* headers. Never defaults to unconditional '*'.
    |
    */

    'trusted_proxies' => env('TRUSTED_PROXIES', '127.0.0.1,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16'),

    /*
    |--------------------------------------------------------------------------
    | Outbound requests
    |--------------------------------------------------------------------------
    |
    | Every URL or host the server reaches on behalf of a tenant (shop APIs, carriers, mailboxes) must resolve to
    | public addresses only. `allowed_hosts` lists host names (or `*.suffix` patterns) that skip that check, for a
    | shop or a mailbox running on the developer's machine. It is honoured in the `local` environment only and
    | ignored everywhere else.
    |
    */

    'outbound' => [
        'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('OUTBOUND_ALLOWED_HOSTS', ''))))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook replay protection
    |--------------------------------------------------------------------------
    |
    | A signed delivery is accepted once: its body (and the delivery id the platform sends, when it sends one) is
    | remembered for `dedupe_ttl_seconds`. A delivery whose platform timestamp is older than `tolerance_seconds` is
    | refused. Shopify retries a failed delivery for up to 48 hours with its original timestamp, hence the defaults.
    |
    */

    'webhooks' => [
        'tolerance_seconds' => (int) env('WEBHOOK_REPLAY_TOLERANCE_SECONDS', 172800),
        'dedupe_ttl_seconds' => (int) env('WEBHOOK_DEDUPE_TTL_SECONDS', 180000),
    ],

];
