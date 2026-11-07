<?php

declare(strict_types=1);

namespace Modules\Customs\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Parameters and responses shared by the customs endpoints.
 */
#[OA\Tag(name: 'Customs', description: 'Swiss customs declarations, assessment decisions and forwarding dossiers')]
#[OA\Parameter(parameter: 'CustomsRecordId', name: 'id', description: 'Record identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'CustomsMasterDataType', name: 'type', description: 'The code table', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['tariff-numbers', 'statistical-keys', 'packaging-types', 'countries', 'document-types']))]
#[OA\Parameter(parameter: 'CustomsPortalToken', name: 'token', description: 'Customer portal magic-link token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
#[OA\Response(response: 'CustomsUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'CustomsForbidden', description: 'The role of the user in the company does not allow this', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'CustomsNotFound', description: 'Record not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'CustomsUnprocessable', description: 'Validation error or customs rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Response(response: 'CustomsChannelError', description: 'The customs channel refused, failed or is not configured', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
final class CustomsOpenApiComponents {}
