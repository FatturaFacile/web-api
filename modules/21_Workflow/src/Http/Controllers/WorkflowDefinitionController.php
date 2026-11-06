<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Workflow\Http\Resources\WorkflowDefinitionResource;
use Modules\Workflow\Models\WorkflowDefinition;
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
            ->orderBy('code')
            ->orderBy('version', 'desc')
            ->paginate((int) $request->query('per_page', 25));

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
}
