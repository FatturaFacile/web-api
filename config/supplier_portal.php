<?php

declare(strict_types=1);

return [

    /*
    | Days a personal link of the supplier portal stays valid, and the address of the portal the link points to. The
    | token itself is only ever in the mail and travels in the X-Supplier-Token header.
    */
    'token_days' => (int) env('SUPPLIER_PORTAL_TOKEN_DAYS', 30),

    'portal_url' => env('SUPPLIER_PORTAL_URL', env('APP_URL', 'http://localhost').'/supplier-portal'),

    /*
    | Qualification bands on the weighted score (0 to 100): at or above "qualified" the supplier is qualified, at or
    | above "conditional" it is qualified with conditions, below it the assessment is not passed.
    */
    'qualification' => [
        'qualified_min_score' => '80.00',
        'conditional_min_score' => '60.00',
    ],

    /*
    | Days before an expiry at which an extra alert is sent, besides the alert of the requirement and the one of the day
    | the document expires.
    */
    'final_alert_days' => 7,

    /*
    | Anti-fraud rule for bank detail changes: how many different approvers must approve before the IBAN is changed.
    */
    'bank_change_approvals' => 2,

    'upload_max_kilobytes' => 10240,

    'documents_disk' => env('SUPPLIER_PORTAL_DOCUMENTS_DISK', 'local'),

];
