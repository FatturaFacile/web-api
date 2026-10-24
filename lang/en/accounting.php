<?php

declare(strict_types=1);

return [

    'messages' => [
        'ledger_retrieved' => 'General ledger retrieved successfully',
        'vendor_aging_retrieved' => 'Vendor aging report retrieved successfully',
    ],

    'errors' => [
        'invalid_iban' => 'The key :iban is not a valid IBAN.',
    ],

    'aging' => [
        'buckets' => [
            'overdue' => 'Overdue',
            'days_0_30' => 'Due within 30 days',
            'days_31_60' => 'Due in 31 to 60 days',
            'days_61_90' => 'Due in 61 to 90 days',
            'days_over_90' => 'Due in over 90 days',
        ],
    ],

    'accounts' => [
        'receivables' => 'Trade receivables',
        'payables' => 'Trade payables',
        'revenue' => 'Sales revenue',
        'output_vat' => 'Output VAT on sales',
        'cost' => 'Purchases of goods and services',
        'input_vat' => 'Input VAT on purchases',
        'bank' => 'Bank',
        'rounding' => 'Rounding and write-offs',
        'tax_payment' => 'Tax payments (F24)',
        'payroll' => 'Payroll',
        'bank_expense' => 'Bank charges and fees',
        'generic_expense' => 'General expenses',
        'transfer_clearing' => 'Bank transfers clearing',
        'other' => 'Account',
        'bank_account' => 'Bank :iban',
    ],

    'categories' => [
        'tax_payment' => 'Tax payment',
        'payroll' => 'Payroll',
        'bank_expense' => 'Bank expense',
        'internal_transfer' => 'Internal transfer',
        'generic_expense' => 'Expense',
    ],

    'descriptions' => [
        'receivable' => 'Invoice :number',
        'revenue' => 'Revenue of invoice :number',
        'output_vat' => 'Output VAT of invoice :number',
        'cost' => 'Costs of supplier invoice :number',
        'input_vat' => 'Input VAT of supplier invoice :number',
        'payable' => 'Supplier invoice :number',
        'receipt' => 'Receipt for invoice :number',
        'supplier_payment' => 'Payment of supplier invoice :number',
        'bank_fee' => 'Bank fee on invoice :number',
        'bank_fee_line' => 'Bank fee',
        'write_off' => 'Write-off on invoice :number',
        'rounding' => 'Rounding difference',
    ],

];
