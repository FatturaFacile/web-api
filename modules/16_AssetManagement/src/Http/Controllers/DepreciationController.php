<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Modules\AssetManagement\Contracts\DepreciationEngineServiceInterface;
use Modules\AssetManagement\DTOs\RunDepreciationDTO;
use Modules\AssetManagement\Http\Requests\RunDepreciationRequest;
use Modules\AssetManagement\Http\Resources\DepreciationRunResource;
use Modules\AssetManagement\Http\Resources\DepreciationScheduleResource;
use Modules\AssetManagement\Models\DepreciationRun;
use Modules\AssetManagement\Models\DepreciationSchedule;
use Modules\AssetManagement\Models\FixedAsset;

final class DepreciationController extends Controller
{
    public function __construct(
        private readonly DepreciationEngineServiceInterface $depreciationEngine,
        private readonly TenantContext $tenantContext
    ) {}

    public function indexRuns(): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $runs = DepreciationRun::where('tenant_id', $tenant->id)
            ->orderByDesc('executed_at')
            ->paginate(25);

        return response()->json([
            'data' => DepreciationRunResource::collection($runs),
            'meta' => [
                'current_page' => $runs->currentPage(),
                'total' => $runs->total(),
            ],
        ]);
    }

    public function executeRun(RunDepreciationRequest $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $actorId = (string) ($request->user()?->id ?? '');

        $run = $this->depreciationEngine->executeDepreciationRun(new RunDepreciationDTO(
            tenantId: (string) $tenant->id,
            companyId: (string) $tenant->id,
            periodCode: (string) $request->input('period_code'),
            periodType: (string) $request->input('period_type', 'monthly'),
            ledgerType: (string) $request->input('ledger_type', 'statutory'),
            singleAssetId: $request->input('single_asset_id'),
            unitsProducedByAssetId: $request->input('units_produced_by_asset_id', []),
            autoPostToGl: (bool) $request->input('auto_post_to_gl', true)
        ), $actorId);

        return response()->json(['data' => new DepreciationRunResource($run)], 201);
    }

    public function showRun(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $run = DepreciationRun::where('tenant_id', $tenant->id)->findOrFail($id);

        return response()->json(['data' => new DepreciationRunResource($run)]);
    }

    public function assetSchedules(string $assetId): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        FixedAsset::where('tenant_id', $tenant->id)->findOrFail($assetId);

        $schedules = DepreciationSchedule::where('tenant_id', $tenant->id)
            ->where('asset_id', $assetId)
            ->orderBy('period_code')
            ->get();

        return response()->json(['data' => DepreciationScheduleResource::collection($schedules)]);
    }
}
