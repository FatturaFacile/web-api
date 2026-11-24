<?php

declare(strict_types=1);

namespace Modules\Facility\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Operations of the utility meters and readings.
 */
#[OA\Get(path: '/api/v1/facility/meters', operationId: 'listFacilityMeters', summary: 'List meters', description: 'Operators only. Paginated utility meters. Filters: building_id, kind, active.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], parameters: [
    new OA\Parameter(name: 'building_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    new OA\Parameter(name: 'kind', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['electricity', 'gas', 'water'])),
    new OA\Parameter(name: 'active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
    new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
], responses: [
    new OA\Response(response: 200, description: 'List meters', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FacilityMeter'))])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/facility/meters/summary', operationId: 'summaryFacilityMeters', summary: 'Monthly consumption summary', description: 'Operators only. Consumption and cost per month, kind and unit for a calendar year; the consumption of a reading counts in the month of its date.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], parameters: [
    new OA\Parameter(name: 'year', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
    new OA\Parameter(name: 'building_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    new OA\Parameter(name: 'kind', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['electricity', 'gas', 'water'])),
], responses: [
    new OA\Response(response: 200, description: 'Monthly consumption summary', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityMeterSummary')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/facility/meters', operationId: 'storeFacilityMeter', summary: 'Create a meter', description: 'Facility managers only. The unit must fit the kind (electricity kWh or MWh; gas Sm3, m3 or kWh; water m3 or l).', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FacilityMeterRequest')), tags: ['Facility'], responses: [
    new OA\Response(response: 201, description: 'Create a meter', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityMeter')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/facility/meters/{id}', operationId: 'showFacilityMeter', summary: 'Show a meter', description: 'Operators only.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 200, description: 'Show a meter', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityMeter')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
])]
#[OA\Put(path: '/api/v1/facility/meters/{id}', operationId: 'updateFacilityMeter', summary: 'Update a meter', description: 'Facility managers only. Kind and unit are locked once the meter has readings.', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FacilityMeterRequest')), tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 200, description: 'Update a meter', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityMeter')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Delete(path: '/api/v1/facility/meters/{id}', operationId: 'destroyFacilityMeter', summary: 'Delete a meter', description: 'Facility managers only.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 204, description: 'Deleted'),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/facility/meters/{id}/readings', operationId: 'listFacilityMeterReadings', summary: 'List readings', description: 'Operators only. Paginated readings, latest first. Filters: from, to.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
    new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
    new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
    new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
], responses: [
    new OA\Response(response: 200, description: 'List readings', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FacilityMeterReading'))])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/facility/meters/{id}/readings', operationId: 'storeFacilityMeterReading', summary: 'Record a reading', description: 'Operators only. Readings follow the dates in order. The first reading is the baseline; a reading below the previous one is refused unless it is declared a roll-over (the meter needs a roll-over value) or a replacement of the meter. The consumption is costed with the tariff of the meter.', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FacilityMeterReadingRequest')), tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 201, description: 'Record a reading', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityMeterReading')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Delete(path: '/api/v1/facility/meters/{id}/readings/{readingId}', operationId: 'destroyFacilityMeterReading', summary: 'Delete the latest reading', description: 'Facility managers only. Only the latest reading of a meter can be removed.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
    new OA\Parameter(name: 'readingId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
], responses: [
    new OA\Response(response: 204, description: 'Deleted'),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
final class FacilityMeterOpenApi {}
