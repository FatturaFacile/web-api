<?php

declare(strict_types=1);

namespace Modules\Inventory\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Parameters, responses and schemas shared by the warehouse management (WMS) endpoints of the inventory module:
 * location master, putaway, outbound (reservations, waves, shipment), transfer orders, cycle counting, replenishment,
 * valuation at date, KPIs, handheld lookup and lot recall. Quantities are decimal strings with 4 decimals, values
 * decimal strings with 2.
 */
#[OA\Parameter(parameter: 'WmsRecordId', name: 'id', description: 'Identifier of the record in the active company', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'WmsWarehouseFilter', name: 'warehouse_id', description: 'Only this warehouse of the active company', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Response(response: 'WmsUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'WmsForbidden', description: 'The role of the user cannot write in the inventory module (read-only roles), or the action is a manager decision (owner/admin)', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'WmsNotFound', description: 'Record not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'WmsUnprocessable', description: 'Invalid data, or the stock / document state does not allow the operation (message explains)', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
final class WmsOpenApiComponents {}
