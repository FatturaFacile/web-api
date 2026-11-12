<?php

declare(strict_types=1);

return [
    'payment_export' => [
        'invoices_missing' => 'Some supplier invoices do not exist.',
        'currency_mismatch' => 'The invoices of a payment file must share one currency.',
        'no_bank_account' => 'No active bank account in :currency to debit.',
        'nothing_to_pay' => 'Invoice :number has nothing to pay.',
        'not_approved' => 'Invoice :number is not approved for payment.',
        'supplier_no_iban' => 'Supplier of invoice :number has no IBAN.',
    ],
    'reconciliation' => [
        'line_already_reconciled' => 'Bank statement line is already reconciled.',
    ],
    'open_banking' => [
        'consent_inactive' => 'This consent is no longer active.',
    ],
];
