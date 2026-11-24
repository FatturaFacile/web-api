<?php

declare(strict_types=1);

return [
    'version' => '2.0.0',
    'rfc9562_uuid_version' => 7,
    'strict_tenancy' => true,
    'audit' => [
        'enabled' => env('ERP_AUDIT_ENABLED', true),
        'async' => env('ERP_AUDIT_ASYNC', false),
    ],
];
