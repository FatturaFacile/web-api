<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Self-service reporting limits
|--------------------------------------------------------------------------
|
| Hard limits of the report designer (semantic layer), its exports and the scheduled deliveries. They protect the
| database: a report never returns more than max_rows rows and a query that runs longer than timeout_ms is cancelled.
|
*/

return [
    'reporting' => [
        // Rows a report can ever return or export, whatever the page size or the top-N asked for.
        'max_rows' => (int) env('ANALYTICS_REPORT_MAX_ROWS', 10000),
        // Largest page of the run endpoint.
        'max_per_page' => 500,
        // PostgreSQL statement timeout of every report query, in milliseconds.
        'timeout_ms' => (int) env('ANALYTICS_REPORT_TIMEOUT_MS', 15000),
        // Days a stored export file stays reachable through its signed link.
        'link_ttl_days' => (int) env('ANALYTICS_EXPORT_LINK_TTL_DAYS', 7),
        // Scheduled exports go only to members of the company unless this is on (an external accountant, for example).
        'allow_external_recipients' => (bool) env('ANALYTICS_EXPORT_ALLOW_EXTERNAL_RECIPIENTS', false),
        // Largest period of a report, in days.
        'max_period_days' => 1830,
    ],

    // KPIs materialised every night in analytics_kpi_snapshots.
    'snapshot_kpis' => ['sales', 'purchases', 'margin', 'receivables-aging', 'payables-aging', 'stock-value'],
];
