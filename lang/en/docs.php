<?php

declare(strict_types=1);

return [

    'ui' => [
        'title' => 'FatturaFacile ERP API documentation',
    ],

    'errors' => [
        'disabled' => 'The API documentation is not available.',
        'spec_missing' => 'The OpenAPI specification has not been generated yet. Run: php artisan docs:export',
    ],

    'export' => [
        'yaml_missing' => 'The symfony/yaml package is required to export the documentation (install the development dependencies).',
        'generation_failed' => 'The OpenAPI specification could not be generated.',
        'done' => 'Documentation exported (:paths paths) to :directory.',
    ],

    'info' => [
        'title' => 'FatturaFacile ERP API',
        'summary' => 'Multi-tenant ERP API for invoicing, purchasing, inventory, banking, accounting and operations.',
        'description' => "REST API of the FatturaFacile ERP.\n\nEvery request except login and the incoming webhooks must carry a bearer token (`Authorization: Bearer <token>`) and the organization it works on (`X-Tenant-ID: <uuid>`). Errors follow RFC 9457 (`application/problem+json`).",
        'contact' => 'FatturaFacile API support',
    ],

    'servers' => [
        'default' => 'API base URL',
    ],

    'security' => [
        'bearer' => 'Access token returned by `POST /v1/auth/login`, sent as `Authorization: Bearer <token>`.',
        'tenant' => 'UUID of the organization (tenant) the request works on. The user must be a member of it.',
    ],

    'tags' => [
        'default' => 'Operations of the :tag module.',
    ],

    'postman' => [
        'description' => 'Postman collection of the FatturaFacile ERP API. Set the baseUrl variable, then run Auth > login: it stores authToken and tenantId for all other requests.',
        'folders' => [
            'auth' => 'Auth',
            'invoicing' => 'Invoicing',
            'banking' => 'Banking',
            'accounting' => 'Accounting',
            'backups' => 'Backups',
        ],
    ],

];
