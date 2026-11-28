<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Mod 299 — Adventure parks, climbing centres and educational farms
|--------------------------------------------------------------------------
| Operating defaults of the timed-slot booking, the PPE register (UNI EN 15567 / EN 365) and the school trips. A company can
| override the tunable values with its own row of adv_park_configs; the size table and the checklist keys are reference data
| (the labels live in the translation dictionaries, never here).
*/
return [
    // Minutes a harness stays out of the stock before the slot starts and after it ends (fitting, return and visual check).
    'buffer_minutes' => 15,

    // Colours of the wristbands, handed out in turn to the slots of a day.
    'wristband_palette' => ['RED', 'BLUE', 'GREEN', 'YELLOW', 'ORANGE', 'PURPLE', 'PINK', 'WHITE'],

    // One accompanying teacher travels free every N paying students, plus one support teacher for every student with a disability.
    'teacher_free_ratio' => 15,
    'teacher_free_per_disabled' => 1,

    // Seats of the picnic area per visit day.
    'picnic_capacity' => 60,

    // VAT rate of the school workshops invoiced to the public administration.
    'school_vat_rate' => '10',

    // Version of the legal text of the liability waiver the visitors sign (rows of adv_waiver_templates).
    'waiver_version_id' => 'v1',
    'waiver_locales' => ['it', 'en', 'de', 'fr'],

    // Public waiver signing: maximum size of the signature image (characters of the data URI).
    'signature_max_length' => 700000,

    // Months between two annual inspections (EN 365).
    'annual_inspection_months' => 12,
    // Days before a deadline the alerts start to warn.
    'alert_days' => 30,

    // Size table of the harnesses. The recommendation takes the smallest size whose maxima cover the visitor, XL otherwise.
    'sizes' => [
        'BABY' => ['min_height_cm' => 80, 'max_height_cm' => 110, 'min_weight_kg' => 10, 'max_weight_kg' => 25],
        'JUNIOR' => ['min_height_cm' => 110, 'max_height_cm' => 140, 'min_weight_kg' => 20, 'max_weight_kg' => 45],
        'ADULT' => ['min_height_cm' => 140, 'max_height_cm' => 190, 'min_weight_kg' => 40, 'max_weight_kg' => 100],
        'XL' => ['min_height_cm' => 170, 'max_height_cm' => 230, 'min_weight_kg' => 90, 'max_weight_kg' => 160],
    ],

    // Daily opening check of the courses (UNI EN 15567-2): keys map to the translation keys adventure_parks.daily_check.<key>.
    'daily_check_items' => [
        'cables_tension', 'anchor_points', 'platforms_decking', 'safety_nets', 'belay_lines', 'signage', 'first_aid_kit',
        'rescue_equipment', 'weather_conditions', 'communication_radios',
    ],

    // Annual inspection of a PPE (EN 365): keys map to adventure_parks.annual_check.<key>.
    'annual_check_items' => [
        'webbing_wear', 'stitching_integrity', 'buckles_function', 'labels_legible', 'hardware_corrosion', 'lanyard_condition',
        'energy_absorber_intact', 'load_test',
    ],

    // Slots created for a new park (time ranges, one colour each).
    'default_slots' => [
        ['start_time' => '09:30', 'end_time' => '11:30'],
        ['start_time' => '12:00', 'end_time' => '14:00'],
        ['start_time' => '14:30', 'end_time' => '16:30'],
        ['start_time' => '17:00', 'end_time' => '19:00'],
    ],

    // Educational farm catalogue: code => [duration (minutes), max participants, price per student].
    'default_workshops' => [
        'BREAD_GRAIN' => ['duration_minutes' => 120, 'max_participants' => 30, 'price_per_student' => '8.00'],
        'BEES' => ['duration_minutes' => 90, 'max_participants' => 25, 'price_per_student' => '7.00'],
        'GARDEN' => ['duration_minutes' => 90, 'max_participants' => 30, 'price_per_student' => '6.00'],
        'JUNIOR_ADVENTURE' => ['duration_minutes' => 120, 'max_participants' => 20, 'price_per_student' => '12.00'],
    ],
];
