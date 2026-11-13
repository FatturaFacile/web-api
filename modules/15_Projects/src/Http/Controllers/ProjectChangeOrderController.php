<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Projects\Contracts\ProjectChangeOrderServiceInterface;
use Modules\Projects\DTOs\ChangeOrderDTO;
use Modules\Projects\Http\Controllers\Concerns\PaginatesProjectRecords;
use Modules\Projects\Http\Requests\RejectionRequest;
use Modules\Projects\Http\Requests\StoreChangeOrderRequest;
use Modules\Projects\Http\Resources\ProjectChangeOrderResource;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectChangeOrder;
use Modules\Projects\Support\Money;
use OpenApi\Attributes as OA;

/**
 * Change orders of a project: registration, approval (applied to contract value, budget and schedule) and rejection.
 */
final class ProjectChangeOrderController extends Controller
{
    use PaginatesProjectRecords;

    public function __construct(private readonly ProjectChangeOrderServiceInterface $changeOrderService) {}

    /**
     * Change orders of the project, optionally by status.
     */
    #[OA\Get(path: '/api/v1/projects/{id}/change-orders', operationId: 'projectsChangeOrdersList', summary: 'List project change orders', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId'), new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')), new OA\Parameter(ref: '#/components/parameters/ProjectsPerPage'), new OA\Parameter(ref: '#/components/parameters/ProjectsPage')],
        responses: [
            new OA\Response(response: 200, description: 'Change orders', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProjectsChangeOrder')), new OA\Property(property: 'meta', ref: '#/components/schemas/ProjectsPaginationMeta')])),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
        ])]
    public function index(string $id, Request $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $orders = ProjectChangeOrder::query()
            ->where('project_id', $project->id)
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->with('lines')
            ->orderByDesc('created_at')
            ->gridPaginate($this->perPage($request, 15));

        return $this->paginated($orders, ProjectChangeOrderResource::class);
    }

    /**
     * Registers a change order (submitted, or pending the workflow when configured).
     */
    #[OA\Post(path: '/api/v1/projects/{id}/change-orders', operationId: 'projectsChangeOrdersCreate', summary: 'Create project change order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsStoreChangeOrderRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Change order registered', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsChangeOrder')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function store(string $id, StoreChangeOrderRequest $request): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('manage', $project);
        $data = $request->validated();

        $changeOrder = $this->changeOrderService->createChangeOrder(new ChangeOrderDTO(
            projectId: $project->id,
            orderNumber: $data['order_number'],
            title: $data['title'],
            scopeDescription: $data['scope_description'],
            costImpactAmount: Money::of($data['cost_impact_amount']),
            revenueImpactAmount: Money::of($data['revenue_impact_amount'] ?? 0),
            scheduleImpactDays: (int) ($data['schedule_impact_days'] ?? 0),
            lines: $data['lines'] ?? [],
            userId: (string) $request->user()?->getKey(),
        ));

        return response()->json(['message' => 'Change order registered', 'data' => new ProjectChangeOrderResource($changeOrder)], 201);
    }

    /**
     * Approves the change order and applies it.
     */
    #[OA\Post(path: '/api/v1/projects/change-orders/{orderId}/approve', operationId: 'projectsChangeOrdersApprove', summary: 'Approve project change order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsChangeOrderId')],
        responses: [
            new OA\Response(response: 200, description: 'Change order approved and applied', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsChangeOrder')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
        ])]
    public function approve(string $orderId, Request $request): JsonResponse
    {
        $order = ProjectChangeOrder::findOrFail($orderId);
        $this->authorize('approve', $order);

        return response()->json(['message' => 'Change order approved and applied', 'data' => new ProjectChangeOrderResource($this->changeOrderService->approveChangeOrder($order, (string) $request->user()?->getKey())->load('lines'))]);
    }

    /**
     * Rejects the change order; nothing is applied.
     */
    #[OA\Post(path: '/api/v1/projects/change-orders/{orderId}/reject', operationId: 'projectsChangeOrdersReject', summary: 'Reject project change order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Projects'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProjectsChangeOrderId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProjectsRejectionRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Change order rejected', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProjectsChangeOrder')])),
            new OA\Response(response: 403, ref: '#/components/responses/ProjectsForbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/ProjectsNotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/ProjectsConflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ProjectsValidationError'),
        ])]
    public function reject(string $orderId, RejectionRequest $request): JsonResponse
    {
        $order = ProjectChangeOrder::findOrFail($orderId);
        $this->authorize('reject', $order);

        return response()->json(['message' => 'Change order rejected', 'data' => new ProjectChangeOrderResource($this->changeOrderService->rejectChangeOrder($order, (string) $request->user()?->getKey(), (string) $request->validated('reason')))]);
    }
}
