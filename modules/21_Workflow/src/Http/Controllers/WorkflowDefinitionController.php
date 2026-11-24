<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Workflow\Http\Requests\SaveWorkflowDefinitionRequest;
use Modules\Workflow\Http\Resources\WorkflowDefinitionResource;
use Modules\Workflow\Models\WorkflowDefinition;
use Modules\Workflow\Services\WorkflowDefinitionManager;
use OpenApi\Attributes as OA;

final class WorkflowDefinitionController extends Controller
{
    /**
     * Returns the paginated list of definitions of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/workflows/definitions',
        operationId: 'listWorkflowsDefinitions',
        description: 'Returns the paginated list of definitions of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List workflow definitions for active tenant',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Definitions'],
        parameters: [
            new OA\Parameter(name: 'target_entity_type', in: 'query', required: false, description: 'Filter by target entity type', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'is_active', in: 'query', required: false, description: 'Filter by is active', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Matches the code or the name', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of definitions', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkflowDefinitionResource')), new OA\Property(property: 'links', type: 'object'), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List workflow definitions for active tenant', tags: ['Workflow Engine'])]
    public function index(Request $request): JsonResponse
    {
        $definitions = WorkflowDefinition::query()
            ->when($request->query('target_entity_type'), fn ($q, $type) => $q->where('target_entity_type', $type))
            ->when($request->query('is_active'), fn ($q, $active) => $q->where('is_active', filter_var($active, FILTER_VALIDATE_BOOLEAN)))
            ->when($request->filled('search'), function ($q) use ($request): void {
                $term = '%'.addcslashes((string) $request->query('search'), '%_\\').'%';
                $q->where(fn ($inner) => $inner->whereLike('code', $term)->orWhereLike('name', $term));
            })
            ->orderBy('code')
            ->orderBy('version', 'desc')
            ->gridPaginate((int) $request->query('per_page', 25));

        return response()->json(WorkflowDefinitionResource::collection($definitions)->response()->getData(true));
    }

    /**
     * Returns a single definition of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/workflows/definitions/{id}',
        operationId: 'getWorkflowsDefinition',
        description: 'Returns a single definition of the active tenant.',
        summary: 'Retrieve workflow definition details',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Definitions'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the definition', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/WorkflowDefinitionResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Retrieve workflow definition details', tags: ['Workflow Engine'])]
    public function show(string $id): JsonResponse
    {
        $definition = WorkflowDefinition::with(['nodes.outgoingTransitions', 'transitions'])->findOrFail($id);

        return response()->json(new WorkflowDefinitionResource($definition));
    }

    /**
     * Creates a draft definition, optionally with its graph and published at once.
     */
    #[OA\Post(
        path: '/api/v1/workflows/definitions',
        operationId: 'createWorkflowsDefinition',
        description: 'Creates a draft definition of the active tenant (next version of its code). Nodes and transitions can be sent inline, transitions referring to node keys; with publish=true the graph is validated and the definition becomes the active version. Owner and admin only.',
        summary: 'Create workflow definition',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Definitions'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkflowSaveDefinitionRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Definition created', content: new OA\JsonContent(ref: '#/components/schemas/WorkflowDefinitionResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and admins manage definitions', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error or invalid graph', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(SaveWorkflowDefinitionRequest $request, WorkflowDefinitionManager $definitions, TenantContext $tenantContext): JsonResponse
    {
        $definition = $definitions->create((string) $tenantContext->getTenantId(), $request->validated(), $request->user()?->id);

        return response()->json(new WorkflowDefinitionResource($definition->load(['nodes', 'transitions'])), 201);
    }

    /**
     * Changes the metadata of a definition, or activates and deactivates it.
     */
    #[OA\Put(
        path: '/api/v1/workflows/definitions/{id}',
        operationId: 'updateWorkflowsDefinition',
        description: 'Changes name, description, target entity type or configuration, and activates (published only) or deactivates the definition. Activating deactivates the other versions of the code. The graph changes through the node and transition endpoints, on drafts only.',
        summary: 'Update workflow definition',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Definitions'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkflowSaveDefinitionRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Definition updated', content: new OA\JsonContent(ref: '#/components/schemas/WorkflowDefinitionResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and admins manage definitions', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    #[OA\Patch(
        path: '/api/v1/workflows/definitions/{id}',
        operationId: 'patchWorkflowsDefinition',
        description: 'Same as PUT: only the fields sent change.',
        summary: 'Partially update workflow definition',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Definitions'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkflowSaveDefinitionRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Definition updated', content: new OA\JsonContent(ref: '#/components/schemas/WorkflowDefinitionResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and admins manage definitions', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(SaveWorkflowDefinitionRequest $request, WorkflowDefinitionManager $definitions, string $id): JsonResponse
    {
        $definition = $definitions->update(WorkflowDefinition::query()->findOrFail($id), $request->validated(), $request->user()?->id);

        return response()->json(new WorkflowDefinitionResource($definition->load(['nodes', 'transitions'])));
    }

    /**
     * Archives a definition without running instances.
     */
    #[OA\Delete(
        path: '/api/v1/workflows/definitions/{id}',
        operationId: 'deleteWorkflowsDefinition',
        description: 'Archives (soft deletes) a definition. Refused while it has running instances.',
        summary: 'Delete workflow definition',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Definitions'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 204, description: 'Definition archived'),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and admins manage definitions', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'The definition has running instances', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function destroy(WorkflowDefinitionManager $definitions, string $id): Response
    {
        $definitions->delete(WorkflowDefinition::query()->findOrFail($id));

        return response()->noContent();
    }

    /**
     * Validates the graph and makes the definition the active version of its code.
     */
    #[OA\Post(
        path: '/api/v1/workflows/definitions/{id}/publish',
        operationId: 'publishWorkflowsDefinition',
        description: 'Validates the graph (one start node with a start transition, approval nodes with an assignee and an approved transition, reachable end node, no outgoing transitions from end nodes) and publishes the definition as the active version of its code.',
        summary: 'Publish workflow definition',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Definitions'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Definition published', content: new OA\JsonContent(ref: '#/components/schemas/WorkflowDefinitionResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and admins manage definitions', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'The graph is not valid', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function publish(WorkflowDefinitionManager $definitions, string $id): JsonResponse
    {
        $definition = $definitions->publish(WorkflowDefinition::query()->findOrFail($id));

        return response()->json(new WorkflowDefinitionResource($definition->load(['nodes', 'transitions'])));
    }
}
