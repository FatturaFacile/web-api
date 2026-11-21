<?php

declare(strict_types=1);

/*
| Executive Decision Support System (Mod 093, Issue #214): classification of the ledger accounts into the analytical
| categories of the data mart, thresholds of the anomaly detector and cache lifetime of the read models. Prefixes are
| matched against the account code, the first match wins; accounts of the chart type `revenue` are always revenue.
*/
return [
    'reporting_currencies' => ['CHF', 'EUR'],

    'cache_ttl_seconds' => (int) env('ANALYTICS_DSS_CACHE_TTL', 900),

    'account_prefixes' => [
        'cash' => ['10'],
        'receivable' => ['110', '111'],
        'payable' => ['200', '201'],
        'inventory' => ['12', '13'],
        'cogs' => ['3', '4', '60'],
        'depreciation' => ['68', '69'],
        'interest' => ['78', '79'],
        'tax' => ['89', '98'],
    ],

    // Account types of the chart (chart_of_accounts.type / account_type) and reconciliation types that classify a balance sheet account.
    'receivable_reconciliation_types' => ['customer', 'receivable', 'debtor'],
    'payable_reconciliation_types' => ['vendor', 'supplier', 'payable', 'creditor'],

    'anomaly' => [
        'concentration_threshold_pct' => 25.0,
        'margin_erosion_points' => 3.0,
        'dso_spike_days' => 7.0,
        'budget_overrun_pct' => 10.0,
        'zscore_threshold' => 2.0,
        'zscore_min_points' => 6,
    ],

    'working_hours_per_fte_day' => 8,
    'cost_of_capital_rate' => 0.06,
    'drilldown_max_page_size' => 200,
];
