<?php

declare(strict_types=1);

namespace App\OpenApi\Components;

use OpenApi\Attributes as OA;

/**
 * Shared OpenAPI parameters and responses of the sales cycle endpoints (orders fulfilment, quotes versions, pricing,
 * promotions, returns and sales indicators).
 */
#[OA\Parameter(parameter: 'SalesCycleOrderId', name: 'id', description: 'Sales order identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'SalesCycleQuoteId', name: 'id', description: 'Quote identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'SalesCycleReturnId', name: 'id', description: 'Sales return identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'SalesCycleDiscountPolicyId', name: 'id', description: 'Discount policy identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'SalesCycleWarehouseId', name: 'warehouse_id', description: 'Warehouse of the active company (the primary warehouse when omitted)', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'SalesCyclePage', name: 'page', description: 'Page number', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1))]
#[OA\Parameter(parameter: 'SalesCyclePerPage', name: 'per_page', description: 'Rows per page (5 to 100)', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 5, maximum: 100))]
#[OA\Response(response: 'SalesCycleUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'SalesCycleForbidden', description: 'The role of the user does not allow the action (read-only roles, or a manager-only action)', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'SalesCycleNotFound', description: 'Not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'SalesCycleValidationError', description: 'Validation error, or a business rule of the sales cycle refused the operation (status, quantities, validity)', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
final class SalesCycleOpenApiComponents {}
