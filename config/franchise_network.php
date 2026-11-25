<?php

declare(strict_types=1);

return [
    /*
    | Monthly billing of the royalty and the national marketing fund. VAT of the invoices the franchisor issues to its stores:
    | Italy 22 % ordinary rate, Switzerland the standard MWST rate. collection_lead_days: days between the invoice run and the
    | direct debit date (SEPA SDD requires the pre-notification, LSV+ the bank cut-off).
    */
    'billing' => [
        'it_vat_rate' => env('FRANCHISE_NETWORK_VAT_IT_RATE', '22'),
        'ch_vat_rate' => env('FRANCHISE_NETWORK_VAT_CH_RATE', '8.1'),
        'payment_days' => (int) env('FRANCHISE_NETWORK_PAYMENT_DAYS', 30),
        'auto_invoice' => (bool) env('FRANCHISE_NETWORK_AUTO_INVOICE', false),
        'collection_lead_days' => ['sepa_sdd' => 14, 'lsv_plus' => 7],
    ],

    /*
    | Shared loyalty programme: value of one point, used to price the redemptions that are settled between the stores.
    */
    'loyalty' => [
        'point_value' => env('FRANCHISE_NETWORK_LOYALTY_POINT_VALUE', '0.01'),
    ],

    /*
    | Benchmark anonymity: a size cluster with fewer stores than this is never shown (the network aggregate replaces it), so no
    | single store can be recognised from the median, the average or the top quartile.
    */
    'benchmark' => [
        'min_peers' => (int) env('FRANCHISE_NETWORK_BENCHMARK_MIN_PEERS', 3),
    ],

    /*
    | Catalogue pushes: a failed delivery is retried with a growing delay (minutes x attempts) up to max_attempts.
    */
    'push' => [
        'max_attempts' => (int) env('FRANCHISE_NETWORK_PUSH_MAX_ATTEMPTS', 5),
        'retry_minutes' => (int) env('FRANCHISE_NETWORK_PUSH_RETRY_MINUTES', 15),
    ],

    /*
    | Store audits: days to close a corrective action, and the shorter delay for a critical criterion at zero.
    */
    'audit' => [
        'action_days' => (int) env('FRANCHISE_NETWORK_AUDIT_ACTION_DAYS', 30),
        'critical_action_days' => (int) env('FRANCHISE_NETWORK_AUDIT_CRITICAL_ACTION_DAYS', 7),
        'photo_max_kb' => (int) env('FRANCHISE_NETWORK_AUDIT_PHOTO_MAX_KB', 8192),
    ],
];
