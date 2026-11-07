<?php

declare(strict_types=1);

return [

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
