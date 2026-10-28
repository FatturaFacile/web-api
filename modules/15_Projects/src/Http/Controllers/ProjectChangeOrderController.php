<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Projects\Actions\ExecuteChangeOrderAction;
use Modules\Projects\Contracts\ProjectChangeOrderServiceInterface;
use Modules\Projects\DTOs\ChangeOrderDTO;
use Modules\Projects\Http\Requests\StoreChangeOrderRequest;
use Modules\Projects\Http\Resources\ProjectChangeOrderResource;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectChangeOrder;

final class ProjectChangeOrderController extends Controller
{
    public function __construct(
        private readonly ProjectChangeOrderServiceInterface $changeOrderService
    ) {}

    public function index(string $projectId, Request $request): JsonResponse
    {
        $project = Project::findOrFail($projectId);

        $query = ProjectChangeOrder::where('project_id', $project->id)
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderBy('created_at', 'desc');

        $orders = $query->paginate((int) $request->query('per_page', 15));

        return response()->json([
            'data' => ProjectChangeOrderResource::collection($orders),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function store(string $projectId, StoreChangeOrderRequest $request): JsonResponse
    {
        $project = Project::findOrFail($projectId);

        $dto = new ChangeOrderDTO(
            projectId: $project->id,
            orderNumber: $request->validated('order_number'),
            title: $request->validated('title'),
            scopeDescription: $request->validated('scope_description'),
            costImpactAmount: (float) $request->validated('cost_impact_amount'),
            revenueImpactAmount: (float) ($request->validated('revenue_impact_amount') ?? 0.0),
            scheduleImpactDays: (int) ($request->validated('schedule_impact_days') ?? 0),
            lines: $request->validated('lines', []),
            userId: (string) auth()->id()
        );

        $changeOrder = $this->changeOrderService->createChangeOrder($dto);

        return response()->json([
            'message' => 'Project change order created successfully',
            'data' => new ProjectChangeOrderResource($changeOrder->load('lines')),
        ], 201);
    }

    public function approve(string $id, ExecuteChangeOrderAction $action): JsonResponse
    {
        $changeOrder = ProjectChangeOrder::findOrFail($id);

        $approvedOrder = $action->execute($changeOrder, (string) auth()->id());

        return response()->json([
            'message' => 'Project change order approved and baseline updated',
            'data' => new ProjectChangeOrderResource($approvedOrder),
        ]);
    }
}
