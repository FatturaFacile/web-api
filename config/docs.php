<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Interactive API documentation
    |--------------------------------------------------------------------------
    |
    | `GET /api/documentation` shows the OpenAPI specification stored in `spec_path` with Scalar. Turn it off with
    | DOCS_ENABLED=false when the documentation must not be public.
    |
    */

    'enabled' => (bool) env('DOCS_ENABLED', true),

    'spec_path' => base_path('docs/api/openapi.json'),

    /*
    |--------------------------------------------------------------------------
    | Who reads the documentation
    |--------------------------------------------------------------------------
    |
    | With require_auth on, the Swagger UI and the raw OpenAPI files answer only a signed-in platform administrator or a
    | user whose email is in allowed_emails (comma separated in DOCS_ALLOWED_EMAILS). It is on by default in production
    | and off elsewhere (local, testing, staging), where the documentation stays open.
    |
    */

    'require_auth' => (bool) env('DOCS_REQUIRE_AUTH', env('APP_ENV', 'production') === 'production'),

    'allowed_emails' => array_values(array_filter(array_map(
        static fn (string $email): string => strtolower(trim($email)),
        explode(',', (string) env('DOCS_ALLOWED_EMAILS', '')),
    ))),

];
