<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Marketplace limits
|--------------------------------------------------------------------------
|
| Limits of the connector installations. The rate limit of a connector is a property of its definition; a company can
| lower it (never raise it above the maximum of the definition) on its own installation.
|
*/

return [
    // Installations of the same connector one company can have (two shops, two payment accounts).
    'max_installations_per_connector' => 5,
    // Seconds a request to an external system can take before the health check or the sync gives up.
    'http_timeout_seconds' => (int) env('MARKETPLACE_HTTP_TIMEOUT', 10),
    // Health checks kept for each installation.
    'health_checks_keep' => 50,
    // Minutes after which a run that never finished no longer blocks the next one.
    'stale_run_minutes' => 15,
];
