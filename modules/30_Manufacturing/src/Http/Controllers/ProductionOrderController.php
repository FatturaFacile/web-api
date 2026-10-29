<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Manufacturing\Contracts\ProductionCostingServiceInterface;
use Modules\Manufacturing\Contracts\ProductionExecutionServiceInterface;
use Modules\Manufacturing\Contracts\ProductionPlanningServiceInterface;
use Modules\Manufacturing\DTOs\CreateProductionOrderDTO;
use Modules\Manufacturing\Http\Controllers\Concerns\ResolvesTenant;
use Modules\Manufacturing\Http\Requests\CreateProductionOrderRequest;
use Modules\Manufacturing\Http\Resources\MfgProductionOrderResource;
use Modules\Manufacturing\Models\MfgProductionOrder;

final class ProductionOrderController extends Controller
{
    use ResolvesTenant;

    public function __construct(
        private ProductionPlanningServiceInterface $planningService,
        private ProductionExecutionServiceInterface $executionService,
        private ProductionCostingServiceInterface $costingService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $orders = MfgProductionOrder::where('tenant_id', $tenantId)->latest()->paginate(25);

        return response()->json([
            'data' => MfgProductionOrderResource::collection($orders),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function store(CreateProductionOrderRequest $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $validated = $request->validated();

        $dto = new CreateProductionOrderDTO(
            tenantId: $tenantId,
            mfgItemId: $validated['mfg_item_id'],
            targetQuantity: (float) $validated['target_quantity'],
            plannedStartDate: $validated['planned_start_date'],
            plannedEndDate: $validated['planned_end_date'],
            bomId: $validated['bom_id'] ?? null,
            routingId: $validated['routing_id'] ?? null,
            projectId: $validated['project_id'] ?? null,
            notes: $validated['notes'] ?? null
        );

        $order = $this->planningService->planOrder($dto);

        return response()->json([
            'message' => 'Production order planned successfully.',
            'data' => new MfgProductionOrderResource($order),
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $order = MfgProductionOrder::where('tenant_id', $tenantId)->findOrFail($id);

        return response()->json(['data' => new MfgProductionOrderResource($order)]);
    }

    public function release(Request $request, string $id): JsonResponse
    {
        $order = $this->executionService->releaseOrder($id);

        return response()->json([
            'message' => 'Production order released.',
            'data' => new MfgProductionOrderResource($order),
        ]);
    }

    public function start(Request $request, string $id): JsonResponse
    {
        $order = $this->executionService->startOrder($id);

        return response()->json([
            'message' => 'Production order started.',
            'data' => new MfgProductionOrderResource($order),
        ]);
    }

    public function complete(Request $request, string $id): JsonResponse
    {
        $order = $this->executionService->completeOrder($id);

        return response()->json([
            'message' => 'Production order completed.',
            'data' => new MfgProductionOrderResource($order),
        ]);
    }

    public function close(Request $request, string $id): JsonResponse
    {
        $order = $this->executionService->closeOrder($id);

        return response()->json([
            'message' => 'Production order closed.',
            'data' => new MfgProductionOrderResource($order),
        ]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $reason = (string) $request->input('reason', 'Cancelled by user request');
        $order = $this->executionService->cancelOrder($id, $reason);

        return response()->json([
            'message' => 'Production order cancelled.',
            'data' => new MfgProductionOrderResource($order),
        ]);
    }

    public function costRollup(Request $request, string $id): JsonResponse
    {
        $rollup = $this->costingService->calculateStandardCostRollup($id);
        $actuals = $this->costingService->calculateActualCostAndVariance($id);

        return response()->json([
            'production_order_id' => $id,
            'standard_cost_rollup' => $rollup,
            'actual_cost_and_variance' => $actuals,
        ]);
    }
}
