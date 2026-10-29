<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Manufacturing\Contracts\QualityManagementServiceInterface;
use Modules\Manufacturing\DTOs\RecordQualityInspectionDTO;
use Modules\Manufacturing\Http\Controllers\Concerns\ResolvesTenant;
use Modules\Manufacturing\Http\Requests\RecordQualityInspectionRequest;
use Modules\Manufacturing\Http\Resources\MfgQualityInspectionResource;
use Modules\Manufacturing\Models\MfgQualityInspection;
use Modules\Manufacturing\Models\MfgQualityNonConformance;

final class QualityController extends Controller
{
    use ResolvesTenant;

    public function __construct(
        private QualityManagementServiceInterface $qmService
    ) {}

    public function inspections(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $inspections = MfgQualityInspection::where('tenant_id', $tenantId)->latest()->paginate(25);

        return response()->json([
            'data' => MfgQualityInspectionResource::collection($inspections),
            'meta' => [
                'current_page' => $inspections->currentPage(),
                'total' => $inspections->total(),
            ],
        ]);
    }

    public function recordInspection(RecordQualityInspectionRequest $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $validated = $request->validated();

        $dto = new RecordQualityInspectionDTO(
            tenantId: $tenantId,
            qualityPlanId: $validated['quality_plan_id'],
            productionOrderId: $validated['production_order_id'] ?? null,
            operationId: $validated['operation_id'] ?? null,
            inspectorId: $validated['inspector_id'] ?? null,
            sampleSize: (int) ($validated['sample_size'] ?? 1),
            defectCount: (int) ($validated['defect_count'] ?? 0),
            notes: $validated['notes'] ?? null,
            characteristicResults: $validated['characteristic_results'] ?? []
        );

        $inspection = $this->qmService->executeInspection($dto);

        return response()->json([
            'message' => 'Quality inspection recorded.',
            'data' => new MfgQualityInspectionResource($inspection),
        ], 201);
    }

    public function nonConformances(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $ncs = MfgQualityNonConformance::where('tenant_id', $tenantId)->latest()->paginate(25);

        return response()->json(['data' => $ncs]);
    }
}
