<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Manufacturing\Contracts\ShopFloorServiceInterface;
use Modules\Manufacturing\DTOs\RecordShopFloorExecutionDTO;
use Modules\Manufacturing\Http\Controllers\Concerns\ResolvesTenant;
use Modules\Manufacturing\Http\Requests\RecordShopFloorExecutionRequest;
use Modules\Manufacturing\Http\Resources\MfgShopFloorExecutionResource;

final class ShopFloorController extends Controller
{
    use ResolvesTenant;

    public function __construct(
        private ShopFloorServiceInterface $shopFloorService
    ) {}

    public function recordExecution(RecordShopFloorExecutionRequest $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $validated = $request->validated();

        $dto = new RecordShopFloorExecutionDTO(
            tenantId: $tenantId,
            productionOrderId: $validated['production_order_id'],
            productionOrderOperationId: $validated['production_order_operation_id'],
            workCenterId: $validated['work_center_id'],
            operatorId: $validated['operator_id'] ?? null,
            machineId: $validated['machine_id'] ?? null,
            goodUnits: (float) ($validated['good_units'] ?? 0.0),
            scrapUnits: (float) ($validated['scrap_units'] ?? 0.0),
            downtimeReason: $validated['downtime_reason'] ?? null,
            laborLogs: $validated['labor_logs'] ?? [],
            machineLogs: $validated['machine_logs'] ?? []
        );

        $execution = $this->shopFloorService->recordExecution($dto);

        return response()->json([
            'message' => 'Shop floor execution recorded.',
            'data' => new MfgShopFloorExecutionResource($execution),
        ], 201);
    }

    public function completeExecution(Request $request, string $id): JsonResponse
    {
        $goodUnits = (float) $request->input('good_units', 1.0);
        $scrapUnits = (float) $request->input('scrap_units', 0.0);

        $execution = $this->shopFloorService->completeExecution($id, $goodUnits, $scrapUnits);

        return response()->json([
            'message' => 'Shop floor execution completed.',
            'data' => new MfgShopFloorExecutionResource($execution),
        ]);
    }

    public function recordDowntime(Request $request, string $id): JsonResponse
    {
        $minutes = (float) $request->input('downtime_minutes', 15.0);
        $reason = (string) $request->input('reason', 'Machine adjustment');

        $execution = $this->shopFloorService->recordDowntime($id, $minutes, $reason);

        return response()->json([
            'message' => 'Machine downtime recorded.',
            'data' => new MfgShopFloorExecutionResource($execution),
        ]);
    }
}
