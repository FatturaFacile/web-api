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
use Modules\Transportation\Http\Requests\TripRequest;
use Modules\Transportation\Http\Resources\TripResource;
use Modules\Transportation\Models\TmsTrip;
use Modules\Transportation\Services\TripService;
use OpenApi\Attributes as OA;

/**
 * Trips of the active company.
 */
final class TripController extends Controller
{
    use InteractsWithTransportation;

    public function __construct(private readonly TripService $service) {}

    #[OA\Get(path: '/api/v1/transportation/trips', operationId: 'listTransportationTrips', description: 'Trips of the active company. Filters: carrier_id, status, vehicle_id, from, to.', summary: 'List trips', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'carrier_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['planned', 'dispatched', 'in_progress', 'completed', 'cancelled'])), new OA\Parameter(name: 'vehicle_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'from', description: 'Y-m-d', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to', description: 'Y-m-d', in: 'query', schema: new OA\Schema(type: 'string', format: 'date'))], responses: [
        new OA\Response(response: 200, description: 'Trips', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TransportationTrip'))])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TmsTrip::class);
        $filters = $request->validate(['carrier_id' => ['nullable', 'uuid'], 'status' => ['nullable', Rule::in(TmsTrip::Statuses)], 'vehicle_id' => ['nullable', 'uuid'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);

        return TripResource::collection(
            TmsTrip::query()
                ->with(['carrier:id,code,name', 'stops'])
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['carrier_id']), static fn ($query) => $query->where('carrier_id', $filters['carrier_id']))
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->when(isset($filters['vehicle_id']), static fn ($query) => $query->where('vehicle_id', $filters['vehicle_id']))
                ->when(isset($filters['from']), static fn ($query) => $query->whereDate('planned_start_at', '>=', $filters['from']))
                ->when(isset($filters['to']), static fn ($query) => $query->whereDate('planned_start_at', '<=', $filters['to']))
                ->orderByDesc('created_at')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/transportation/trips', operationId: 'storeTransportationTrip', description: 'Create a planned trip. Add transport orders with the orders endpoint: they are checked against the capacity of the trip.', summary: 'Create a trip', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TransportationTripRequest')), responses: [
        new OA\Response(response: 201, description: 'Trip created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationTrip')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function store(TripRequest $request): JsonResponse
    {
        $this->authorize('create', TmsTrip::class);

        return (new TripResource($this->service->create($this->tenantId(), $request->validated())->load(['carrier:id,code,name', 'stops'])))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/transportation/trips/{id}', operationId: 'showTransportationTrip', description: 'Return a trip.', summary: 'Show a trip', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], responses: [
        new OA\Response(response: 200, description: 'Trip', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationTrip')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
    ])]
    public function show(string $id): TripResource
    {
        $record = TmsTrip::query()->with(['carrier:id,code,name', 'stops'])->findOrFail($id);
        $this->authorize('view', $record);

        return new TripResource($record);
    }

    #[OA\Put(path: '/api/v1/transportation/trips/{id}', operationId: 'updateTransportationTrip', description: 'Change a planned or dispatched trip. The capacity can never go below the load already consolidated.', summary: 'Update a trip', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TransportationTripRequest')), responses: [
        new OA\Response(response: 200, description: 'Trip updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationTrip')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function update(TripRequest $request, string $id): TripResource
    {
        $record = TmsTrip::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new TripResource($this->service->update($record, $request->validated())->load(['carrier:id,code,name', 'stops']));
    }

    #[OA\Delete(path: '/api/v1/transportation/trips/{id}', operationId: 'deleteTransportationTrip', description: 'Delete a planned trip that carries no order (soft delete).', summary: 'Delete a trip', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], responses: [
        new OA\Response(response: 204, description: 'Trip deleted'),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $record = TmsTrip::query()->findOrFail($id);
        $this->authorize('delete', $record);
        $this->service->delete($record);

        return response()->noContent();
    }
}
