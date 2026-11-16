<?php

declare(strict_types=1);

namespace Modules\Facility\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Operations of the inspection templates and inspections.
 */
#[OA\Get(path: '/api/v1/facility/inspection-templates', operationId: 'listFacilityInspectionTemplates', summary: 'List inspection templates', description: 'Operators only. Paginated by next due date. Filter: active.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], parameters: [
    new OA\Parameter(name: 'active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
    new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
], responses: [
    new OA\Response(response: 200, description: 'List inspection templates', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FacilityInspectionTemplate'))])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
])]
#[OA\Post(path: '/api/v1/facility/inspection-templates', operationId: 'storeFacilityInspectionTemplate', summary: 'Create an inspection template', description: 'Facility managers only. A checklist, a frequency and the next due date; the scheduler generates the inspections.', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FacilityInspectionTemplateRequest')), tags: ['Facility'], responses: [
    new OA\Response(response: 201, description: 'Create an inspection template', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityInspectionTemplate')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/facility/inspection-templates/generate', operationId: 'generateFacilityInspections', summary: 'Generate due inspections', description: 'Facility managers only. Runs the scheduler by hand: one inspection per due date, once only, whatever the number of runs.', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/FacilityGenerateInspectionsRequest')), tags: ['Facility'], responses: [
    new OA\Response(response: 200, description: 'Generate due inspections', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FacilityInspection'))])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/facility/inspection-templates/{id}', operationId: 'showFacilityInspectionTemplate', summary: 'Show an inspection template', description: 'Operators only.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 200, description: 'Show an inspection template', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityInspectionTemplate')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
])]
#[OA\Put(path: '/api/v1/facility/inspection-templates/{id}', operationId: 'updateFacilityInspectionTemplate', summary: 'Update an inspection template', description: 'Facility managers only. Generated inspections keep the checklist they were created with.', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FacilityInspectionTemplateRequest')), tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 200, description: 'Update an inspection template', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityInspectionTemplate')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Delete(path: '/api/v1/facility/inspection-templates/{id}', operationId: 'destroyFacilityInspectionTemplate', summary: 'Delete an inspection template', description: 'Facility managers only. Inspections already generated stay.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 204, description: 'Deleted'),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/facility/inspections', operationId: 'listFacilityInspections', summary: 'List inspections', description: 'Operators only. Paginated by due date. Filters: status, assignee_id, building_id, template_id, from, to, mine, open, overdue.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], parameters: [
    new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['scheduled', 'in_progress', 'completed', 'cancelled'])),
    new OA\Parameter(name: 'assignee_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    new OA\Parameter(name: 'building_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    new OA\Parameter(name: 'template_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
    new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
    new OA\Parameter(name: 'mine', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
    new OA\Parameter(name: 'open', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
    new OA\Parameter(name: 'overdue', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
    new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
], responses: [
    new OA\Response(response: 200, description: 'List inspections', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FacilityInspection'))])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/facility/inspections/{id}', operationId: 'showFacilityInspection', summary: 'Show an inspection', description: 'Operators only. With the checklist and its answers.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 200, description: 'Show an inspection', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityInspection')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
])]
#[OA\Post(path: '/api/v1/facility/inspections/{id}/start', operationId: 'startFacilityInspection', summary: 'Start an inspection', description: 'Operators only. From scheduled to in progress.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 200, description: 'Start an inspection', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityInspection')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/facility/inspections/{id}/complete', operationId: 'completeFacilityInspection', summary: 'Complete an inspection', description: 'Operators only. Records the answers of the checklist: every required item needs an answer. A failed pass/fail item makes the result fail and raises a high priority service request for the location.', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FacilityCompleteInspectionRequest')), tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 200, description: 'Complete an inspection', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityInspection')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/facility/inspections/{id}/cancel', operationId: 'cancelFacilityInspection', summary: 'Cancel an inspection', description: 'Operators only, with the reason.', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FacilityReasonRequest')), tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 200, description: 'Cancel an inspection', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityInspection')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
final class FacilityInspectionOpenApi {}
