<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\VanSalesAndFieldForce\Application\Commands\ProcessOfflineVanDeltaSyncCommand;
use Modules\VanSalesAndFieldForce\Application\Commands\ProcessOfflineVanDeltaSyncHandler;
use Modules\VanSalesAndFieldForce\Application\Commands\RecordRouteVisitOutcomeCommand;
use Modules\VanSalesAndFieldForce\Application\Commands\RecordRouteVisitOutcomeHandler;
use Modules\VanSalesAndFieldForce\Http\Controllers\Concerns\InteractsWithVanSelling;
use Modules\VanSalesAndFieldForce\Http\Requests\OfflineDeltaSyncRequest;
use Modules\VanSalesAndFieldForce\Http\Requests\VanVisitOutcomeStoreRequest;
use Modules\VanSalesAndFieldForce\Http\Resources\VanVisitOutcomeResource;
use Modules\VanSalesAndFieldForce\Models\VanVisitOutcome;
use Modules\VanSalesAndFieldForce\Services\ThermalPrintPayloadBuilderService;
use OpenApi\Attributes as OA;

final class InFieldVisitAndSyncController extends Controller
{
    use InteractsWithVanSelling;

    public function __construct(
        private readonly RecordRouteVisitOutcomeHandler $visitHandler,
        private readonly ProcessOfflineVanDeltaSyncHandler $syncHandler,
        private readonly ThermalPrintPayloadBuilderService $printer,
    ) {}

    #[OA\Get(
        path: '/api/v1/van-selling/visits',
        operationId: 'listVanVisitOutcomes',
        summary: 'List in-field van selling and pre-sale visit outcomes',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Van Selling'],
        responses: [new OA\Response(response: 200, description: 'Visit outcomes')]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', VanVisitOutcome::class);
        $vanId = $request->query('van_warehouse_id');

        return VanVisitOutcomeResource::collection(
            VanVisitOutcome::query()
                ->with(['contact', 'vanWarehouse'])
                ->where('tenant_id', $this->tenantId())
                ->when(is_string($vanId) && $vanId !== '', static fn ($q) => $q->where('van_warehouse_id', $vanId))
                ->orderByDesc('visited_at')
                ->paginate($this->perPage())
        );
    }

    #[OA\Post(
        path: '/api/v1/van-selling/visits',
        operationId: 'storeVanVisitOutcome',
        summary: 'Record an in-field visit outcome with definitive van sectional numbering, empties, and print payload',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Van Selling'],
        responses: [new OA\Response(response: 201, description: 'Visit outcome recorded')]
    )]
    public function store(VanVisitOutcomeStoreRequest $request): JsonResponse
    {
        $this->authorize('create', VanVisitOutcome::class);
        $visit = $this->visitHandler->handle(new RecordRouteVisitOutcomeCommand($this->tenantId(), $request->validated()));

        return (new VanVisitOutcomeResource($visit))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/van-selling/visits/{id}',
        operationId: 'showVanVisitOutcome',
        summary: 'Show an in-field visit outcome',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Van Selling'],
        responses: [new OA\Response(response: 200, description: 'Visit outcome')]
    )]
    public function show(string $id): VanVisitOutcomeResource
    {
        $visit = VanVisitOutcome::query()->with(['contact', 'vanWarehouse'])->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('view', $visit);

        return new VanVisitOutcomeResource($visit);
    }

    #[OA\Get(
        path: '/api/v1/van-selling/visits/{id}/thermal-print',
        operationId: 'getVanVisitThermalPrint',
        summary: 'Get ESC/POS (80mm) and ZPL (104mm) Bluetooth thermal printer payloads for a visit document',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Van Selling'],
        responses: [new OA\Response(response: 200, description: 'Thermal print payload')]
    )]
    public function thermalPrint(string $id): JsonResponse
    {
        $visit = VanVisitOutcome::query()->with(['contact', 'vanWarehouse'])->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('view', $visit);

        $tenantName = (string) (Tenant::query()->whereKey($this->tenantId())->value('legal_name') ?? 'FatturaFacile');
        $payload = $this->printer->buildForVisit($visit, $visit->vanWarehouse, [
            'company_name' => $tenantName,
            'customer_name' => $visit->contact?->company_name ?: 'Customer',
        ]);

        return response()->json(['data' => $payload->toArray()]);
    }

    #[OA\Post(
        path: '/api/v1/van-selling/offline-sync',
        operationId: 'processOfflineVanDeltaSync',
        summary: 'Execute bidirectional encrypted delta sync for offline field sales and temperature logs',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Van Selling'],
        responses: [new OA\Response(response: 200, description: 'Delta sync completed')]
    )]
    public function offlineSync(OfflineDeltaSyncRequest $request): JsonResponse
    {
        $this->authorize('create', VanVisitOutcome::class);
        $result = $this->syncHandler->handle(new ProcessOfflineVanDeltaSyncCommand($this->tenantId(), $request->validated()));

        return response()->json(['data' => $result]);
    }
}
