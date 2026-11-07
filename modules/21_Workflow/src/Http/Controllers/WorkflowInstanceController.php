<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Enterprise\MultiTenant\Services\ModuleAccessGate;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Workflow\Actions\StartWorkflowAction;
use Modules\Workflow\DTOs\StartWorkflowDTO;
use Modules\Workflow\Http\Requests\StartWorkflowRequest;
use Modules\Workflow\Http\Resources\WorkflowAuditEntryResource;
use Modules\Workflow\Http\Resources\WorkflowInstanceResource;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngineService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

final class WorkflowInstanceController extends Controller
{
    /**
     * Returns the paginated list of instances of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/workflows/instances',
        operationId: 'listWorkflowsInstances',
        description: 'Returns the paginated list of instances of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List active and historical workflow instances',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Instances'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filter by status', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'approvable_type', in: 'query', required: false, description: 'Filter by approvable type', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'approvable_id', in: 'query', required: false, description: 'Filter by approvable', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of instances', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkflowInstanceResource')), new OA\Property(property: 'links', type: 'object'), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List active and historical workflow instances', tags: ['Workflow Engine'])]
    public function index(Request $request): JsonResponse
    {
        $instances = WorkflowInstance::query()
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('approvable_type'), fn ($q, $type) => $q->where('approvable_type', $type))
            ->when($request->query('approvable_id'), fn ($q, $id) => $q->where('approvable_id', $id))
            ->orderBy('started_at', 'desc')
            ->paginate((int) $request->query('per_page', 25));

        return response()->json(WorkflowInstanceResource::collection($instances)->response()->getData(true));
    }

    /**
     * Returns a single instance of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/workflows/instances/{id}',
        operationId: 'getWorkflowsInstance',
        description: 'Returns a single instance of the active tenant.',
        summary: 'Retrieve workflow instance details with active steps',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Instances'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the instance', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/WorkflowInstanceResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Retrieve workflow instance details with active steps', tags: ['Workflow Engine'])]
    public function show(string $id): JsonResponse
    {
        $instance = WorkflowInstance::with(['steps.decisions', 'definition'])->findOrFail($id);

        return response()->json(new WorkflowInstanceResource($instance));
    }

    /**
     * Creates a new instance in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/workflows/instances',
        operationId: 'createWorkflowsInstance',
        description: 'Creates a new instance in the active tenant.',
        summary: 'Initiate a new workflow instance for an approvable entity',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Instances'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkflowStartWorkflowRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Instance created', content: new OA\JsonContent(ref: '#/components/schemas/WorkflowInstanceResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Initiate a new workflow instance for an approvable entity', tags: ['Workflow Engine'])]
    public function store(StartWorkflowRequest $request, StartWorkflowAction $action, TenantContext $tenantContext, ModuleAccessGate $moduleAccess): JsonResponse
    {
        ['class' => $approvableClass, 'module' => $module] = $request->approvableDefinition();
        $tenantId = $tenantContext->getTenantId();

        // Only a record of the active tenant, and only from somebody who may change it.
        /** @var Model $approvable */
        $approvable = $approvableClass::query()
            ->whereKey($request->string('approvable_id')->value())
            ->where((new $approvableClass)->qualifyColumn('tenant_id'), $tenantId)
            ->firstOrFail();

        abort_unless($moduleAccess->allows($request->user(), $tenantId, $module, 'write'), Response::HTTP_FORBIDDEN);

        // A record waiting for approval is usually locked against edits, so `update` would refuse the very submission:
        // the record's own `submitForApproval` ability decides when it exists, otherwise the user must at least see it.
        $policy = Gate::getPolicyFor($approvable);

        if ($policy !== null) {
            $ability = method_exists($policy, 'submitForApproval') ? 'submitForApproval' : (method_exists($policy, 'view') ? 'view' : null);

            if ($ability !== null) {
                $this->authorize($ability, $approvable);
            }
        }

        $dto = new StartWorkflowDTO(
            workflowCode: $request->string('workflow_code')->value(),
            approvable: $approvable,
            contextSnapshot: $request->input('context_snapshot', []),
            initiatorUserId: (string) auth()->id()
        );

        $instance = $action->execute($dto);

        return response()->json(new WorkflowInstanceResource($instance), 201);
    }

    /**
     * Cancels an active workflow instance, recording the optional reason.
     */
    #[OA\Post(
        path: '/api/v1/workflows/instances/{id}/cancel',
        operationId: 'cancelWorkflowsInstance',
        description: 'Cancels an active workflow instance, recording the optional reason.',
        summary: 'Cancel workflow instance',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Instances'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the instance', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'reason')])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Cancel an active workflow instance', tags: ['Workflow Engine'])]
    public function cancel(string $id, Request $request, WorkflowEngineService $engine): JsonResponse
    {
        $instance = WorkflowInstance::findOrFail($id);
        $this->authorize('cancel', $instance);
        $engine->cancel($instance, $request->input('reason'));

        return response()->json(['message' => 'Workflow instance successfully cancelled.']);
    }

    /**
     * Returns the cryptographic forensic audit trail of a workflow instance.
     */
    #[OA\Get(
        path: '/api/v1/workflows/instances/{id}/audit-trail',
        operationId: 'getAuditTrailWorkflowsInstance',
        description: 'Returns the cryptographic forensic audit trail of a workflow instance.',
        summary: 'Get workflow audit trail',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Instances'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the instance', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkflowAuditEntryResource'))),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Retrieve cryptographic forensic audit trail for a workflow instance', tags: ['Workflow Engine'])]
    public function auditTrail(string $id): JsonResponse
    {
        $instance = WorkflowInstance::findOrFail($id);
        $this->authorize('viewAuditTrail', $instance);
        $entries = $instance->auditEntries()->get();

        return response()->json(WorkflowAuditEntryResource::collection($entries));
    }
}
