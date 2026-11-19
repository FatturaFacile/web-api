<?php

declare(strict_types=1);

namespace Modules\Transportation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Transportation\Http\Controllers\Concerns\InteractsWithTransportation;
use Modules\Transportation\Http\Requests\TransportOrderRequest;
use Modules\Transportation\Http\Resources\TransportOrderResource;
use Modules\Transportation\Models\TmsTransportOrder;
use Modules\Transportation\Services\TransportOrderService;
use OpenApi\Attributes as OA;

/**
 * Transport orders of the active company.
 */
final class TransportOrderController extends Controller
{
    use InteractsWithTransportation;

    public function __construct(private readonly TransportOrderService $service) {}

    #[OA\Get(path: '/api/v1/transportation/transport-orders', operationId: 'listTransportationTransportOrders', description: 'Transport orders of the active company. Filters: status, carrier_id, trip_id, from, to, search.', summary: 'List transport orders', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['draft', 'planned', 'in_transit', 'delivered', 'cancelled', 'exception'])), new OA\Parameter(name: 'carrier_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'trip_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'from', description: 'Y-m-d', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to', description: 'Y-m-d', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string'))], responses: [
        new OA\Response(response: 200, description: 'Transport orders', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TransportationTransportOrder'))])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TmsTransportOrder::class);
        $filters = $request->validate(['status' => ['nullable', Rule::in(TmsTransportOrder::Statuses)], 'carrier_id' => ['nullable', 'uuid'], 'trip_id' => ['nullable', 'uuid'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'search' => ['nullable', 'string', 'max:100']]);

        return TransportOrderResource::collection(
            TmsTransportOrder::query()
                ->with(['carrier:id,code,name', 'trip:id,number,status'])
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->when(isset($filters['carrier_id']), static fn ($query) => $query->where('carrier_id', $filters['carrier_id']))
                ->when(isset($filters['trip_id']), static fn ($query) => $query->where('trip_id', $filters['trip_id']))
                ->when(isset($filters['from']), static fn ($query) => $query->whereDate('delivery_to', '>=', $filters['from']))
                ->when(isset($filters['to']), static fn ($query) => $query->whereDate('delivery_to', '<=', $filters['to']))
                ->when(isset($filters['search']), static function ($query) use ($filters): void {
                    $term = '%'.mb_strtolower(str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $filters['search'])).'%';
                    $query->where(static fn ($inner) => $inner
                        ->whereRaw("LOWER(COALESCE(number, '')) LIKE ? ESCAPE '!'", [$term])
                        ->orWhereRaw("LOWER(COALESCE(reference, '')) LIKE ? ESCAPE '!'", [$term])
                        ->orWhereRaw("LOWER(COALESCE(origin_name, '')) LIKE ? ESCAPE '!'", [$term])
                        ->orWhereRaw("LOWER(COALESCE(destination_name, '')) LIKE ? ESCAPE '!'", [$term]));
                })
                ->orderByDesc('created_at')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/transportation/transport-orders', operationId: 'storeTransportationTransportOrder', description: 'Create a draft transport order. The number is assigned by the system; the carrier is set with assign-carrier.', summary: 'Create a transport order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TransportationTransportOrderRequest')), responses: [
        new OA\Response(response: 201, description: 'Transport order created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationTransportOrder')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function store(TransportOrderRequest $request): JsonResponse
    {
        $this->authorize('create', TmsTransportOrder::class);

        return (new TransportOrderResource($this->service->create($this->tenantId(), $request->validated())->load(['carrier:id,code,name', 'trip:id,number,status'])))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/transportation/transport-orders/{id}', operationId: 'showTransportationTransportOrder', description: 'Return a transport order.', summary: 'Show a transport order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], responses: [
        new OA\Response(response: 200, description: 'Transport order', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationTransportOrder')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
    ])]
    public function show(string $id): TransportOrderResource
    {
        $record = TmsTransportOrder::query()->with(['carrier:id,code,name', 'trip:id,number,status'])->findOrFail($id);
        $this->authorize('view', $record);

        return new TransportOrderResource($record);
    }

    #[OA\Put(path: '/api/v1/transportation/transport-orders/{id}', operationId: 'updateTransportationTransportOrder', description: 'Change a draft or planned transport order (not once it is on the road).', summary: 'Update a transport order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TransportationTransportOrderRequest')), responses: [
        new OA\Response(response: 200, description: 'Transport order updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationTransportOrder')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function update(TransportOrderRequest $request, string $id): TransportOrderResource
    {
        $record = TmsTransportOrder::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new TransportOrderResource($this->service->update($record, $request->validated())->load(['carrier:id,code,name', 'trip:id,number,status']));
    }

    #[OA\Delete(path: '/api/v1/transportation/transport-orders/{id}', operationId: 'deleteTransportationTransportOrder', description: 'Delete a draft or cancelled transport order (soft delete).', summary: 'Delete a transport order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], responses: [
        new OA\Response(response: 204, description: 'Transport order deleted'),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $record = TmsTransportOrder::query()->findOrFail($id);
        $this->authorize('delete', $record);
        $this->service->delete($record);

        return response()->noContent();
    }
}
