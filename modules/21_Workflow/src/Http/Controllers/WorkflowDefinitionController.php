<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Workflow\Http\Resources\WorkflowDefinitionResource;
use Modules\Workflow\Models\WorkflowDefinition;

final class WorkflowDefinitionController extends Controller
{
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

    #[EnterpriseEndpoint(summary: 'Retrieve workflow definition details', tags: ['Workflow Engine'])]
    public function show(string $id): JsonResponse
    {
        $definition = WorkflowDefinition::with(['nodes.outgoingTransitions', 'transitions'])->findOrFail($id);

        return response()->json(new WorkflowDefinitionResource($definition));
    }
}
