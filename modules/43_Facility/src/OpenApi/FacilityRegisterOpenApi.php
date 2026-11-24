<?php

declare(strict_types=1);

namespace Modules\Facility\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Operations of the building and space register and of the overview.
 */
#[OA\Get(path: '/api/v1/facility/overview', operationId: 'showFacilityOverview', summary: 'Facility overview', description: 'Counters for the start page: buildings, spaces, bookings of today, the caller\'s upcoming bookings, the request board and the inspections to do. Members see the counters of their own requests; operators those of the company.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], responses: [
    new OA\Response(response: 200, description: 'Facility overview', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityOverview')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
])]
#[OA\Get(path: '/api/v1/facility/buildings', operationId: 'listFacilityBuildings', summary: 'List buildings', description: 'Paginated buildings by code. Filters: active, q (code or name).', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], parameters: [
    new OA\Parameter(name: 'active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
    new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
    new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
], responses: [
    new OA\Response(response: 200, description: 'List buildings', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FacilityBuilding'))])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
])]
#[OA\Post(path: '/api/v1/facility/buildings', operationId: 'storeFacilityBuilding', summary: 'Create a building', description: 'Facility managers only. The code is unique among the buildings of the company.', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FacilityBuildingRequest')), tags: ['Facility'], responses: [
    new OA\Response(response: 201, description: 'Create a building', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityBuilding')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/facility/buildings/{id}', operationId: 'showFacilityBuilding', summary: 'Show a building', description: 'A building of the company.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 200, description: 'Show a building', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityBuilding')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
])]
#[OA\Put(path: '/api/v1/facility/buildings/{id}', operationId: 'updateFacilityBuilding', summary: 'Update a building', description: 'Facility managers only.', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FacilityBuildingRequest')), tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 200, description: 'Update a building', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityBuilding')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Delete(path: '/api/v1/facility/buildings/{id}', operationId: 'destroyFacilityBuilding', summary: 'Delete a building', description: 'Facility managers only. A building that still has spaces cannot be deleted.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 204, description: 'Deleted'),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/facility/buildings/{id}/tree', operationId: 'treeFacilityBuilding', summary: 'Space tree of a building', description: 'Floors first, then every space under its parent, nested with the children key.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 200, description: 'Space tree of a building', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FacilitySpaceNode'))])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
])]
#[OA\Get(path: '/api/v1/facility/spaces', operationId: 'listFacilitySpaces', summary: 'List spaces', description: 'Paginated floors and spaces. Filters: building_id, parent_id, kind, bookable, active, q.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], parameters: [
    new OA\Parameter(name: 'building_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    new OA\Parameter(name: 'parent_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    new OA\Parameter(name: 'kind', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['floor', 'office', 'meeting_room', 'desk', 'parking', 'warehouse', 'common_area'])),
    new OA\Parameter(name: 'bookable', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
    new OA\Parameter(name: 'active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
    new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
    new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
], responses: [
    new OA\Response(response: 200, description: 'List spaces', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FacilitySpace'))])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/facility/spaces/available', operationId: 'availableFacilitySpaces', summary: 'Find free spaces', description: 'Bookable active spaces with nothing booked during the interval. Filters: building_id, kind, min_capacity.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], parameters: [
    new OA\Parameter(name: 'starts_at', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date-time')),
    new OA\Parameter(name: 'ends_at', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date-time')),
    new OA\Parameter(name: 'building_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    new OA\Parameter(name: 'kind', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['office', 'meeting_room', 'desk', 'parking', 'warehouse'])),
    new OA\Parameter(name: 'min_capacity', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
], responses: [
    new OA\Response(response: 200, description: 'Find free spaces', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FacilitySpace'))])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/facility/spaces', operationId: 'storeFacilitySpace', summary: 'Create a space', description: 'Facility managers only. A floor has no parent; desks, parking spots and meeting rooms are leaves; the parent must be in the same building; a bookable space needs a capacity.', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FacilitySpaceRequest')), tags: ['Facility'], responses: [
    new OA\Response(response: 201, description: 'Create a space', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilitySpace')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/facility/spaces/{id}', operationId: 'showFacilitySpace', summary: 'Show a space', description: 'A floor or space with its building.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 200, description: 'Show a space', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilitySpace')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
])]
#[OA\Put(path: '/api/v1/facility/spaces/{id}', operationId: 'updateFacilitySpace', summary: 'Update a space', description: 'Facility managers only. The building never changes and a space cannot become its own ancestor.', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FacilitySpaceRequest')), tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 200, description: 'Update a space', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilitySpace')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Delete(path: '/api/v1/facility/spaces/{id}', operationId: 'destroyFacilitySpace', summary: 'Delete a space', description: 'Facility managers only. A space with children or with bookings still to come cannot be deleted.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 204, description: 'Deleted'),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
final class FacilityRegisterOpenApi {}
