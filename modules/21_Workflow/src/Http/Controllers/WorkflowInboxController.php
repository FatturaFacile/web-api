<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Workflow\Http\Resources\WorkflowInstanceResource;
use Modules\Workflow\Http\Resources\WorkflowTaskResource;
use Modules\Workflow\Models\ApprovalDecision;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowInboxService;
use Modules\Workflow\Services\WorkflowSlaReportService;
use OpenApi\Attributes as OA;

/**
 * The inbox of a person (what waits for them, what they decided, what they asked for) and the SLA report of the company.
 */
final class WorkflowInboxController extends Controller
{
    use ApiResponse;

    /**
     * Inject the collaborators.
     */
    public function __construct(private readonly WorkflowInboxService $inbox) {}

    /**
     * My tasks.
     */
    #[OA\Get(
        path: '/api/v1/workflows/my-tasks',
        operationId: 'getMyWorkflowTasks',
        description: 'The approvals waiting for the caller, soonest deadline first: steps assigned to the caller, to the role the caller holds in the company, and, while an out-of-office delegation is in force, those of the colleague who delegated (only the kinds of approval the delegation covers; `via_delegation_from` says who). Owners and administrators also see steps assigned to a role. `meta.summary` carries the counters for a badge. Steps of instances that already ended never appear.',
        summary: 'My approval tasks',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Inbox'],
        parameters: [
            new OA\Parameter(name: 'state', in: 'query', required: false, description: '`overdue` past the time limit, `delegated` reached me through a delegation, `escalated` was escalated', schema: new OA\Schema(type: 'string', enum: ['overdue', 'delegated', 'escalated'])),
            new OA\Parameter(name: 'definition_code', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'approvable_type', in: 'query', required: false, description: 'Class of the record under approval, as returned by the instance endpoints', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 1, default: 25)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The tasks', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkflowTask')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer'), new OA\Property(property: 'summary', ref: '#/components/schemas/WorkflowTaskSummary')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid filter', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function myTasks(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'state' => ['nullable', 'in:overdue,delegated,escalated'],
            'definition_code' => ['nullable', 'string', 'max:100'],
            'approvable_type' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $tasks = $this->inbox->tasks($request->user(), $filters)->paginate((int) ($filters['per_page'] ?? 25));

        return $this->successResponse(
            WorkflowTaskResource::collection($tasks->getCollection()),
            'Tasks retrieved',
            meta: ['current_page' => $tasks->currentPage(), 'last_page' => $tasks->lastPage(), 'per_page' => $tasks->perPage(), 'total' => $tasks->total(), 'summary' => $this->inbox->summary($request->user())]
        );
    }

    /**
     * My decisions.
     */
    #[OA\Get(
        path: '/api/v1/workflows/my-history',
        operationId: 'getMyWorkflowHistory',
        description: 'The decisions the caller took, in their own name or on behalf of a colleague who delegated, newest first, with the instance each belongs to and its current state.',
        summary: 'My approval history',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Inbox'],
        parameters: [
            new OA\Parameter(name: 'decision', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['approved', 'rejected'])),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 1, default: 25)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The decisions', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkflowHistoryEntry')), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid filter', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function myHistory(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'decision' => ['nullable', 'in:approved,rejected'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $history = $this->inbox->history($request->user(), $filters, (int) ($filters['per_page'] ?? 25));
        $userId = (string) $request->user()->id;

        return $this->successResponse(
            $history->getCollection()->map(static fn (ApprovalDecision $decision): array => [
                'decision_id' => $decision->id,
                'decision' => $decision->decision,
                'comment' => $decision->comment,
                'decided_at' => $decision->decided_at?->toIso8601String(),
                'on_behalf_of' => (string) $decision->user_id === $userId ? null : $decision->user_id,
                'step_id' => $decision->workflow_instance_step_id,
                'workflow_instance_id' => $decision->step?->workflow_instance_id,
                'workflow_code' => $decision->step?->instance?->definition?->code,
                'instance_status' => $decision->step?->instance?->status,
                'approvable_type' => $decision->step?->instance?->approvable_type,
                'approvable_id' => $decision->step?->instance?->approvable_id,
            ])->all(),
            'History retrieved',
            meta: ['current_page' => $history->currentPage(), 'last_page' => $history->lastPage(), 'per_page' => $history->perPage(), 'total' => $history->total()]
        );
    }

    /**
     * My requests.
     */
    #[OA\Get(
        path: '/api/v1/workflows/my-requests',
        operationId: 'getMyWorkflowRequests',
        description: 'The approvals the caller asked for, newest first, with their status.',
        summary: 'Approvals I requested',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Inbox'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'approved', 'rejected', 'cancelled', 'expired'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 1, default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The instances', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkflowInstanceResource')), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid filter', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function myRequests(Request $request): JsonResponse
    {
        $filters = $request->validate(['status' => ['nullable', 'in:pending,in_progress,approved,rejected,cancelled,expired'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $instances = $this->inbox->requests($request->user(), $filters['status'] ?? null)->paginate((int) ($filters['per_page'] ?? 25));

        return $this->successResponse(
            WorkflowInstanceResource::collection($instances->getCollection()),
            'Requests retrieved',
            meta: ['current_page' => $instances->currentPage(), 'last_page' => $instances->lastPage(), 'per_page' => $instances->perPage(), 'total' => $instances->total()]
        );
    }

    /**
     * SLA report.
     */
    #[OA\Get(
        path: '/api/v1/workflows/sla-report',
        operationId: 'getWorkflowSlaReport',
        description: 'Service level of the approvals of the company for steps opened in a period (default: the last 30 days, at most 366): how many were decided inside their time limit, how many late or timed out, how many are waiting past their limit, and the average hours to decide (null when nothing was decided). Steps closed because the node was left or the instance cancelled are not counted. Owners and administrators only.',
        summary: 'Approval SLA report',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Inbox'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The report', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WorkflowSlaReport')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and administrators', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid period', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function slaReport(Request $request, WorkflowSlaReportService $report, TenantClock $clock): JsonResponse
    {
        $this->authorize('viewSla', WorkflowInstance::class);

        $validated = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $to = isset($validated['to']) ? CarbonImmutable::parse($validated['to']) : $clock->today();
        $from = isset($validated['from']) ? CarbonImmutable::parse($validated['from']) : $to->subDays(30);

        if ($from->diffInDays($to) > 366) {
            throw ValidationException::withMessages(['to' => 'The period cannot be longer than 366 days.']);
        }

        return $this->successResponse($report->report($from, $to), 'SLA report retrieved');
    }
}
