<?php

declare(strict_types=1);

return [
    /*
     * Billable utilization a tenant aims for when it has not set a target for a department or cost centre (percent of the net
     * available hours). Utilization within +/- `target_tolerance` points of the target is reported as on target.
     */
    'default_billable_target' => (float) env('RESOURCE_PLANNING_DEFAULT_BILLABLE_TARGET', 75.0),
    'target_tolerance' => (float) env('RESOURCE_PLANNING_TARGET_TOLERANCE', 5.0),

    /*
     * Fallback working location of an employee that has none recorded: the country of the tenant, and for Switzerland
     * the canton of the Ticino head office (the default of the time tracking calendars).
     */
    'default_swiss_canton' => env('RESOURCE_PLANNING_DEFAULT_CANTON', 'TI'),
];
