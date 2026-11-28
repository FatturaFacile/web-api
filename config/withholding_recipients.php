<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Mod 356 — Withholding taxes on commissions, royalties, occasional work and amateur sports (IT / CH)
|--------------------------------------------------------------------------
| Statutory parameters of the calculators. Rates are percentages written as strings, amounts are plain decimals in the
| currency of the recipient (EUR for IT, CHF for CH). They are reference values to be reviewed every January against the
| INPS, Enasarco and AFC circulars; a tenant never edits them from the UI.
*/
return [
    // Progressive IRPEF brackets (upper bound of the taxable amount, rate). null = no upper bound.
    'irpef_brackets' => [
        ['up_to' => '28000', 'rate' => '23'],
        ['up_to' => '50000', 'rate' => '33'],
        ['up_to' => null, 'rate' => '43'],
    ],

    'agent' => [
        'withholding_rate' => '23',
        // Art. 25-bis D.P.R. 600/1973: the withholding applies on 50% of the commission, or on 20% when the agent
        // certifies the continuous use of employees or third parties.
        'base_percent' => '50',
        'reduced_base_percent' => '20',
        'enasarco' => [
            'agent_rate' => '8.75',
            'company_rate' => '8.75',
            'ceiling' => ['mono' => '46000', 'pluri' => '28000'],
            'annual_minimum' => ['mono' => '878', 'pluri' => '439'],
        ],
        // FIRR accrual: progressive brackets of the yearly commissions (upper bound, rate).
        'firr_brackets' => [
            ['up_to' => '12500', 'rate' => '4'],
            ['up_to' => '20000', 'rate' => '2'],
            ['up_to' => '32000', 'rate' => '1'],
            ['up_to' => null, 'rate' => '0'],
        ],
        // The declaration must be received by 31 December of the year before the one it covers.
        'declaration_deadline_month_day' => '12-31',
    ],

    'author_royalty' => [
        'withholding_rate' => '20',
        'young_age_limit' => 35,
        'young_expense_percent' => '40',
        'standard_expense_percent' => '25',
        'non_taxable_code' => '22',
        'cu_causal' => 'B',
    ],

    'occasional' => [
        'withholding_rate' => '20',
        'stamp_threshold' => '77.47',
        'stamp_amount' => '2.00',
        'inps_threshold' => '5000',
        // INPS Gestione Separata, collaborators without other cover. Year => rate; the highest year not above the
        // payment year applies.
        'inps_rate' => ['2024' => '33.72', '2025' => '33.72', '2026' => '33.72'],
        'inps_worker_share' => '33.333333', // one third worker, two thirds client
        'cu_causal' => 'M',
        'f24_inps_code' => 'C10',
    ],

    'amateur_sports' => [
        'exempt_threshold' => '5000',
        'irpef_threshold' => '15000',
        'inps_base_percent_reduced' => '50',
        'inps_reduction_until_year' => 2027,
        'non_taxable_code' => '24',
        'cu_causal' => 'M',
    ],

    'swiss' => [
        'avs_rate' => '10.6', // AVS / AI / IPG, half employee, half employer
        'de_minimis' => '2300',
        // Federal flat-rate acquisition expenses of non-resident artists, athletes and speakers (Art. 92 LIFD).
        'flat_expense_percent' => ['artist' => '50', 'athlete' => '20', 'speaker' => '20'],
        // Source tax on the daily taxable amount (after expenses): upper bound, rate.
        'source_tax_brackets' => [
            ['up_to' => '200', 'rate' => '0'],
            ['up_to' => '1000', 'rate' => '5'],
            ['up_to' => '3000', 'rate' => '10'],
            ['up_to' => '5000', 'rate' => '15'],
            ['up_to' => null, 'rate' => '20'],
        ],
    ],

    'f24' => [
        'self_employed_code' => '1040',
        'agent_code' => '1038',
        'due_day' => 16,
    ],

    // CU ministerial causal of each regime (the agent causal depends on the Enasarco mandate: Q mono, R pluri).
    'cu_causals' => ['A', 'M', 'M1', 'B', 'N', 'Q', 'R', 'V'],

    // The INL communication (Art. 14 D.Lgs. 81/2008) must reach the labour inspectorate before the work starts.
    'inl' => [
        'recipient_email' => 'comunicazioni.occasionali@pec.ispettorato.gov.it',
    ],
];
