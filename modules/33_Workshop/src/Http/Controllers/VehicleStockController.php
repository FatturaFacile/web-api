<?php

declare(strict_types=1);

namespace Modules\Workshop\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Workshop\Http\Requests\SaveStockUnitRequest;
use Modules\Workshop\Http\Requests\SellVehicleRequest;
use Modules\Workshop\Http\Resources\WorkshopResource;
use Modules\Workshop\Models\VehicleStockUnit;
use Modules\Workshop\Services\WorkshopService;
use OpenApi\Attributes as OA;

/**
 * New and used vehicles held for sale, and their sale.
 */
final class VehicleStockController extends Controller
{
    public function __construct(private readonly WorkshopService $workshop) {}

    #[OA\Get(path: '/api/v1/workshop/vehicle-stock', operationId: 'listWorkshopVehicleStock', description: 'Return the vehicles for sale, optionally by condition or status.', summary: 'List vehicle stock', security: [['bearerAuth' => []]], tags: ['Workshop'], parameters: [
        new OA\Parameter(name: 'condition', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['new', 'used'])),
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['in_stock', 'reserved', 'sold'])),
    ], responses: [
        new OA\Response(response: 200, description: 'Stock', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordCollection')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', VehicleStockUnit::class);

        return WorkshopResource::collection(VehicleStockUnit::query()
            ->when($request->filled('condition'), fn ($query) => $query->where('condition', $request->string('condition')->toString()))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->orderBy('stock_number')
            ->paginate(max(1, min($request->integer('per_page', 25), 100))));
    }

    #[OA\Post(path: '/api/v1/workshop/vehicle-stock', operationId: 'storeWorkshopVehicleStock', description: 'Add a new or used vehicle to the stock for sale.', summary: 'Add stock vehicle', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkshopStockUnitRequest')), tags: ['Workshop'], responses: [
        new OA\Response(response: 201, description: 'Vehicle added', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function store(SaveStockUnitRequest $request): JsonResponse
    {
        $this->authorize('create', VehicleStockUnit::class);

        $unit = new VehicleStockUnit;
        $unit->fill($request->validated());
        $unit->save();

        return (new WorkshopResource($unit))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/workshop/vehicle-stock/{id}', operationId: 'showWorkshopVehicleStock', description: 'Return a stock vehicle with its sale.', summary: 'Show stock vehicle', security: [['bearerAuth' => []]], tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 200, description: 'Vehicle', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
    ])]
    public function show(string $id): WorkshopResource
    {
        return new WorkshopResource($this->find($id, 'view')->load('sale'));
    }

    #[OA\Put(path: '/api/v1/workshop/vehicle-stock/{id}', operationId: 'updateWorkshopVehicleStock', description: 'Change a stock vehicle that is not sold.', summary: 'Change stock vehicle', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkshopStockUnitRequest')), tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 200, description: 'Vehicle changed', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function update(SaveStockUnitRequest $request, string $id): WorkshopResource
    {
        $unit = $this->find($id, 'update');

        abort_if($unit->status === 'sold', Response::HTTP_UNPROCESSABLE_ENTITY, 'A sold vehicle cannot change.');
        $unit->update($request->validated());

        return new WorkshopResource($unit);
    }

    #[OA\Post(path: '/api/v1/workshop/vehicle-stock/{id}/sell', operationId: 'sellWorkshopVehicle', description: 'Sell a stock vehicle: it joins the vehicles of the buyer and the sale is invoiced through the invoicing flow.', summary: 'Sell vehicle', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkshopSellRequest')), tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 201, description: 'Vehicle sold', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function sell(SellVehicleRequest $request, string $id): JsonResponse
    {
        $sale = $this->workshop->sell($this->find($id, 'bill'), $request->validated());

        return (new WorkshopResource($sale))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    private function find(string $id, string $ability): VehicleStockUnit
    {
        $unit = VehicleStockUnit::query()->findOrFail($id);
        $this->authorize($ability, $unit);

        return $unit;
    }
}
