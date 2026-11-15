<?php

declare(strict_types=1);

return [
    'errors' => [
        'forfettario_incompatible' => 'Withholding tax, split payment and reverse charge are not allowed under the flat-rate regime (RF19).',
        'enasarco_rate_not_configured' => 'No ENASARCO rate is configured for :year.',
        'split_payment_incompatible' => 'Split payment cannot be combined with reverse charge.',
        'split_payment_requires_vat' => 'Split payment requires at least one line with VAT.',
        'invalid_natura' => 'The natura code :natura is not valid.',
        'amount_out_of_range' => 'An amount is too large to be calculated.',
        'invalid_quarter' => 'The quarter must be between 1 and 4.',
    ],

    'warnings' => [
        'zero_rate_without_natura' => 'Line :line has a 0% VAT rate without a natura code.',
        'split_payment_customer' => 'Split payment is usually reserved for public administrations and listed companies; check the customer.',
    ],

    'messages' => [
        'preview' => 'Fiscal preview calculated successfully',
        'stamp_duty_report' => 'Stamp duty report retrieved successfully',
        'withholding_report' => 'Withholding report retrieved successfully',
        'enasarco_status' => 'ENASARCO status retrieved successfully',
    ],
];
