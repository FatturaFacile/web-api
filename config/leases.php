<?php

declare(strict_types=1);

return [
    /*
    | Currencies a lease can be written in, and the value of a new underlying asset up to which the low-value
    | exemption (IFRS 16.B3-B8) may be elected, per currency. The threshold is an assessment of the asset when new,
    | not of the contract, and is the same in every standard the module supports.
    */
    'currencies' => ['EUR', 'CHF'],

    'low_value_threshold' => [
        'EUR' => '5000.00',
        'CHF' => '5000.00',
    ],

    /*
    | Leases of 12 months or less (including options reasonably certain to be exercised) can be expensed on a
    | straight-line basis instead of being capitalised (IFRS 16.5).
    */
    'short_term_max_months' => 12,

    /*
    | Longest lease the module measures: a schedule has one row per month.
    */
    'max_term_months' => 600,

    /*
    | Accounts of the lease postings. A contract that names none of its own falls back to these codes, created in the
    | chart of accounts on first use like the other posting services do.
    */
    'accounts' => [
        'rou_asset' => ['code' => '1670', 'name' => 'Right-of-use assets (Diritti d\'uso)', 'type' => 'asset', 'normal_balance' => 'debit'],
        'accumulated_depreciation' => ['code' => '1675', 'name' => 'Accumulated depreciation of right-of-use assets', 'type' => 'asset', 'normal_balance' => 'credit'],
        'lease_liability' => ['code' => '2450', 'name' => 'Lease liabilities (Debiti per leasing)', 'type' => 'liability', 'normal_balance' => 'credit'],
        'lease_clearing' => ['code' => '2460', 'name' => 'Lease settlement clearing (Lessor payables)', 'type' => 'liability', 'normal_balance' => 'credit'],
        'interest_expense' => ['code' => '6850', 'name' => 'Interest on lease liabilities', 'type' => 'expense', 'normal_balance' => 'debit'],
        'depreciation_expense' => ['code' => '6410', 'name' => 'Depreciation of right-of-use assets', 'type' => 'expense', 'normal_balance' => 'debit'],
        'remeasurement_result' => ['code' => '6860', 'name' => 'Lease remeasurement result', 'type' => 'expense', 'normal_balance' => 'debit'],
    ],
];
