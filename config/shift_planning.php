<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Shift planning (Mod 073)
|--------------------------------------------------------------------------
|
| The Swiss surcharges are fixed by the Labour Act and live in ChLaborComplianceStrategy. The Italian ones are set by the
| collective agreement (CCNL) of each company: these are the common defaults, overridden per deployment. Percent of the
| hourly cost on the minutes that attract the supplement.
|
*/

return [
    'italy' => [
        'night_surcharge_percent' => (float) env('SHIFT_PLANNING_IT_NIGHT_PERCENT', 25),
        'sunday_surcharge_percent' => (float) env('SHIFT_PLANNING_IT_SUNDAY_PERCENT', 30),
        'holiday_surcharge_percent' => (float) env('SHIFT_PLANNING_IT_HOLIDAY_PERCENT', 50),
    ],
];
