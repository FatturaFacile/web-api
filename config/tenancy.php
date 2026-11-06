<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Request context
    |--------------------------------------------------------------------------
    |
    | Every authenticated route must state who is calling and for which company, through the X-Tenant-Id and
    | X-User-Id headers or the tenant_id and user_id query parameters. Both are checked against the access token.
    |
    */
    'require_request_context' => (bool) env('TENANCY_REQUIRE_REQUEST_CONTEXT', true),
];
