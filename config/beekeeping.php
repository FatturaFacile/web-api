<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Beekeeping and honey production (Mod 296 / Issue #542)
|--------------------------------------------------------------------------
| Statutory and agronomic parameters. They are data, not code: review them every January. Compensation percentages and the
| Swiss rates are assumptions to be confirmed by the tax advisor of the company.
*/
return [
    // Radius a worker bee forages around the hive (metres); apiaries whose circles overlap share flora and varroa pressure.
    'foraging_radius_m' => 3000,

    // Honey quality (EU Directive 2001/110/EC, Swiss LDAl): moisture in percent, HMF in mg/kg.
    'quality' => [
        'moisture_warning' => 18.0,
        'moisture_limit' => 20.0,
        'hmf_warning' => 30.0,
        'hmf_limit' => 40.0,
    ],

    // Shelf life used to compute the TMC (termine minimo di conservazione) of a jar lot.
    'best_before_months' => 24,

    // Colony alerts.
    'alerts' => [
        'swarm_brood_frames' => 8,
        'swarm_cells_threshold' => 1,
        'weak_strength_score' => 2,
        'queenless_missing_days' => 0,
    ],

    // International queen marking colours by last digit of the year of birth.
    'queen_colors' => [
        1 => 'white', 6 => 'white',
        2 => 'yellow', 7 => 'yellow',
        3 => 'red', 8 => 'red',
        4 => 'green', 9 => 'green',
        5 => 'blue', 0 => 'blue',
    ],

    'jar_formats_g' => [250, 500, 1000],

    'botanical_origins' => ['acacia', 'chestnut', 'linden', 'alpine_rhododendron', 'wildflower', 'citrus', 'sulla', 'eucalyptus', 'honeydew'],

    // Default withdrawal periods (days) proposed by the register for common veterinary products; the veterinarian prescription prevails.
    'treatment_products' => [
        'oxalic_acid_sublimated' => ['substance' => 'oxalic_acid', 'withdrawal_days' => 0],
        'oxalic_acid_trickled' => ['substance' => 'oxalic_acid', 'withdrawal_days' => 0],
        'thymol' => ['substance' => 'thymol', 'withdrawal_days' => 0],
        'formic_acid' => ['substance' => 'formic_acid', 'withdrawal_days' => 0],
        'amitraz' => ['substance' => 'amitraz', 'withdrawal_days' => 30],
        'flumethrin' => ['substance' => 'flumethrin', 'withdrawal_days' => 30],
        'tau_fluvalinate' => ['substance' => 'tau_fluvalinate', 'withdrawal_days' => 30],
        'brood_interruption' => ['substance' => 'biotechnical', 'withdrawal_days' => 0],
    ],

    // Annual overwintering census window (month-day), Italian BDA / VETINFO and Swiss cantonal veterinary office.
    'census' => [
        'IT' => ['start' => '11-01', 'end' => '12-31'],
        'CH' => ['start' => '11-01', 'end' => '12-31'],
    ],

    'tax' => [
        // Art. 34 DPR 633/72: the percentages of compensation of the apiculture products (percent).
        'IT' => [
            'exemption_threshold' => 7000.0,
            'compensation' => [
                'honey' => 8.8,
                'beeswax' => 8.8,
                'propolis' => 8.8,
                'royal_jelly' => 8.8,
                'live_bees' => 8.8,
            ],
            // Ordinary VAT rates when the producer is in the ordinary regime.
            'ordinary' => [
                'honey' => 10.0,
                'beeswax' => 22.0,
                'propolis' => 10.0,
                'royal_jelly' => 10.0,
                'live_bees' => 10.0,
                'non_food' => 22.0,
            ],
        ],
        // Switzerland: reduced rate for food (honey, edible bee products, live animals), standard rate for the rest.
        'CH' => [
            'reduced' => 2.6,
            'standard' => 8.1,
            'reduced_kinds' => ['honey', 'propolis', 'royal_jelly', 'live_bees'],
        ],
    ],
];
