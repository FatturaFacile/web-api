<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Shared OpenAPI parameters and responses. They live apart from the schemas: swagger-php drops every schema of a class
 * that also declares class-level parameters.
 */
#[OA\Parameter(parameter: 'SupplierPortalId', name: 'id', description: 'Record identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'SupplierPortalSupplierId', name: 'supplierId', description: 'Supplier identifier (suppliers of the procurement module)', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'SupplierPortalRfqId', name: 'rfqId', description: 'Request for quotation identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'SupplierPortalPerPage', name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100))]
#[OA\SecurityScheme(securityScheme: 'supplierToken', type: 'apiKey', description: 'Personal token of a supplier on the supplier portal', name: 'X-Supplier-Token', in: 'header')]
#[OA\Response(response: 'SupplierPortalUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'SupplierPortalForbidden', description: 'The role of the user does not allow this', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'SupplierPortalNotFound', description: 'Record not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'SupplierPortalUnprocessable', description: 'Validation error or business rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
final class SupplierPortalOpenApiComponents {}
