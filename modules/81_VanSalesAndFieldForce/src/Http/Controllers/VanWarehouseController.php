<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\VanSalesAndFieldForce\Application\Commands\CreateVanWarehouseCommand;
use Modules\VanSalesAndFieldForce\Application\Commands\CreateVanWarehouseHandler;
use Modules\VanSalesAndFieldForce\Domain\Enums\VanFiscalDocumentType;
use Modules\VanSalesAndFieldForce\Http\Controllers\Concerns\InteractsWithVanSelling;
use Modules\VanSalesAndFieldForce\Http\Requests\ReserveSectionalBatchRequest;
use Modules\VanSalesAndFieldForce\Http\Requests\VanTemperatureStoreRequest;
use Modules\VanSalesAndFieldForce\Http\Requests\VanWarehouseStoreRequest;
use Modules\VanSalesAndFieldForce\Http\Requests\VanWarehouseUpdateRequest;
use Modules\VanSalesAndFieldForce\Http\Resources\VanWarehouseResource;
use Modules\VanSalesAndFieldForce\Models\VanWarehouse;
use Modules\VanSalesAndFieldForce\Services\DedicatedVanSectionalService;
use Modules\VanSalesAndFieldForce\Services\VanWarehouseManagementService;
use OpenApi\Attributes as OA;

final class VanWarehouseController extends Controller
{
    use InteractsWithVanSelling;

    public function __construct(
        private readonly CreateVanWarehouseHandler $createHandler,
        private readonly VanWarehouseManagementService $vanService,
        private readonly DedicatedVanSectionalService $sectionals,
    ) {}

    #[OA\Get(
        path: '/api/v1/van-selling/vans',
        operationId: 'listVanWarehouses',
        summary: 'List mobile van warehouses with license plates, cold-chain sensors and dedicated fiscal sectionals',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Van Selling'],
        responses: [new OA\Response(response: 200, description: 'Van warehouses list')]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', VanWarehouse::class);
        $status = $request->query('status');

        return VanWarehouseResource::collection(
            VanWarehouse::query()
                ->where('tenant_id', $this->tenantId())
                ->when(is_string($status) && $status !== '', static fn ($q) => $q->where('status', $status))
                ->orderBy('code')
                ->paginate($this->perPage())
        );
    }

    #[OA\Post(
        path: '/api/v1/van-selling/vans',
        operationId: 'storeVanWarehouse',
        summary: 'Create a mobile van warehouse by license plate with dedicated fiscal sectionals',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Van Selling'],
        responses: [new OA\Response(response: 201, description: 'Van warehouse created')]
    )]
    public function store(VanWarehouseStoreRequest $request): JsonResponse
    {
        $this->authorize('create', VanWarehouse::class);
        $van = $this->createHandler->handle(new CreateVanWarehouseCommand($this->tenantId(), $request->validated()));

        return (new VanWarehouseResource($van))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/van-selling/vans/{id}',
        operationId: 'showVanWarehouse',
        summary: 'Show a mobile van warehouse',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Van Selling'],
        responses: [new OA\Response(response: 200, description: 'Van warehouse')]
    )]
    public function show(string $id): VanWarehouseResource
    {
        $van = VanWarehouse::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('view', $van);

        return new VanWarehouseResource($van);
    }

    #[OA\Put(
        path: '/api/v1/van-selling/vans/{id}',
        operationId: 'updateVanWarehouse',
        summary: 'Update a mobile van warehouse',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Van Selling'],
        responses: [new OA\Response(response: 200, description: 'Van warehouse updated')]
    )]
    public function update(VanWarehouseUpdateRequest $request, string $id): VanWarehouseResource
    {
        $van = VanWarehouse::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('update', $van);
        $van->fill($request->validated());
        $van->sync_version = (int) $van->sync_version + 1;
        $van->save();

        return new VanWarehouseResource($van);
    }

    #[OA\Post(
        path: '/api/v1/van-selling/vans/{id}/temperature',
        operationId: 'recordVanTemperature',
        summary: 'Record cold-chain fridge temperature reading on a mobile van warehouse',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Van Selling'],
        responses: [new OA\Response(response: 200, description: 'Temperature recorded')]
    )]
    public function recordTemperature(VanTemperatureStoreRequest $request, string $id): VanWarehouseResource
    {
        $van = VanWarehouse::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('update', $van);
        $validated = $request->validated();

        return new VanWarehouseResource(
            $this->vanService->recordTemperature($van, (float) $validated['temp_celsius'], $validated['notes'] ?? null)
        );
    }

    #[OA\Post(
        path: '/api/v1/van-selling/vans/{id}/reserve-sectional-batch',
        operationId: 'reserveVanSectionalBatch',
        summary: 'Reserve a contiguous batch of definitive fiscal sectional numbers for offline handheld operation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Van Selling'],
        responses: [new OA\Response(response: 200, description: 'Sectional batch reserved')]
    )]
    public function reserveSectionalBatch(ReserveSectionalBatchRequest $request, string $id): JsonResponse
    {
        $van = VanWarehouse::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('update', $van);
        $validated = $request->validated();

        return response()->json([
            'data' => $this->sectionals->reserveOfflineBatch(
                $van,
                VanFiscalDocumentType::from((string) $validated['document_type']),
                (int) ($validated['count'] ?? 25),
            ),
        ]);
    }
}
