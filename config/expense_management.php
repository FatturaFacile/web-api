<?php

declare(strict_types=1);

return [
    /*
    | Where receipt files are kept: "local" (private disk) or "dms" (documents of Mod 007, kept locally too for the previewer).
    */
    'receipts' => [
        'store' => env('EXPENSE_RECEIPT_STORE', 'local'),
        'max_kilobytes' => (int) env('EXPENSE_RECEIPT_MAX_KB', 10240),
    ],

    /*
    | Ledger accounts of the posting hook (ExpenseReportPostedEvent) when a category has no default account.
    */
    'accounts' => [
        'default_expense' => env('EXPENSE_ACCOUNT_DEFAULT', '6900'),
        'per_diem' => env('EXPENSE_ACCOUNT_PER_DIEM', '6910'),
        'recoverable_vat' => env('EXPENSE_ACCOUNT_RECOVERABLE_VAT', '1170'),
        'employee_payable' => env('EXPENSE_ACCOUNT_EMPLOYEE_PAYABLE', '2230'),
    ],
];
