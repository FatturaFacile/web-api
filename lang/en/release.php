<?php

declare(strict_types=1);

return [

    'title' => 'Release :version production readiness',

    'status' => [
        'ok' => 'OK',
        'warning' => 'WARNING',
        'fail' => 'FAIL',
    ],

    'checks' => [
        'environment' => 'Environment is production (APP_ENV=:value)',
        'debug' => 'Debug mode is off (APP_DEBUG)',
        'key' => 'Application key is set (APP_KEY)',
        'queue' => 'Queue connection is asynchronous (QUEUE_CONNECTION=:value)',
        'cache' => 'Cache store is shared between processes (CACHE_STORE=:value)',
        'scheduler' => 'Scheduler has the nightly backup (tenants:backup --all)',
        'backup_disk' => 'Backup disk is configured (:disk)',
        'storage' => 'Storage directories are writable',
        'database' => 'Database is reachable and migrated',
        'docs' => 'OpenAPI specification is generated (docs/api/openapi.json)',
        'config_cache' => 'Configuration is cached (php artisan config:cache)',
        'route_cache' => 'Routes are cached (php artisan route:cache)',
    ],

    'summary' => ':failed failed, :warnings warning(s), :ok passed.',
    'ready' => 'Ready for release.',
    'not_ready' => 'Not ready: fix the failed checks before deploying.',

];
