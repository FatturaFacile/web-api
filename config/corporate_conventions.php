<?php

declare(strict_types=1);

return [
    /*
    | Work-email one-time password: lifetime in minutes, wrong codes allowed per challenge and challenges one address may request per hour.
    */
    'otp' => [
        'ttl_minutes' => (int) env('CONVENTIONS_OTP_TTL_MINUTES', 10),
        'max_attempts' => (int) env('CONVENTIONS_OTP_MAX_ATTEMPTS', 5),
        'max_requests_per_hour' => (int) env('CONVENTIONS_OTP_MAX_REQUESTS_PER_HOUR', 5),
    ],

    /*
    | Months a pass stays valid after enrolment (never beyond the end of the convention).
    */
    'pass_validity_months' => (int) env('CONVENTIONS_PASS_VALIDITY_MONTHS', 12),

    /*
    | Prefix of the QR payload printed on every convention pass; the POS reads FFCONV:<wallet pass id>.
    */
    'qr_prefix' => 'FFCONV:',

    /*
    | Slugs that would collide with the fixed paths of the public API or the landing page.
    */
    'reserved_slugs' => ['passes', 'new', 'dashboard', 'billing-reconciliation', 'admin', 'api'],

    /*
    | VAT rate (percent) applied to the consolidated invoice of the employer quota, by country of the convention.
    | The quota stored on a split billing is a gross (VAT included) amount, as it is part of the retail price.
    */
    'invoice_vat_rate' => ['IT' => '22.00', 'CH' => '8.10'],

    /*
    | Default merchant fee (percent of the voucher face value) by provider. They mirror typical contracts and are only the default
    | proposed at the till: the cashier or the settlement statement may override them per redemption.
    */
    'welfare_fees' => [
        'EDENRED' => '8.00',
        'PELLEGRINI' => '7.50',
        'PLUXEE_SODEXO' => '8.00',
        'EUDAIMON' => '5.00',
        'DOUBLEYOU' => '5.00',
        'REKA_CARD' => '1.50',
        'LUNCH_CHECK' => '1.50',
        'BONCADEAU' => '3.00',
        'OTHER' => '0.00',
    ],

    /*
    | Tolerance (in currency units) between the expected net and the amount a provider settles before a redemption is disputed.
    */
    'reconciliation_tolerance' => '0.01',

    /*
    | Digital wallet passes. Apple: pass type identifier, team identifier and the PKCS#12 certificate that signs the .pkpass.
    | Google: service account e-mail, its private key (PEM) and the issuer id. A wallet without credentials is reported as
    | unavailable and its button is hidden; the QR code of the pass always works.
    */
    'wallet' => [
        'organization_name' => env('CONVENTIONS_WALLET_ORGANIZATION', 'FatturaFacile'),
        'apple' => [
            'pass_type_identifier' => env('CONVENTIONS_APPLE_PASS_TYPE_ID'),
            'team_identifier' => env('CONVENTIONS_APPLE_TEAM_ID'),
            'certificate_path' => env('CONVENTIONS_APPLE_CERTIFICATE_PATH'),
            'certificate_password' => env('CONVENTIONS_APPLE_CERTIFICATE_PASSWORD'),
            'wwdr_certificate_path' => env('CONVENTIONS_APPLE_WWDR_PATH'),
        ],
        'google' => [
            'issuer_id' => env('CONVENTIONS_GOOGLE_ISSUER_ID'),
            'service_account_email' => env('CONVENTIONS_GOOGLE_SERVICE_ACCOUNT'),
            'private_key' => env('CONVENTIONS_GOOGLE_PRIVATE_KEY'),
        ],
    ],

    /*
    | Disk and folder of the PDF contracts, logos and the beneficiary schedules attached to the consolidated invoices.
    */
    'storage' => [
        'disk' => env('CONVENTIONS_STORAGE_DISK', 'local'),
        'folder' => 'corporate-conventions',
    ],
];
