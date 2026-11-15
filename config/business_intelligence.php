<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Business intelligence limits
|--------------------------------------------------------------------------
|
| Limits of the dashboards, pivots and snapshots built on the semantic layer of the Analytics module. The row and time
| limits of the queries themselves are those of config/analytics.php (`reporting`).
|
*/

return [
    // Rows a widget or a pivot takes from a query; the result says when it was cut.
    'widget_max_rows' => (int) env('BI_WIDGET_MAX_ROWS', 1000),
    // Calculated measures a company can define on one dataset.
    'max_calculated_measures_per_dataset' => 25,
    // Snapshots kept for each saved query or dashboard; older ones are removed when a new one is taken.
    'snapshots_keep' => (int) env('BI_SNAPSHOTS_KEEP', 60),
    // Largest number of cells (rows x columns) a pivot returns.
    'pivot_max_cells' => 5000,
];
