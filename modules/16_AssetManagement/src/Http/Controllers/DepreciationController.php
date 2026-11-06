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
use OpenApi\Attributes as OA;

final class DepreciationController extends Controller
{
    public function __construct(
        private readonly DepreciationEngineServiceInterface $depreciationEngine,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Returns the paginated depreciation runs of the active tenant, latest first.
     */
    #[OA\Get(
        path: '/api/v1/eam/depreciation/runs',
        operationId: 'getIndexRunsEamDepreciationRuns',
        description: 'Returns the paginated depreciation runs of the active tenant, latest first.',
        summary: 'List depreciation runs',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Depreciation'],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssetManagementDepreciationRunResource')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
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

    /**
     * Executes a depreciation run for the given period.
     */
    #[OA\Post(
        path: '/api/v1/eam/depreciation/runs',
        operationId: 'executeRunEamDepreciationRuns',
        description: 'Executes a depreciation run for the given period.',
        summary: 'Execute depreciation run',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Depreciation'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementRunDepreciationRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Run created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementDepreciationRunResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
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

    /**
     * Returns a depreciation run of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/eam/depreciation/runs/{id}',
        operationId: 'getShowRunEamDepreciationRuns',
        description: 'Returns a depreciation run of the active tenant.',
        summary: 'Get depreciation run',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Depreciation'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the run', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementDepreciationRunResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function showRun(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $run = DepreciationRun::where('tenant_id', $tenant->id)->findOrFail($id);

        return response()->json(['data' => new DepreciationRunResource($run)]);
    }

    /**
     * Returns the depreciation schedules of a fixed asset ordered by period.
     */
    #[OA\Get(
        path: '/api/v1/eam/assets/{id}/depreciation-schedules',
        operationId: 'getAssetSchedulesEamAsset',
        description: 'Returns the depreciation schedules of a fixed asset ordered by period.',
        summary: 'List asset depreciation schedules',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Assets'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the asset', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssetManagementDepreciationScheduleResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
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
