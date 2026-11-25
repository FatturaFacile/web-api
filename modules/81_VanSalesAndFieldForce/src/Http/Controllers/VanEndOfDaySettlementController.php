<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\VanSalesAndFieldForce\Application\Commands\CompleteEndOfDaySettlementCommand;
use Modules\VanSalesAndFieldForce\Application\Commands\CompleteEndOfDaySettlementHandler;
use Modules\VanSalesAndFieldForce\Http\Controllers\Concerns\InteractsWithVanSelling;
use Modules\VanSalesAndFieldForce\Http\Requests\VanEodSettlementPreviewRequest;
use Modules\VanSalesAndFieldForce\Http\Requests\VanEodSettlementStoreRequest;
use Modules\VanSalesAndFieldForce\Http\Resources\VanEodSettlementResource;
use Modules\VanSalesAndFieldForce\Models\VanEodSettlement;
use Modules\VanSalesAndFieldForce\Services\VanEndOfDaySettlementService;
use OpenApi\Attributes as OA;

final class VanEndOfDaySettlementController extends Controller
{
    use InteractsWithVanSelling;

    public function __construct(
        private readonly VanEndOfDaySettlementService $settlementService,
        private readonly CompleteEndOfDaySettlementHandler $completeHandler,
    ) {}

    #[OA\Get(
        path: '/api/v1/van-selling/settlements',
        operationId: 'listVanEodSettlements',
        summary: 'List end-of-day van settlements (Quadratura Fine Giro)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Van Selling'],
        responses: [new OA\Response(response: 200, description: 'EOD settlements')]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', VanEodSettlement::class);
        $vanId = $request->query('van_warehouse_id');

        return VanEodSettlementResource::collection(
            VanEodSettlement::query()
                ->with('vanWarehouse')
                ->where('tenant_id', $this->tenantId())
                ->when(is_string($vanId) && $vanId !== '', static fn ($q) => $q->where('van_warehouse_id', $vanId))
                ->orderByDesc('settlement_date')
                ->orderByDesc('created_at')
                ->paginate($this->perPage())
        );
    }

    #[OA\Post(
        path: '/api/v1/van-selling/settlements/preview',
        operationId: 'previewVanEodSettlement',
        summary: 'Preview end-of-day stock, empties, cold-chain, and cash/POS/TWINT reconciliation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Van Selling'],
        responses: [new OA\Response(response: 200, description: 'EOD settlement preview')]
    )]
    public function preview(VanEodSettlementPreviewRequest $request): JsonResponse
    {
        $this->authorize('viewAny', VanEodSettlement::class);
        $validated = $request->validated();

        return response()->json([
            'data' => $this->settlementService->preview(
                $this->tenantId(),
                (string) $validated['van_warehouse_id'],
                $validated['van_load_manifest_id'] ?? null,
                $validated['settlement_date'] ?? null,
            ),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/van-selling/settlements',
        operationId: 'storeVanEodSettlement',
        summary: 'Complete end-of-day van settlement (keep onboard vs unload to central depot + deposit slip)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Van Selling'],
        responses: [new OA\Response(response: 201, description: 'EOD settlement completed')]
    )]
    public function store(VanEodSettlementStoreRequest $request): JsonResponse
    {
        $this->authorize('create', VanEodSettlement::class);
        $settlement = $this->completeHandler->handle(
            new CompleteEndOfDaySettlementCommand($this->tenantId(), $request->validated(), $this->userId())
        );

        return (new VanEodSettlementResource($settlement))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/van-selling/settlements/{id}',
        operationId: 'showVanEodSettlement',
        summary: 'Show an end-of-day van settlement',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Van Selling'],
        responses: [new OA\Response(response: 200, description: 'EOD settlement')]
    )]
    public function show(string $id): VanEodSettlementResource
    {
        $settlement = VanEodSettlement::query()->with('vanWarehouse')->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('view', $settlement);

        return new VanEodSettlementResource($settlement);
    }
}
