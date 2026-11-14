<?php

declare(strict_types=1);

namespace Modules\Facility\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Operations of the service requests.
 */
#[OA\Get(path: '/api/v1/facility/service-requests', operationId: 'listFacilityServiceRequests', summary: 'List service requests', description: 'Paginated requests, newest first. Members see the requests they raised; operators see all. Filters: status, category, priority, assignee_id, building_id, mine, open, overdue, q (number or title).', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], parameters: [
    new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['new', 'assigned', 'in_progress', 'on_hold', 'resolved', 'closed', 'cancelled'])),
    new OA\Parameter(name: 'category', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['cleaning', 'repair', 'it', 'security', 'hvac', 'other'])),
    new OA\Parameter(name: 'priority', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['low', 'normal', 'high', 'urgent'])),
    new OA\Parameter(name: 'assignee_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    new OA\Parameter(name: 'building_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    new OA\Parameter(name: 'mine', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
    new OA\Parameter(name: 'open', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
    new OA\Parameter(name: 'overdue', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
    new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
    new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
], responses: [
    new OA\Response(response: 200, description: 'List service requests', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FacilityServiceRequest'))])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/facility/service-requests/board', operationId: 'boardFacilityServiceRequests', summary: 'Request board counters', description: 'Requests by status, open requests by priority and the number of open requests past their SLA.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], responses: [
    new OA\Response(response: 200, description: 'Request board counters', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityServiceRequestBoard')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
])]
#[OA\Post(path: '/api/v1/facility/service-requests', operationId: 'storeFacilityServiceRequest', summary: 'Raise a service request', description: 'Any member raises a request. The number is progressive per company and year (SR-2027-0001) and the SLA due date follows the priority.', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FacilityServiceRequestRequest')), tags: ['Facility'], responses: [
    new OA\Response(response: 201, description: 'Raise a service request', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityServiceRequest')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/facility/service-requests/{id}', operationId: 'showFacilityServiceRequest', summary: 'Show a service request', description: 'A request with its people.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 200, description: 'Show a service request', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityServiceRequest')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
])]
#[OA\Put(path: '/api/v1/facility/service-requests/{id}', operationId: 'updateFacilityServiceRequest', summary: 'Update a service request', description: 'Changes an open request (operators) or a new one (its requester). A new priority recomputes the SLA due date from the creation time.', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FacilityServiceRequestRequest')), tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 200, description: 'Update a service request', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityServiceRequest')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/facility/service-requests/{id}/assign', operationId: 'assignFacilityServiceRequest', summary: 'Assign a service request', description: 'Operators only. A new request becomes assigned; the first assignment is the first response.', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FacilityAssignRequest')), tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 200, description: 'Assign a service request', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityServiceRequest')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/facility/service-requests/{id}/start', operationId: 'startFacilityServiceRequest', summary: 'Start work on a service request', description: 'Operators only. From new, assigned or on hold to in progress.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 200, description: 'Start work on a service request', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityServiceRequest')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/facility/service-requests/{id}/hold', operationId: 'holdFacilityServiceRequest', summary: 'Put a service request on hold', description: 'Operators only. From assigned or in progress, with the reason.', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FacilityReasonRequest')), tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 200, description: 'Put a service request on hold', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityServiceRequest')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/facility/service-requests/{id}/resolve', operationId: 'resolveFacilityServiceRequest', summary: 'Resolve a service request', description: 'Operators only. From in progress, with what was done.', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FacilityResolveRequest')), tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 200, description: 'Resolve a service request', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityServiceRequest')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/facility/service-requests/{id}/close', operationId: 'closeFacilityServiceRequest', summary: 'Close a service request', description: 'Operators only. From resolved.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 200, description: 'Close a service request', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityServiceRequest')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/facility/service-requests/{id}/reopen', operationId: 'reopenFacilityServiceRequest', summary: 'Reopen a service request', description: 'Operators or the requester. From resolved back to in progress.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 200, description: 'Reopen a service request', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityServiceRequest')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/facility/service-requests/{id}/cancel', operationId: 'cancelFacilityServiceRequest', summary: 'Cancel a service request', description: 'Operators, or the requester while the request is new, with the reason.', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FacilityReasonRequest')), tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 200, description: 'Cancel a service request', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityServiceRequest')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/facility/service-requests/{id}/link-work-order', operationId: 'linkWorkOrderFacilityServiceRequest', summary: 'Link a maintenance work order', description: 'Operators only. Links an existing maintenance work order of the asset management module (referenced by id); the asset of the work order is taken when the request has none.', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FacilityLinkWorkOrderRequest')), tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 200, description: 'Link a maintenance work order', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityServiceRequest')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/facility/service-requests/{id}/raise-work-order', operationId: 'raiseWorkOrderFacilityServiceRequest', summary: 'Raise a maintenance work order', description: 'Operators only. Creates a corrective (emergency when urgent) work order in the asset management module for the asset of the request and links it. The work order stays the only record of the maintenance job.', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/FacilityRaiseWorkOrderRequest')), tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 200, description: 'Raise a maintenance work order', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityServiceRequest')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/facility/service-requests/{id}/comments', operationId: 'listFacilityRequestComments', summary: 'List comments', description: 'Comments of a request, oldest first. Internal comments are visible to operators only.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 200, description: 'List comments', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FacilityRequestComment'))])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
])]
#[OA\Post(path: '/api/v1/facility/service-requests/{id}/comments', operationId: 'storeFacilityRequestComment', summary: 'Comment a service request', description: 'Adds a comment; only operators can write internal comments.', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FacilityRequestCommentRequest')), tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 201, description: 'Comment a service request', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityRequestComment')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
final class FacilityRequestOpenApi {}
