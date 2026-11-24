<?php

declare(strict_types=1);

namespace Modules\Controlling\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Operations, shared responses and tag of the controlling, margin analytics and budget endpoints.
 */
#[OA\Tag(name: 'Controlling', description: 'Analytical cost accounting, overhead allocation, margin control, working capital KPIs, budgets, rolling forecast and variance analysis')]
#[OA\Get(path: '/api/v1/controlling/cost-centers', operationId: 'listControllingCostCenters', summary: 'List cost centers (flat) or the rollup tree with view=tree and fiscal_year', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/controlling/cost-centers', operationId: 'storeControllingCostCenter', summary: 'Create a cost center', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [
    new OA\Response(response: 201, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/controlling/cost-centers/{id}', operationId: 'showControllingCostCenter', summary: 'Show a cost center', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], parameters: [new OA\Parameter(ref: '#/components/parameters/ControllingId')], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Put(path: '/api/v1/controlling/cost-centers/{id}', operationId: 'updateControllingCostCenter', summary: 'Update a cost center', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], parameters: [new OA\Parameter(ref: '#/components/parameters/ControllingId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Delete(path: '/api/v1/controlling/cost-centers/{id}', operationId: 'destroyControllingCostCenter', summary: 'Delete a cost center without children', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], parameters: [new OA\Parameter(ref: '#/components/parameters/ControllingId')], responses: [
    new OA\Response(response: 204, description: 'Done'),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/controlling/jobs', operationId: 'listControllingJobs', summary: 'List jobs', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/controlling/jobs', operationId: 'storeControllingJob', summary: 'Create a job', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [
    new OA\Response(response: 201, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/controlling/jobs/{id}', operationId: 'showControllingJob', summary: 'Show a job', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], parameters: [new OA\Parameter(ref: '#/components/parameters/ControllingId')], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Put(path: '/api/v1/controlling/jobs/{id}', operationId: 'updateControllingJob', summary: 'Update a job', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], parameters: [new OA\Parameter(ref: '#/components/parameters/ControllingId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Delete(path: '/api/v1/controlling/jobs/{id}', operationId: 'destroyControllingJob', summary: 'Delete a job', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], parameters: [new OA\Parameter(ref: '#/components/parameters/ControllingId')], responses: [
    new OA\Response(response: 204, description: 'Done'),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/controlling/jobs/{id}/pnl', operationId: 'showControllingJobPnl', summary: 'Profit and loss of a job against its budget', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], parameters: [new OA\Parameter(ref: '#/components/parameters/ControllingId')], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/controlling/analytical-ledger', operationId: 'listControllingLedger', summary: 'Filterable analytical journal', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/controlling/analytical-ledger', operationId: 'storeControllingLedgerEntry', summary: 'Post a manual analytical adjustment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [
    new OA\Response(response: 201, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/controlling/allocation-rules', operationId: 'listControllingAllocationRules', summary: 'List allocation rules', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/controlling/allocation-rules', operationId: 'storeControllingAllocationRule', summary: 'Create an allocation rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [
    new OA\Response(response: 201, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Put(path: '/api/v1/controlling/allocation-rules/{id}', operationId: 'updateControllingAllocationRule', summary: 'Update an allocation rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], parameters: [new OA\Parameter(ref: '#/components/parameters/ControllingId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Delete(path: '/api/v1/controlling/allocation-rules/{id}', operationId: 'destroyControllingAllocationRule', summary: 'Delete an allocation rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], parameters: [new OA\Parameter(ref: '#/components/parameters/ControllingId')], responses: [
    new OA\Response(response: 204, description: 'Done'),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/controlling/allocations', operationId: 'listControllingAllocationRuns', summary: 'List allocation runs', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/controlling/allocations/preview', operationId: 'previewControllingAllocation', summary: 'Simulate the allocation of a period without posting', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/controlling/allocations/execute', operationId: 'executeControllingAllocation', summary: 'Post the allocation of a period', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [
    new OA\Response(response: 201, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/controlling/allocations/{runId}/reverse', operationId: 'reverseControllingAllocation', summary: 'Reverse a posted allocation run', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], parameters: [new OA\Parameter(name: 'runId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/analytics/margins/summary', operationId: 'showMarginSummary', summary: 'Margin KPIs with deltas against the baseline', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/analytics/margins/explore', operationId: 'exploreMargins', summary: 'Margins by dimension with drill path, sorting and pagination', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/analytics/margins/matrix', operationId: 'showMarginMatrix', summary: 'Two-dimensional margin cross-tab', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/analytics/margins/pareto', operationId: 'showMarginPareto', summary: 'ABC (80/15/5) classification and margin-eroding outliers', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/analytics/margins/export', operationId: 'exportMargins', summary: 'Export the margin explorer as CSV or XLSX', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/analytics/financial-kpis', operationId: 'showFinancialKpis', summary: 'DSO, DPO, CCN, inventory turnover, DIO, CCC and their history', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/analytics/financial-kpis/snapshots', operationId: 'storeFinancialSnapshot', summary: 'Compute or enter the financial snapshot of a date', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [
    new OA\Response(response: 201, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/analytics/variance', operationId: 'showVariance', summary: 'Variance of actual against budget, forecast and prior year', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/analytics/variance/waterfall', operationId: 'showVarianceWaterfall', summary: 'Bridge from budget margin to actual margin', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/budgets', operationId: 'listControllingBudgets', summary: 'List budget plans', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/budgets', operationId: 'storeControllingBudget', summary: 'Create a budget plan', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [
    new OA\Response(response: 201, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/budgets/seed-from-prior-year', operationId: 'seedControllingBudget', summary: 'Create a plan from prior-year actuals with their seasonality', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [
    new OA\Response(response: 201, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/budgets/{id}', operationId: 'showControllingBudget', summary: 'Show a plan with its lines', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], parameters: [new OA\Parameter(ref: '#/components/parameters/ControllingId')], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Put(path: '/api/v1/budgets/{id}', operationId: 'updateControllingBudget', summary: 'Update a draft plan', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], parameters: [new OA\Parameter(ref: '#/components/parameters/ControllingId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Delete(path: '/api/v1/budgets/{id}', operationId: 'destroyControllingBudget', summary: 'Delete a plan that is not approved or locked', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], parameters: [new OA\Parameter(ref: '#/components/parameters/ControllingId')], responses: [
    new OA\Response(response: 204, description: 'Done'),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/budgets/{id}/clone', operationId: 'cloneControllingBudget', summary: 'Clone a plan with an uplift percentage', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], parameters: [new OA\Parameter(ref: '#/components/parameters/ControllingId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [
    new OA\Response(response: 201, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/budgets/{id}/submit', operationId: 'submitControllingBudget', summary: 'Submit a draft plan', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], parameters: [new OA\Parameter(ref: '#/components/parameters/ControllingId')], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/budgets/{id}/approve', operationId: 'approveControllingBudget', summary: 'Approve a submitted plan', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], parameters: [new OA\Parameter(ref: '#/components/parameters/ControllingId')], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/budgets/{id}/lock', operationId: 'lockControllingBudget', summary: 'Lock an approved plan', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], parameters: [new OA\Parameter(ref: '#/components/parameters/ControllingId')], responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Put(path: '/api/v1/budgets/{id}/lines/bulk', operationId: 'bulkControllingBudgetLines', summary: 'Batch upsert of the monthly grid lines', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], parameters: [new OA\Parameter(ref: '#/components/parameters/ControllingId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [
    new OA\Response(response: 200, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/budgets/{id}/generate-rolling-forecast', operationId: 'generateControllingRollingForecast', summary: 'Actuals up to the cutoff plus projected open months', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Controlling'], parameters: [new OA\Parameter(ref: '#/components/parameters/ControllingId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [
    new OA\Response(response: 201, description: 'Done', content: new OA\JsonContent(type: 'object')),
    new OA\Response(ref: '#/components/responses/ControllingUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/ControllingForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/ControllingUnprocessable', response: 422),
])]
final class ControllingOpenApi {}
