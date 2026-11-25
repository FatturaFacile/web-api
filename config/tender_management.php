<?php

declare(strict_types=1);

/*
| Tender management (Mod 169, Issue #415).
|
| The figures below are SAMPLE values for demonstration and tests: the reductions of the provisional guarantee (Art. 106
| D.Lgs. 36/2023), the ANAC contribution brackets (published yearly by ANAC resolution) and the SOA renewal milestones must be
| reviewed by the company against the current legal texts before being relied upon.
*/
return [
    /*
    | Provisional bid bond: base percentage of the base auction amount (1 % or 2 %, set by the contracting authority) and the
    | cumulative Art. 106 reductions. The reductions multiply (a 50 % then a 30 % cut leaves 35 %). ISO 14001 and EMAS are
    | alternatives: only the larger of the two applies.
    */
    'bid_bond' => [
        'allowed_base_percentages' => ['1.00', '2.00'],
        'default_base_percentage' => '2.00',
        'reductions' => [
            'pmi' => '0.50',
            'iso9001' => '0.30',
            'emas' => '0.30',
            'iso14001' => '0.20',
            'pdr125' => '0.10',
        ],
    ],

    /*
    | ANAC tender contribution paid by the economic operator, by value bracket [upper bound exclusive, fee]. Below the first
    | bracket nothing is due.
    */
    'anac_contribution' => [
        'brackets' => [
            ['below' => '150000', 'fee' => '0.00'],
            ['below' => '300000', 'fee' => '35.00'],
            ['below' => '500000', 'fee' => '80.00'],
            ['below' => '800000', 'fee' => '125.00'],
            ['below' => '1000000', 'fee' => '165.00'],
            ['below' => '2000000', 'fee' => '200.00'],
            ['below' => '5000000', 'fee' => '250.00'],
            ['below' => '20000000', 'fee' => '500.00'],
            ['below' => null, 'fee' => '800.00'],
        ],
    ],

    /*
    | Scoring: share of the technical and economic maximum, the bilinear break-point factor and the share of the maximum
    | scores above which an offer is suspected anomalous (Art. 110 D.Lgs. 36/2023: four fifths of both maxima).
    */
    'scoring' => [
        'technical_max_default' => '70',
        'economic_max_default' => '30',
        'bilinear_factor' => '0.85',
        'anomaly_share' => '0.80',
    ],

    /*
    | Swiss eligibility: the debt-enforcement extract must not be older than this (months), the Logib deviation limit of the
    | federal tenders (percent) and the proof types required of every Swiss bidder.
    */
    'ch' => [
        'betreibungsauszug_max_age_months' => 3,
        'logib_max_deviation_percent' => '5.00',
        'logib_federal_only' => true,
    ],

    /*
    | Italian SOA attestations are valid five years, with a verification after the third.
    */
    'soa' => [
        'validity_years' => 5,
        'intermediate_check_years' => 3,
    ],

    /*
    | Alert milestones. Deadlines raise one alert per milestone (hours before); vault documents at these days before expiry.
    */
    'alerts' => [
        'deadline_offsets_hours' => [168, 72, 24, 4],
        'expiry_offsets_days' => [90, 60, 30, 7, 0],
        'soa_milestone_offsets_days' => [90, 30, 0],
    ],

    'uploads' => [
        'disk' => env('TENDER_UPLOAD_DISK', 'local'),
        'max_kilobytes' => (int) env('TENDER_UPLOAD_MAX_KB', 20480),
    ],
];
