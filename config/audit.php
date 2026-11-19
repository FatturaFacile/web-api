<?php

declare(strict_types=1);

return [
    /*
    | Master switch of the immutable audit trail capture (model events, sensitive reads and exports).
    */
    'enabled' => (bool) env('AUDIT_ENABLED', true),

    /*
    | Secrets. The HMAC secret signs every record; the encryption key protects old/new values at rest (AES-256-GCM).
    | Both fall back to a key derived from APP_KEY, but production should set dedicated values.
    */
    'hmac_secret' => env('AUDIT_HMAC_SECRET'),
    'encryption_key' => env('AUDIT_ENCRYPTION_KEY'),

    /*
    | A VIEWED event for the same (actor, entity type, entity id) inside this window is not logged again.
    */
    'view_debounce_seconds' => (int) env('AUDIT_VIEW_DEBOUNCE_SECONDS', 60),

    'lock_seconds' => 10,

    /*
    | WORM archival: sealed JSONL batches and their SHA-256 manifest. The disk may be an S3 compatible store with Object
    | Lock (Infomaniak Swiss Backup, AWS S3): the objects are written with the COMPLIANCE retention below.
    */
    'worm' => [
        'disk' => env('AUDIT_WORM_DISK', 'audit_worm'),
        'prefix' => env('AUDIT_WORM_PREFIX', 'audit-worm'),
        'object_lock_mode' => env('AUDIT_WORM_OBJECT_LOCK_MODE', 'COMPLIANCE'),
        'retention_years' => (int) env('AUDIT_WORM_RETENTION_YEARS', 10),
        'batch_size' => (int) env('AUDIT_WORM_BATCH_SIZE', 5000),
    ],
];
