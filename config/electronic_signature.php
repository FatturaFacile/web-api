<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    | Disk of the documents of the envelopes (original, working copy, signed PDF). Keep it private: files are only served
    | through the API after the token or the permission check.
    */
    'disk' => env('ELECTRONIC_SIGNATURE_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Signing links and one-time codes
    |--------------------------------------------------------------------------
    */
    'default_expiration_days' => (int) env('ELECTRONIC_SIGNATURE_EXPIRATION_DAYS', 14),
    'max_expiration_days' => 90,
    'default_reminder_days' => [3, 1],
    'max_recipients' => 20,
    'max_document_bytes' => 15 * 1024 * 1024,

    'otp' => [
        'digits' => 6,
        'ttl_minutes' => 10,
        'max_attempts' => 5,
    ],

    'portal' => [
        // Requests per minute and IP address on the public signing and verification endpoints.
        'throttle' => (int) env('ELECTRONIC_SIGNATURE_PORTAL_THROTTLE', 30),
        'challenge_throttle' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Compliance rules applied when a template does not define one
    |--------------------------------------------------------------------------
    | Minimum tier by document type and amount (CHF / EUR are compared at par). A template with `min_amount` of the company
    | refines these rules. Employment contracts that carry a written-form clause (CO art. 13 ff., Italian c.c. art. 2125 for
    | the non-compete covenant) need a qualified signature.
    */
    'policies' => [
        'quote' => [['min_amount' => 0, 'level' => 'FES']],
        'purchase_order' => [
            ['min_amount' => 0, 'level' => 'FES'],
            ['min_amount' => 5000, 'level' => 'FEA'],
            ['min_amount' => 25000, 'level' => 'FEQ'],
        ],
        'employment_contract' => [['min_amount' => 0, 'level' => 'FEQ']],
        'document' => [['min_amount' => 0, 'level' => 'FES']],
        'upload' => [['min_amount' => 0, 'level' => 'FES']],
    ],

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    | Endpoints of the production services. A provider configuration can override them in its settings. The credentials
    | themselves are stored encrypted per company, never here.
    */
    'providers' => [
        'swisscom' => [
            'base_url' => env('SWISSCOM_AIS_URL', 'https://ais.swissdigitalsignature.com/AIS-Server/rs/v1.0'),
            'timeout' => 30,
            'poll_seconds' => 90,
        ],
        'infocert' => [
            'base_url' => env('INFOCERT_SIGN_URL', 'https://signature.infocert.digital/v1'),
            'timeout' => 30,
        ],
        'namirial' => [
            'base_url' => env('NAMIRIAL_ESAW_URL', 'https://esaw.namirial.com/api/v6'),
            'timeout' => 30,
        ],
    ],

    'webhooks' => [
        // A callback older than this many seconds is refused (replay protection); nonces are remembered for twice as long.
        'tolerance_seconds' => 300,
    ],

    'sms' => [
        'url' => env('ELECTRONIC_SIGNATURE_SMS_URL'),
        'token' => env('ELECTRONIC_SIGNATURE_SMS_TOKEN'),
        'sender' => env('ELECTRONIC_SIGNATURE_SMS_SENDER', 'FatturaFacile'),
        'timeout' => 10,
    ],

    // Retention of the signed documents and of the audit dossier archived with them.
    'archive_retention_years' => 10,
];
