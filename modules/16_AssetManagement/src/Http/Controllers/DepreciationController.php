<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\AssetManagement\Contracts\DepreciationEngineServiceInterface;
use Modules\AssetManagement\DTOs\RunDepreciationDTO;
use Modules\AssetManagement\Http\Requests\DepreciationPreviewRequest;
use Modules\AssetManagement\Http\Requests\ExecuteYearEndBatchRequest;
use Modules\AssetManagement\Http\Requests\RunDepreciationRequest;
use Modules\AssetManagement\Http\Resources\DepreciationRunResource;
use Modules\AssetManagement\Http\Resources\DepreciationScheduleResource;
use Modules\AssetManagement\Models\DepreciationRun;
use Modules\AssetManagement\Models\DepreciationSchedule;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\AssetManagement\Services\ParallelDepreciationScheduleService;
use Modules\AssetManagement\Support\AssetLedger;
use OpenApi\Attributes as OA;

/**
 * Depreciation: period runs (manual, one per period and ledger), simulations, asset schedules and previews.
 */
final class DepreciationController extends Controller
{
    public function __construct(
        private readonly DepreciationEngineServiceInterface $depreciationEngine,
        private readonly TenantContext $tenantContext,
        private readonly ParallelDepreciationScheduleService $parallelSchedules,
    ) {}

