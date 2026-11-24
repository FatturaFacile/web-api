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
        'description' => "REST API of the FatturaFacile ERP.\n\nThe API is served over HTTPS only (plain HTTP is redirected or refused).\n\n**Authentication: session cookie.** From the web application: 1) `GET /sanctum/csrf-cookie` (sets the `XSRF-TOKEN` cookie), 2) `POST /v1/auth/login` with credentials included and the `X-XSRF-TOKEN` header (value of the `XSRF-TOKEN` cookie): the response sets the HttpOnly + Secure session cookie and carries no token, 3) every following request sends the cookie, and every write also sends `X-XSRF-TOKEN`. The request must come from an https origin listed in the Sanctum stateful domains (Origin or Referer header). A missing or wrong CSRF token is a 419.\n\n**Bearer token: optional token mode.** Only when the server enables `auth.token_mode.enabled` (off by default) and the login sends `token_mode: true`, the login returns a Bearer token (`Authorization: Bearer <token>`); with the setting off `token_mode: true` is a 422 and Bearer tokens are not accepted as user credentials. Integration API keys are a separate mechanism.\n\nEvery request except login and the incoming webhooks must also carry the organization it works on (`X-Tenant-Id: <uuid>`). Errors follow RFC 9457 (`application/problem+json`).",
        'contact' => 'FatturaFacile API support',
    ],

    'servers' => [
        'default' => 'API base URL',
    ],

    'security' => [
        'cookie' => 'Default user authentication: the HttpOnly + Secure session cookie set by `POST /v1/auth/login`. Call `GET /sanctum/csrf-cookie` first and send the `XSRF-TOKEN` cookie value in the `X-XSRF-TOKEN` header on every write.',
        'bearer' => 'Only meaningful in the optional token mode (server setting `auth.token_mode.enabled`, off by default): the token returned by `POST /v1/auth/login` when it is called with `token_mode: true`, sent as `Authorization: Bearer <token>`.',
        'tenant' => 'UUID of the organization (tenant) the request works on. The user must be a member of it.',
    ],

    'tags' => [
        'default' => 'Operations of the :tag module.',
    ],

    'postman' => [
        'description' => 'Postman collection of the FatturaFacile ERP API. Set the baseUrl and frontendUrl variables, then run Auth > login: the collection fetches the CSRF cookie itself and sends Origin, Referer and X-XSRF-TOKEN; the session cookie is kept by Postman and the active tenant in tenantId. authToken is used only in the optional token mode (login with token_mode true).',
        'folders' => [
            'auth' => 'Auth',
            'invoicing' => 'Invoicing',
            'banking' => 'Banking',
            'accounting' => 'Accounting',
            'backups' => 'Backups',
        ],
    ],

];
