<?php

declare(strict_types=1);

namespace App\OpenApi\Components;

use App\OpenApi\Processors\DocumentRequestContext;
use OpenApi\Attributes as OA;

/**
 * Reusable components of the request context that every tenant-scoped operation must declare. The references are added
 * to the operations by {@see DocumentRequestContext}.
 */
#[OA\Parameter(
    parameter: 'XTenantId',
    name: 'X-Tenant-Id',
    description: 'Identifier of the active tenant. The `tenant_id` query parameter is accepted as an alternative.',
    in: 'header',
    required: true,
    schema: new OA\Schema(type: 'string', format: 'uuid')
)]
#[OA\Parameter(
    parameter: 'XUserId',
    name: 'X-User-Id',
    description: 'Identifier of the authenticated user: it must match the access token. The `user_id` query parameter is accepted as an alternative.',
    in: 'header',
    required: true,
    schema: new OA\Schema(type: 'string')
)]
#[OA\Parameter(
    parameter: 'TenantIdQuery',
    name: 'tenant_id',
    description: 'Query alternative to the `X-Tenant-Id` header. The header wins when both are sent.',
    in: 'query',
    required: false,
    schema: new OA\Schema(type: 'string', format: 'uuid')
)]
#[OA\Parameter(
    parameter: 'UserIdQuery',
    name: 'user_id',
    description: 'Query alternative to the `X-User-Id` header. The header wins when both are sent.',
    in: 'query',
    required: false,
    schema: new OA\Schema(type: 'string')
)]
#[OA\Response(
    response: 'MissingRequestContext',
    description: 'Missing request context: tenant_id or user_id was not sent, neither as header nor as query parameter',
    content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/ApiProblemDetailsResponse'))
)]
#[OA\Response(
    response: 'ForbiddenRequestContext',
    description: 'Forbidden: the declared user_id differs from the access token, or the user is not authorized on the tenant',
    content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/ApiProblemDetailsResponse'))
)]
class RequestContextComponents {}
