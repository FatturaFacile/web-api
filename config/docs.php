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

];
