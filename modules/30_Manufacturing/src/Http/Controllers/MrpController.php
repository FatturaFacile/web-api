<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Manufacturing\Contracts\MrpServiceInterface;
use Modules\Manufacturing\DTOs\RunMrpDTO;
use Modules\Manufacturing\Http\Controllers\Concerns\ResolvesTenant;
use Modules\Manufacturing\Http\Requests\RunMrpRequest;
use Modules\Manufacturing\Http\Resources\MfgMrpRunResource;
use Modules\Manufacturing\Models\MfgMrpPlannedOrder;
use Modules\Manufacturing\Models\MfgMrpRun;
use Modules\Manufacturing\Models\MfgMrpShortage;

final class MrpController extends Controller
{
    use ResolvesTenant;

    public function __construct(
        private MrpServiceInterface $mrpService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $runs = MfgMrpRun::where('tenant_id', $tenantId)->latest()->paginate(25);

        return response()->json([
            'data' => MfgMrpRunResource::collection($runs),
            'meta' => [
                'current_page' => $runs->currentPage(),
                'total' => $runs->total(),
            ],
        ]);
    }

    public function run(RunMrpRequest $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $validated = $request->validated();

        $dto = new RunMrpDTO(
            tenantId: $tenantId,
            horizonStartDate: $validated['horizon_start_date'],
            horizonEndDate: $validated['horizon_end_date'],
            runType: $validated['run_type'] ?? 'regenerative',
            itemIds: $validated['item_ids'] ?? null,
            executedBy: $request->user()?->id
        );

        $run = $this->mrpService->executeMrpRun($dto);

        return response()->json([
            'message' => 'MRP run executed successfully.',
            'data' => new MfgMrpRunResource($run),
        ], 201);
    }

    public function plannedOrders(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $orders = MfgMrpPlannedOrder::where('tenant_id', $tenantId)->latest()->paginate(50);

        return response()->json(['data' => $orders]);
    }

    public function shortages(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $shortages = MfgMrpShortage::where('tenant_id', $tenantId)->latest()->paginate(50);

        return response()->json(['data' => $shortages]);
    }

    public function convertPlannedOrder(Request $request, string $id): JsonResponse
    {
        $convertedId = $this->mrpService->convertPlannedOrder($id);

        return response()->json([
            'message' => 'Planned order converted successfully.',
            'converted_order_id' => $convertedId,
        ]);
    }
}
