<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Workflow\Actions\StartWorkflowAction;
use Modules\Workflow\DTOs\StartWorkflowDTO;
use Modules\Workflow\Http\Requests\StartWorkflowRequest;
use Modules\Workflow\Http\Resources\WorkflowAuditEntryResource;
use Modules\Workflow\Http\Resources\WorkflowInstanceResource;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngineService;

final class WorkflowInstanceController extends Controller
{
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

    #[EnterpriseEndpoint(summary: 'Retrieve workflow instance details with active steps', tags: ['Workflow Engine'])]
    public function show(string $id): JsonResponse
    {
        $instance = WorkflowInstance::with(['steps.decisions', 'definition'])->findOrFail($id);

        return response()->json(new WorkflowInstanceResource($instance));
    }

    #[EnterpriseEndpoint(summary: 'Initiate a new workflow instance for an approvable entity', tags: ['Workflow Engine'])]
    public function store(StartWorkflowRequest $request, StartWorkflowAction $action): JsonResponse
    {
        $approvableClass = $request->string('approvable_type')->value();
        $approvableId = $request->string('approvable_id')->value();

        /** @var Model $approvable */
        $approvable = $approvableClass::findOrFail($approvableId);

        $dto = new StartWorkflowDTO(
            workflowCode: $request->string('workflow_code')->value(),
            approvable: $approvable,
            contextSnapshot: $request->input('context_snapshot', []),
            initiatorUserId: (string) auth()->id()
        );

        $instance = $action->execute($dto);

        return response()->json(new WorkflowInstanceResource($instance), 201);
    }

    #[EnterpriseEndpoint(summary: 'Cancel an active workflow instance', tags: ['Workflow Engine'])]
    public function cancel(string $id, Request $request, WorkflowEngineService $engine): JsonResponse
    {
        $instance = WorkflowInstance::findOrFail($id);
        $engine->cancel($instance, $request->input('reason'));

        return response()->json(['message' => 'Workflow instance successfully cancelled.']);
    }

    #[EnterpriseEndpoint(summary: 'Retrieve cryptographic forensic audit trail for a workflow instance', tags: ['Workflow Engine'])]
    public function auditTrail(string $id): JsonResponse
    {
        $instance = WorkflowInstance::findOrFail($id);
        $entries = $instance->auditEntries()->get();

        return response()->json(WorkflowAuditEntryResource::collection($entries));
    }
}
