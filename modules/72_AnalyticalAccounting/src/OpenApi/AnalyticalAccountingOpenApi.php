<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Operations and tag of the industrial and analytical cost accounting endpoints (Mod 018).
 */
#[OA\Tag(name: 'Analytical Accounting', description: 'Cost and profit centers, management ledger, derivation rules, step-down apportionment and contribution margin reports')]
#[OA\Get(path: '/api/v1/analytical/centers', operationId: 'listAnalyticalCenters', summary: 'List cost and profit centers (paginated, filter by center_type, category, is_active)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Get(path: '/api/v1/analytical/centers/tree', operationId: 'treeAnalyticalCenters', summary: 'Nested tree of the centers, optionally from root_id', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Get(path: '/api/v1/analytical/centers/flat', operationId: 'flatAnalyticalCenters', summary: 'Depth-first flat list of the centers with depth and path', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Post(path: '/api/v1/analytical/centers', operationId: 'storeAnalyticalCenter', summary: 'Create a center', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')), responses: [
    new OA\Response(response: 201, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Get(path: '/api/v1/analytical/centers/{id}', operationId: 'showAnalyticalCenter', summary: 'Show a center', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Put(path: '/api/v1/analytical/centers/{id}', operationId: 'updateAnalyticalCenter', summary: 'Update or move a center', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')), responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Delete(path: '/api/v1/analytical/centers/{id}', operationId: 'destroyAnalyticalCenter', summary: 'Delete a center never used by postings', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
    new OA\Response(response: 204, description: 'Done'),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Post(path: '/api/v1/analytical/centers/{id}/deactivate', operationId: 'deactivateAnalyticalCenter', summary: 'Deactivate a center and optionally its subtree', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')), responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Get(path: '/api/v1/analytical/centers/{id}/ancestors', operationId: 'ancestorsAnalyticalCenter', summary: 'Ancestors of a center', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Get(path: '/api/v1/analytical/centers/{id}/descendants', operationId: 'descendantsAnalyticalCenter', summary: 'Descendants of a center', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Get(path: '/api/v1/analytical/derivation-rules', operationId: 'listAnalyticalDerivationRules', summary: 'List derivation rules by priority', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Post(path: '/api/v1/analytical/derivation-rules', operationId: 'storeAnalyticalDerivationRule', summary: 'Create a derivation rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')), responses: [
    new OA\Response(response: 201, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Post(path: '/api/v1/analytical/derivation-rules/test', operationId: 'testAnalyticalDerivationRules', summary: 'Show the dimensions the rules derive for a posting context', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')), responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Get(path: '/api/v1/analytical/derivation-rules/{id}', operationId: 'showAnalyticalDerivationRule', summary: 'Show a derivation rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Put(path: '/api/v1/analytical/derivation-rules/{id}', operationId: 'updateAnalyticalDerivationRule', summary: 'Update a derivation rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')), responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Delete(path: '/api/v1/analytical/derivation-rules/{id}', operationId: 'destroyAnalyticalDerivationRule', summary: 'Delete a derivation rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
    new OA\Response(response: 204, description: 'Done'),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Get(path: '/api/v1/analytical/drivers', operationId: 'listAnalyticalDrivers', summary: 'List allocation drivers (system drivers are created on first read)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Post(path: '/api/v1/analytical/drivers', operationId: 'storeAnalyticalDriver', summary: 'Create a custom allocation driver', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')), responses: [
    new OA\Response(response: 201, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Put(path: '/api/v1/analytical/drivers/{id}', operationId: 'updateAnalyticalDriver', summary: 'Update an allocation driver', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')), responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Delete(path: '/api/v1/analytical/drivers/{id}', operationId: 'destroyAnalyticalDriver', summary: 'Delete a custom allocation driver', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
    new OA\Response(response: 204, description: 'Done'),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Get(path: '/api/v1/analytical/drivers/{id}/values', operationId: 'listAnalyticalDriverValues', summary: 'List the values of a driver by center and period', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Put(path: '/api/v1/analytical/drivers/{id}/values', operationId: 'storeAnalyticalDriverValues', summary: 'Set the values of a driver for one period', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')), responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Get(path: '/api/v1/analytical/entries', operationId: 'listAnalyticalEntries', summary: 'Query the analytical (management) ledger', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Post(path: '/api/v1/analytical/entries', operationId: 'storeAnalyticalEntry', summary: 'Post a manual analytical adjustment with allocation vectors', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')), responses: [
    new OA\Response(response: 201, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Get(path: '/api/v1/analytical/entries/{id}', operationId: 'showAnalyticalEntry', summary: 'Show an analytical entry with its lines', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Post(path: '/api/v1/analytical/entries/{id}/reverse', operationId: 'reverseAnalyticalEntry', summary: 'Reverse an analytical entry with a compensating entry', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')), responses: [
    new OA\Response(response: 201, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Get(path: '/api/v1/analytical/schedules', operationId: 'listAnalyticalSchedules', summary: 'List step-down schedules', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Post(path: '/api/v1/analytical/schedules', operationId: 'storeAnalyticalSchedule', summary: 'Create a step-down schedule with its ordered steps', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')), responses: [
    new OA\Response(response: 201, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Get(path: '/api/v1/analytical/schedules/{id}', operationId: 'showAnalyticalSchedule', summary: 'Show a step-down schedule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Put(path: '/api/v1/analytical/schedules/{id}', operationId: 'updateAnalyticalSchedule', summary: 'Replace a step-down schedule and its steps', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')), responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Delete(path: '/api/v1/analytical/schedules/{id}', operationId: 'destroyAnalyticalSchedule', summary: 'Delete a step-down schedule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
    new OA\Response(response: 204, description: 'Done'),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Get(path: '/api/v1/analytical/runs', operationId: 'listAnalyticalRuns', summary: 'List apportionment runs', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Post(path: '/api/v1/analytical/runs/preview', operationId: 'previewAnalyticalRun', summary: 'Preview a step-down cycle with the full allocation trace, nothing posted', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')), responses: [
    new OA\Response(response: 201, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Get(path: '/api/v1/analytical/runs/{id}', operationId: 'showAnalyticalRun', summary: 'Inspect a run with its steps and allocation chain', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Post(path: '/api/v1/analytical/runs/{id}/consolidate', operationId: 'consolidateAnalyticalRun', summary: 'Consolidate a preview run and post the apportionment entries', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')), responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Post(path: '/api/v1/analytical/runs/{id}/reverse', operationId: 'reverseAnalyticalRun', summary: 'Reverse a consolidated run with compensating entries', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')), responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Get(path: '/api/v1/analytical/reports/contribution-margin', operationId: 'analyticalContributionMarginReport', summary: 'Contribution margin income statement (JSON or CSV)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
#[OA\Get(path: '/api/v1/analytical/reports/direct-vs-apportioned', operationId: 'analyticalDirectVsApportionedReport', summary: 'Direct versus apportioned cost comparison per center (JSON or CSV)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Analytical Accounting'], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(response: 401, description: 'Unauthenticated'),
    new OA\Response(response: 403, description: 'Forbidden'),
    new OA\Response(response: 422, description: 'Validation error'),
])]
final class AnalyticalAccountingOpenApi {}
