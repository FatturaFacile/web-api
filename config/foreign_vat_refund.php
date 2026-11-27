<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Foreign VAT refund (Mod 227 / Issue #473)
    |--------------------------------------------------------------------------
    | Disk of the generated dossiers (EU portal XML, ESTV 1222/1223, Modello IVA 79, ZIP bundles), of the scans of the original
    | invoices and of the mandate documents. Private: files are served only through authenticated endpoints.
    */
    'disk' => env('FOREIGN_VAT_REFUND_DISK', 'local'),

    // Largest scan accepted, in kilobytes.
    'max_upload_kb' => 10240,

    // Codes of the ledger accounts created on first use when the company chose none (settings screen).
    'accounts' => [
        'receivable_code' => '1198',
        'non_recoverable_expense_code' => '6898',
    ],

    // Days before the peremptory deadline at which the alerts are sent (highest level first).
    'alert_levels' => [60, 30, 15],

    // Days before the expiry of a certificate of taxable status at which the company is warned.
    'certificate_warning_days' => 60,

    // Roles of the company that receive the alerts.
    'alert_roles' => ['owner', 'admin', 'accountant'],
];
