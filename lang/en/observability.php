<?php

declare(strict_types=1);

return [
    'messages' => [
        'listed' => 'Records retrieved successfully.',
        'loaded' => 'Record retrieved successfully.',
        'saved' => 'The observability settings were saved.',
        'probed' => 'The institutional connectors were checked.',
    ],
    'errors' => [
        'unknown_connector_title' => 'Unknown connector',
        'unknown_connector' => 'The institutional connector does not exist.',
    ],
    'hints' => [
        'not_postgres' => 'pg_stat_statements is available only on PostgreSQL; this environment uses another database engine.',
        'extension_missing' => 'The pg_stat_statements extension is not enabled. Add pg_stat_statements to shared_preload_libraries, restart PostgreSQL and run CREATE EXTENSION pg_stat_statements; in the application database.',
        'permission_denied' => 'The database user cannot read pg_stat_statements. Grant the pg_read_all_stats role to the application user.',
    ],
];
