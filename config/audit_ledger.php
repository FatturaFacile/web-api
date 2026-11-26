<?php

declare(strict_types=1);
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Mod 005 - probative audit ledger (packages/audit-ledger)
|--------------------------------------------------------------------------
|
| The ledger is the probative business and security audit trail. Technical logs, errors and telemetry belong to Mod 113.
| Every tenant owns a keyed hash chain: hash_n = HMAC-SHA256(K_tenant, canonical_json(entry_n) || hash_{n-1}) with
| K_tenant = HKDF(master key of the key version, salt = tenant id). The master keys come from the environment or from a
| secret manager and are never stored in the database.
*/

return [
    /*
    | Master keys by key version (base64: prefix accepted, as for APP_KEY). Add a new version and run
    | RotateAuditKeyCommand to start signing with it: the old versions stay configured so that the history keeps verifying.
    */
    'master_keys' => array_filter([
        '1' => env('AUDIT_LEDGER_MASTER_KEY'),
        '2' => env('AUDIT_LEDGER_MASTER_KEY_V2'),
        '3' => env('AUDIT_LEDGER_MASTER_KEY_V3'),
    ], static fn (mixed $key): bool => is_string($key) && $key !== ''),

    'active_key_version' => (int) env('AUDIT_LEDGER_ACTIVE_KEY_VERSION', 1),

    /*
    | Local development and tests derive the master key from APP_KEY. Production refuses to write without a dedicated key.
    */
    'allow_app_key_fallback' => (bool) env('AUDIT_LEDGER_ALLOW_APP_KEY_FALLBACK', env('APP_ENV') !== 'production'),

    /*
    | Attribute names that are never recorded (not even pseudonymised): exact names after normalisation (lower case,
    | dashes as underscores), plus the prefixes and suffixes below. Exact matching avoids false hits such as `shipping_*`.
    */
    'secret_keys' => [
        'password', 'password_confirmation', 'current_password', 'new_password', 'old_password', 'secret', 'client_secret',
        'token', 'remember_token', 'api_token', 'api_key', 'access_token', 'refresh_token', 'id_token', 'private_key', 'otp',
        'otp_code', 'one_time_password', 'two_factor_secret', 'two_factor_recovery_codes', 'recovery_codes', 'auth_code',
        'authorization', 'cookie', 'cvv', 'cvc', 'card_number', 'pin', 'passcode', 'passphrase', 'webhook_secret',
    ],
    'secret_prefixes' => ['password_'],
    'secret_suffixes' => ['_password', '_secret', '_token', '_api_key', '_private_key', '_otp', '_passphrase'],

    /*
    | Personal attributes replaced by a pseudonym token (audit_subject_map): the token stays in the chain, the readable
    | value is held in the mapping and disappears with EraseAuditSubjectCommand. Models add their own through $auditPersonal.
    */
    'personal_keys' => [
        'fiscal_code', 'codice_fiscale', 'tax_code', 'ssn', 'avs_number', 'iban', 'qr_iban', 'date_of_birth', 'birth_date',
        'birth_place', 'passport_number', 'id_card_number', 'driving_license_number',
    ],

    'ip_truncation' => [
        'ipv4_prefix' => 24,
        'ipv6_prefix' => 48,
    ],

    /*
    | Failed logins: after `max_failures` inside `window_minutes` the IP is refused for `block_minutes`, with a security entry.
    */
    'login_throttle' => [
        'enabled' => (bool) env('AUDIT_LOGIN_THROTTLE', true),
        'max_failures' => (int) env('AUDIT_LOGIN_MAX_FAILURES', 10),
        'window_minutes' => 15,
        'block_minutes' => 15,
    ],

    /*
    | Retention in years by category. A configured value below the legal minimum is raised to it (CH CO art. 957a/958f,
    | GeBüV, LIVA art. 70, nLPD/OPDa; IT art. 2215-bis/2220 c.c., DPR 600/73, DPR 633/72, CAD/AgID, Garante 27/11/2008, GDPR).
    | Expiry is only allowed for entries covered by an anchored checkpoint.
    */
    'retention' => [
        'minimum_years' => [
            'crud' => 10,
            'business' => 10,
            'accounting' => 10,
            'security' => 1,
            'access' => 0.5,
            'platform' => 10,
        ],
        'years' => [
            'crud' => (float) env('AUDIT_RETENTION_CRUD_YEARS', 10),
            'business' => (float) env('AUDIT_RETENTION_BUSINESS_YEARS', 10),
            'accounting' => (float) env('AUDIT_RETENTION_ACCOUNTING_YEARS', 10),
            'security' => (float) env('AUDIT_RETENTION_SECURITY_YEARS', 2),
            'access' => (float) env('AUDIT_RETENTION_ACCESS_YEARS', 1),
            'platform' => (float) env('AUDIT_RETENTION_PLATFORM_YEARS', 10),
        ],
    ],

    /*
    | Checkpoints are anchored on a write-once target: the filesystem disk below (S3 Object Lock / Azure Immutable Blob
    | / the Mod 030 preservation package are configured as that disk).
    */
    'anchor' => [
        'enabled' => (bool) env('AUDIT_LEDGER_ANCHOR_ENABLED', true),
        'disk' => env('AUDIT_LEDGER_ANCHOR_DISK', env('AUDIT_WORM_DISK', 'audit_worm')),
        'prefix' => env('AUDIT_LEDGER_ANCHOR_PREFIX', 'audit-ledger-checkpoints'),
    ],

    'verification' => [
        'chunk_size' => 1000,
        'stale_run_minutes' => 120,
    ],

    'export' => [
        'disk' => env('AUDIT_LEDGER_EXPORT_DISK', 'local'),
        'prefix' => 'audit-ledger-exports',
        'max_window_days' => 366,
        'max_entries' => (int) env('AUDIT_LEDGER_EXPORT_MAX_ENTRIES', 500000),
    ],

    /*
    | Roles allowed to search, verify and export the ledger of a tenant.
    */
    'auditor_roles' => ['owner', 'admin', 'external_auditor', 'dpo', 'compliance'],

    /*
    | Short resource type of the history endpoint => the class written in the ledger and the module key the member needs
    | (read access) to see the history of that record.
    */
    'history_resources' => [
        'Invoice' => ['class' => Invoice::class, 'module' => 'invoicing'],
        'Contact' => ['class' => Contact::class, 'module' => 'crm'],
        'Payment' => ['class' => Payment::class, 'module' => 'invoicing'],
        'User' => ['class' => User::class, 'module' => 'system'],
    ],
];
