<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Dairy, cooperative creamery and alpine pasture vertical (Mod 298 / Issue #544)
|--------------------------------------------------------------------------
| Reference values of the module. Every figure is a default that a deployment can change here; the legal ones (VAT rates,
| declaration deadlines, hygiene limits) must be checked against the current rules before production use.
*/
return [
    // Raw cow milk density (kg per liter) used when a delivery does not state it.
    'default_density' => 1.032,

    // Hygiene / anomaly thresholds of the laboratory results (Reg. CE 853/2004, annex III, section IX).
    'quality_limits' => [
        'scc_cells_ml' => 400000,
        'tbc_cfu_ml' => 100000,
        // A freezing point warmer than this suggests added water.
        'freezing_point_c' => -0.505,
    ],

    // VAT rate (percent) applied to the settlement of a breeder, by the jurisdiction of the breeder.
    'vat_rate_percent' => ['IT' => 10.0, 'CH' => 2.6],
    'currency' => ['IT' => 'EUR', 'CH' => 'CHF'],

    // Invoice numbering prefix by billing document type of a settlement.
    'document_prefix' => ['self_billing' => 'AF-LATTE', 'credit_note' => 'NC-LATTE', 'invoice' => 'FA-LATTE'],
    'document_payment_terms_days' => 30,

    // Day of the following month by which each declaration is due.
    'filing_due_day' => ['dbmilch' => 15, 'sian' => 10],

    // Recurring care operations generated when a vat batch is completed: care type => days after the production date, and the
    // repeat interval in days after each execution (0 = once).
    'care_schedule' => [
        'brining' => ['first_after_days' => 1, 'repeat_every_days' => 0],
        'turning' => ['first_after_days' => 14, 'repeat_every_days' => 14],
        'brushing' => ['first_after_days' => 30, 'repeat_every_days' => 30],
    ],

    // Highest number of wheels a single vat batch may produce (guards the plaque range).
    'max_wheels_per_batch' => 500,

    // Import size cap in kilobytes.
    'import_max_kb' => 5120,

    // Months used to compute the age of a wheel (average month length in days).
    'days_per_month' => 30.4375,
];
