<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Ski and snowboard schools, alpine guides and outdoor centres (Mod 286)
|--------------------------------------------------------------------------
| Working assumptions of the vertical, to be reviewed each season with the school's tax adviser: the lesson slots, the default
| secretariat retention, the VAT of the add-ons sold with a lesson and the legal wording of the VAT exemptions.
*/
return [
    // Lesson slot lengths in minutes (30 minute taster / kids slot, 55 minute hour with a five minute change over).
    'slot_minutes' => [30, 55],

    // A lesson may span up to this many consecutive slots.
    'max_slots_per_lesson' => 8,

    // The planning board shows slots between these two times of day.
    'board' => ['day_start' => '08:00', 'day_end' => '17:30'],

    // Default retention of the school secretariat on the gross fee of an instructor.
    'retention' => ['mode' => 'PERCENT', 'value' => 10.0],

    // Price of a private lesson per hour (net) when the booking does not state one (public portal), and of a group course per day.
    'lesson_hourly_price' => 90.0,
    'group_course_day_price' => 60.0,

    // Net unit price per day of the add-ons sold on the public portal (the portal never accepts a price from the client).
    'addon_prices' => [
        'RENTAL_SKI_KIT' => 35.0, 'RENTAL_SNOWBOARD_KIT' => 38.0, 'RENTAL_BOOTS' => 15.0, 'RENTAL_HELMET' => 8.0,
        'ARTVA_KIT' => 25.0, 'SKIPASS' => 55.0, 'OTHER' => 0.0,
    ],

    // Rental and skipass are not instruction: they are taxable. Rates are working assumptions (IT ordinary / reduced, CH standard).
    'vat_rates' => [
        'IT' => ['rental' => 22.0, 'skipass' => 10.0, 'other' => 22.0],
        'CH' => ['rental' => 8.1, 'skipass' => 3.8, 'other' => 8.1],
    ],

    // VAT exemption of sports instruction and educational activities.
    'exemptions' => [
        'IT' => ['nature' => 'N4', 'legal_reference' => 'Art. 10 n. 20 DPR 633/72'],
        'CH' => ['nature' => null, 'legal_reference' => 'Art. 21 cpv. 2 n. 11 LIVA'],
    ],

    // The public booking portal answers at most this many requests per minute and IP (see the route throttle).
    'public_throttle' => '30,1',

    // Colours of the default technical levels (see SkiLevelCatalogue).
    'levels' => [
        'SNOW_LEAGUE' => [
            ['code' => 'SNOWLI', 'color' => '#7ec8e3'],
            ['code' => 'BLUE_PRINCE', 'color' => '#1e88e5'],
            ['code' => 'BLUE_KING', 'color' => '#1565c0'],
            ['code' => 'BLUE_STAR', 'color' => '#0d47a1'],
            ['code' => 'RED_PRINCE', 'color' => '#ef5350'],
            ['code' => 'RED_KING', 'color' => '#d32f2f'],
            ['code' => 'RED_STAR', 'color' => '#b71c1c'],
            ['code' => 'BLACK_PRINCE', 'color' => '#616161'],
            ['code' => 'BLACK_KING', 'color' => '#424242'],
            ['code' => 'BLACK_STAR', 'color' => '#212121'],
        ],
        'FISI_AMSI' => [
            ['code' => 'BRONZE', 'color' => '#cd7f32'],
            ['code' => 'SILVER', 'color' => '#b0b7bd'],
            ['code' => 'GOLD', 'color' => '#d4af37'],
        ],
    ],
];
