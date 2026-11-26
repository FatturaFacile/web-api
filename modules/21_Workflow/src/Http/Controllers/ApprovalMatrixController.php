<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Workflow\Http\Requests\SimulateMatrixRequest;
use Modules\Workflow\Http\Requests\UpsertApprovalMatrixRequest;
use Modules\Workflow\Http\Resources\ApprovalMatrixResource;
use Modules\Workflow\Models\ApprovalMatrix;
use Modules\Workflow\Services\ApprovalMatrixEvaluatorService;
use Modules\Workflow\Services\ApprovalMatrixManager;
use OpenApi\Attributes as OA;

/**
 * Tiered approval matrices of the active tenant: list and read for every workflow user, write for owners and
 * administrators, and a dry run ("simulate") for anybody who designs or audits a workflow.
 */
final class ApprovalMatrixController extends Controller
{
    use ApiResponse;

    /**
     * Lists the matrices, highest priority first.
     */
    #[OA\Get(
        path: '/api/v1/workflows/matrices',
        operationId: 'listWorkflowsMatrices',
        description: 'Lists the approval matrices of the active tenant with their conditions and tiers, optionally for one subject type.',
        summary: 'List approval matrices',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Approval Matrices'],
        parameters: [
            new OA\Parameter(name: 'subject_type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Matrices', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkflowApprovalMatrix')), new OA\Property(property: 'links', type: 'object'), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List approval matrices', tags: ['Workflow Engine'])]
    public function index(Request $request, ApprovalMatrixManager $matrices): JsonResponse
    {
        $page = $matrices->paginate($request->query('subject_type') !== null ? (string) $request->query('subject_type') : null, max(1, min(100, (int) $request->query('per_page', 25))));

        return response()->json(ApprovalMatrixResource::collection($page)->response()->getData(true));
    }

    /**
     * Returns one matrix.
     */
    #[OA\Get(
        path: '/api/v1/workflows/matrices/{id}',
        operationId: 'getWorkflowsMatrix',
        description: 'Returns one approval matrix of the active tenant.',
        summary: 'Retrieve an approval matrix',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Approval Matrices'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The matrix', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Retrieve an approval matrix', tags: ['Workflow Engine'])]
    public function show(string $id): JsonResponse
    {
        $matrix = $this->find($id);

        return $this->successResponse((new ApprovalMatrixResource($matrix->load(['rules', 'tiers'])))->resolve(), 'Approval matrix retrieved');
    }

    /**
     * Creates a matrix.
     */
    #[OA\Post(
        path: '/api/v1/workflows/matrices',
        operationId: 'createWorkflowsMatrix',
        description: 'Creates an approval matrix: conditions (all must hold) and cumulative tiers by amount range.',
        summary: 'Create an approval matrix',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Approval Matrices'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkflowUpsertApprovalMatrixRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Matrix created', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 403, description: 'Only owners and administrators', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Create an approval matrix', tags: ['Workflow Engine'])]
    public function store(UpsertApprovalMatrixRequest $request, ApprovalMatrixManager $matrices): JsonResponse
    {
        $matrix = $matrices->upsert($request->validated(), null, $request->user()?->id !== null ? (string) $request->user()->id : null);

        return $this->successResponse((new ApprovalMatrixResource($matrix))->resolve(), 'Approval matrix created', 201);
    }

    /**
     * Replaces a matrix.
     */
    #[OA\Put(
        path: '/api/v1/workflows/matrices/{id}',
        operationId: 'updateWorkflowsMatrix',
        description: 'Replaces the header, the conditions and the tiers of a matrix in one transaction.',
        summary: 'Update an approval matrix',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Approval Matrices'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkflowUpsertApprovalMatrixRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Matrix updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 403, description: 'Only owners and administrators', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Update an approval matrix', tags: ['Workflow Engine'])]
    public function update(UpsertApprovalMatrixRequest $request, ApprovalMatrixManager $matrices, string $id): JsonResponse
    {
        $matrix = $matrices->upsert($request->validated(), $this->find($id), $request->user()?->id !== null ? (string) $request->user()->id : null);

        return $this->successResponse((new ApprovalMatrixResource($matrix))->resolve(), 'Approval matrix updated');
    }

    /**
     * Deletes a matrix.
     */
    #[OA\Delete(
        path: '/api/v1/workflows/matrices/{id}',
        operationId: 'deleteWorkflowsMatrix',
        description: 'Deletes a matrix. Instances already running keep their approvers.',
        summary: 'Delete an approval matrix',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Approval Matrices'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 403, description: 'Only owners and administrators', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Delete an approval matrix', tags: ['Workflow Engine'])]
    public function destroy(ApprovalMatrixManager $matrices, string $id): Response
    {
        $matrices->delete($this->find($id));

        return response()->noContent();
    }

    /**
     * Dry run: which approvers a document with these attributes would get.
     */
    #[OA\Post(
        path: '/api/v1/workflows/matrices/simulate',
        operationId: 'simulateWorkflowsMatrix',
        description: 'Evaluates the matrices of a subject type for the given attributes without saving anything. Returns the winning matrix, whether the default rule or the fallback approver was used, whether the document would be auto-approved and the tiers.',
        summary: 'Simulate an approval matrix',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Approval Matrices'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkflowSimulateMatrixRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Simulation result', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Simulate an approval matrix', tags: ['Workflow Engine'])]
    public function simulate(SimulateMatrixRequest $request, ApprovalMatrixEvaluatorService $evaluator, TenantContext $tenants): JsonResponse
    {
        $result = $evaluator->simulate(
            (string) $tenants->getTenantId(),
            $request->string('subject_type')->value(),
            (array) $request->input('attributes', []),
            $request->input('matrix_id')
        );

        $matrix = $result['matrix'];

        return $this->successResponse([
            'matrix' => $matrix === null ? null : ['id' => $matrix->id, 'code' => $matrix->code, 'name' => $matrix->name],
            'used_default' => $result['used_default'],
            'used_fallback' => $result['used_fallback'],
            'auto_approved' => $result['auto_approved'],
            'blocked' => $result['blocked'],
            'tiers' => $result['tiers'],
        ], 'Simulation completed');
    }

    private function find(string $id): ApprovalMatrix
    {
        return ApprovalMatrix::query()->where('tenant_id', (string) app(TenantContext::class)->getTenantId())->whereKey($id)->firstOrFail();
    }
}
