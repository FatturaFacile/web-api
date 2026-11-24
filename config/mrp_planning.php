<?php

declare(strict_types=1);

/*
| MRP I & II (Mod 063).
|
| default_customs_days: customs clearance days (sdoganamento) added to the lead time of a bought item whose supplier lives
| on the other side of the Swiss border, when an item has no customs_clearance_days of its own.
|
| shutdown_defaults: yearly closures applied to every supplier of the country (month-day ranges, inclusive; a range may
| cross the new year). Tenants add their own periods in mrp_shutdown_periods.
*/
return [
    'default_customs_days' => 2,

    'shutdown_defaults' => [
        'IT' => [
            ['label' => 'Ferragosto', 'from' => '08-10', 'to' => '08-20'],
        ],
        'CH' => [
            ['label' => 'Betriebsferien (summer)', 'from' => '07-20', 'to' => '08-09'],
            ['label' => 'Betriebsferien (winter)', 'from' => '12-24', 'to' => '01-02'],
        ],
    ],
];
