<?php

declare(strict_types=1);

return [
    /*
    | Chart-of-accounts codes of the accrual and settlement entries, used when a contract has no account of its own.
    | expense: "Premi e sconti passivi su vendite" / Rückvergütungen und Erlösminderungen; provision: "Fatture e note di credito da emettere" /
    | Rückstellung für Rückvergütungen; payable: trade payables for the promotional invoices of the retailer; vat: recoverable input VAT.
    */
    'accounts' => [
        'expense' => env('GDO_ACCOUNT_EXPENSE', '6890'),
        'provision' => env('GDO_ACCOUNT_PROVISION', '2290'),
        'payable' => env('GDO_ACCOUNT_PAYABLE', '2210'),
        'vat' => env('GDO_ACCOUNT_VAT', '1170'),
    ],

    /*
    | Standard VAT rate proposed for the credit notes and promotional invoices, by jurisdiction of the contract (percent).
    */
    'tax_rates' => [
        'IT' => env('GDO_TAX_RATE_IT', '22'),
        'CH' => env('GDO_TAX_RATE_CH', '8.1'),
    ],

    /*
    | Variance (percent of the earned amount) up to which the promotional invoice of the retailer is accepted without a dispute,
    | when the contract does not set its own tolerance.
    */
    'tolerance_percent' => env('GDO_TOLERANCE_PERCENT', '2'),
];