    /**
     * Paginated depreciation runs, latest first.
     */
    #[OA\Get(
        path: '/api/v1/eam/depreciation/runs',
        operationId: 'getIndexRunsEamDepreciationRuns',
        description: 'Paginated depreciation runs of the active company, latest first, optionally of one ledger.',
        summary: 'List depreciation runs',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Depreciation'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamLedgerType'), new OA\Parameter(ref: '#/components/parameters/EamPage'), new OA\Parameter(ref: '#/components/parameters/EamPerPage')],
        responses: [
            new OA\Response(response: 200, description: 'Runs', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssetManagementDepreciationRunResource'))])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
        ]
    )]
    public function indexRuns(Request $request): AnonymousResourceCollection
    {
        $query = DepreciationRun::where('tenant_id', $this->tenantContext->getTenantId());

        if ($request->filled('ledger_type')) {
            $query->where('ledger_type', AssetLedger::normalize((string) $request->query('ledger_type')));
        }

        return DepreciationRunResource::collection($query->orderByDesc('executed_at')->gridPaginate(max(1, min(100, (int) $request->query('per_page', '25')))));
    }

    /**
     * Executes the depreciation run of a period and ledger.
     */
    #[OA\Post(
        path: '/api/v1/eam/depreciation/runs',
        operationId: 'executeRunEamDepreciationRuns',
        description: 'Depreciates every depreciable asset for a period (2026-03 monthly, 2026-Q1 quarterly, 2026 yearly) and ledger, once per period. Only the statutory (civil) ledger is posted to the General Ledger, dated on the last day of the period; assets locked by a workflow are skipped and listed in summary_metrics.',
        summary: 'Execute depreciation run',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Depreciation'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementRunDepreciationRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Run executed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementDepreciationRunResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function executeRun(RunDepreciationRequest $request): JsonResponse
    {
        $run = $this->depreciationEngine->executeDepreciationRun($this->runDto($request), $this->actor($request));

        return (new DepreciationRunResource($run))->response()->setStatusCode(201);
    }

    /**
     * Simulates a depreciation run without writing anything.
     */
    #[OA\Post(
        path: '/api/v1/eam/depreciation/simulate',
        operationId: 'simulateEamDepreciationRun',
        description: 'What a run of the period and ledger would charge asset by asset, optionally for one category or asset. Nothing is written or posted.',
        summary: 'Simulate depreciation run',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Depreciation'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementRunDepreciationRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Simulation', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementDepreciationSimulation')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function simulate(RunDepreciationRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->depreciationEngine->simulateRun($this->runDto($request), $request->validated('category_id'))]);
    }

    /**
     * One depreciation run.
     */
    #[OA\Get(
        path: '/api/v1/eam/depreciation/runs/{id}',
        operationId: 'getShowRunEamDepreciationRuns',
        description: 'Returns a depreciation run of the active company.',
        summary: 'Get depreciation run',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Depreciation'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamRunId')],
        responses: [
            new OA\Response(response: 200, description: 'Run', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementDepreciationRunResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
        ]
    )]
    public function showRun(string $id): DepreciationRunResource
    {
        return new DepreciationRunResource(DepreciationRun::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id));
    }

    /**
     * Depreciation charged to an asset, by period.
     */
    #[OA\Get(
        path: '/api/v1/eam/assets/{id}/depreciation-schedules',
        operationId: 'getAssetSchedulesEamAsset',
        description: 'Depreciation charged to the asset, by period, optionally of one ledger.',
        summary: 'List asset depreciation schedules',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Depreciation'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamAssetId'), new OA\Parameter(ref: '#/components/parameters/EamLedgerType'), new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 100)), new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1))],
        responses: [
            new OA\Response(response: 200, description: 'Schedules', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssetManagementDepreciationScheduleResource')), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
        ]
    )]
    public function assetSchedules(Request $request, string $id): AnonymousResourceCollection
    {
        $asset = $this->asset($id);
        $query = DepreciationSchedule::where('tenant_id', $asset->tenant_id)->where('asset_id', $asset->id);

        if ($request->filled('ledger_type')) {
            $query->where('ledger_type', AssetLedger::normalize((string) $request->query('ledger_type')));
        }

        return DepreciationScheduleResource::collection($query->orderBy('ledger_type')->orderBy('period_code')->gridPaginate(max(1, min(100, (int) $request->query('per_page', 100)))));
    }

    /**
     * Projected depreciation schedule of an asset book.
     */
    #[OA\Get(
        path: '/api/v1/eam/assets/{id}/depreciation-preview',
        operationId: 'previewEamAssetDepreciation',
        description: 'Projected depreciation of an asset book from the next period until it is fully depreciated (at most `periods`), with the method, rate and pro-rata convention of the book. Nothing is written.',
        summary: 'Preview asset depreciation schedule',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Depreciation'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/EamAssetId'),
            new OA\Parameter(ref: '#/components/parameters/EamLedgerType'),
            new OA\Parameter(name: 'period_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['monthly', 'quarterly', 'yearly'])),
            new OA\Parameter(name: 'periods', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 600, minimum: 1)),
            new OA\Parameter(name: 'units_per_period', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'decimal')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Projected schedule', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssetManagementDepreciationPreviewRow'))])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function preview(DepreciationPreviewRequest $request, string $id): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['data' => $this->depreciationEngine->previewSchedule(
            $this->asset($id),
            (string) ($data['ledger_type'] ?? AssetLedger::Statutory),
            (string) ($data['period_type'] ?? 'yearly'),
            (int) ($data['periods'] ?? 60),
            (string) ($data['units_per_period'] ?? '0')
        )]);
    }

    /**
     * Multi-year parallel depreciation comparison (Civil vs Fiscal CH/IT + Transition 4.0/5.0).
     */
    #[OA\Get(
        path: '/api/v1/eam/assets/{id}/parallel-schedules',
        operationId: 'parallelSchedulesEamAsset',
        description: 'Side-by-side multi-year Civil (statutory) vs Fiscal (CH AFC / IT D.M. 1988) depreciation schedule comparison with temporary deferred-tax differences and Transition 4.0/5.0 incentives.',
        summary: 'Compare parallel civil and fiscal depreciation schedules',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Depreciation'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/EamAssetId'),
            new OA\Parameter(name: 'period_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['monthly', 'quarterly', 'yearly'])),
            new OA\Parameter(name: 'periods', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 120, minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Parallel schedule comparison', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
        ]
    )]
    public function parallelSchedules(Request $request, string $id): JsonResponse
    {
        $asset = $this->asset($id);
        $periodType = (string) $request->query('period_type', 'yearly');
        $periods = max(1, min(120, (int) $request->query('periods', '30')));

        return response()->json(['data' => $this->parallelSchedules->compare($asset, $periodType, $periods)]);
    }

    /**
     * Year-end batch parallel depreciation run across Civil (posted to GL) and Fiscal (off-ledger) books.
     */
    #[OA\Post(
        path: '/api/v1/eam/depreciation/year-end-batch',
        operationId: 'executeYearEndBatchEamDepreciation',
        description: 'Executes the year-end batch depreciation across both Statutory (civil, posted to GL as Ammortamento a Fondo Ammortamento) and Tax (fiscal) books.',
        summary: 'Execute year-end parallel depreciation batch',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Depreciation'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementExecuteYearEndBatchRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Year-end batch executed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function executeYearEndBatch(ExecuteYearEndBatchRequest $request): JsonResponse
    {
        $data = $request->validated();
        $result = $this->depreciationEngine->executeYearEndBatch(
            (string) $this->tenantContext->getTenantId(),
            (int) $data['year'],
            (bool) ($data['auto_post_to_gl'] ?? true),
            $this->actor($request)
        );

        return response()->json(['data' => [
            'year' => $result['year'],
            'statutory_run' => new DepreciationRunResource($result['statutory_run']),
            'tax_run' => new DepreciationRunResource($result['tax_run']),
            'civil_total' => $result['civil_total'],
            'fiscal_total' => $result['fiscal_total'],
            'temporary_difference' => $result['temporary_difference'],
            'journal_entry_ids' => $result['journal_entry_ids'],
        ]], 201);
    }

    /**
     * Posts a previously calculated statutory depreciation run to the General Ledger.
     */
    #[OA\Post(
        path: '/api/v1/eam/depreciation/runs/{id}/post-to-gl',
        operationId: 'postToGlEamDepreciationRun',
        description: 'Posts all unposted schedules of a statutory (civil) depreciation run to the General Ledger, respecting period locks and idempotency.',
        summary: 'Post depreciation run to General Ledger',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Depreciation'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamRunId')],
        responses: [
            new OA\Response(response: 200, description: 'Run posted to GL', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementDepreciationRunResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function postRunToGl(Request $request, string $id): DepreciationRunResource
    {
        $run = DepreciationRun::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);

        return new DepreciationRunResource($this->depreciationEngine->postRunToGl($run, $this->actor($request)));
    }

    /**
     * The run parameters of a validated request.
     */
    private function runDto(RunDepreciationRequest $request): RunDepreciationDTO
    {
        $data = $request->validated();
        $tenantId = (string) $this->tenantContext->getTenantId();

        return new RunDepreciationDTO(
            tenantId: $tenantId,
            companyId: $tenantId,
            periodCode: (string) $data['period_code'],
            periodType: (string) ($data['period_type'] ?? 'monthly'),
            ledgerType: AssetLedger::normalize($data['ledger_type'] ?? null),
            singleAssetId: $data['single_asset_id'] ?? null,
            unitsProducedByAssetId: array_map('strval', $data['units_produced_by_asset_id'] ?? []),
            autoPostToGl: (bool) ($data['auto_post_to_gl'] ?? true)
        );
    }

    /**
     * The asset of the active company, 404 otherwise.
     */
    private function asset(string $id): FixedAsset
    {
        return FixedAsset::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);
    }

    /**
     * The authenticated user id.
     */
    private function actor(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id !== null ? (string) $id : null;
    }
}
