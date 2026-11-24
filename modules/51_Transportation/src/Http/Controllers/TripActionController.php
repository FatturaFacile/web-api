<?php

declare(strict_types=1);

namespace Modules\Transportation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Transportation\Http\Controllers\Concerns\InteractsWithTransportation;
use Modules\Transportation\Http\Resources\TripResource;
use Modules\Transportation\Models\TmsTrip;
use Modules\Transportation\Services\TripService;
use OpenApi\Attributes as OA;

/**
 * Trip planning: consolidate orders with a capacity check, order the stops, then dispatch, run, complete or cancel the trip.
 */
final class TripActionController extends Controller
{
    use InteractsWithTransportation;

    public function __construct(private readonly TripService $trips) {}

    #[OA\Post(path: '/api/v1/transportation/trips/{id}/orders', operationId: 'addOrdersTransportationTrip', description: 'Adds planned orders that are not on a trip to a planned or dispatched trip, with a pickup and a delivery stop each. Refused when the total weight or volume exceeds the capacity of the trip or when an order belongs to another carrier.', summary: 'Consolidate orders on a trip', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['order_ids'], properties: [new OA\Property(property: 'order_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))])), responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationTrip')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function addOrders(Request $request, string $id): TripResource
    {
        $record = TmsTrip::query()->findOrFail($id);
        $this->authorize('update', $record);
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:100'],
            'order_ids.*' => ['uuid', 'distinct'],
        ]);

        return new TripResource($this->trips->addOrders($record, $data['order_ids']));
    }

    #[OA\Delete(path: '/api/v1/transportation/trips/{id}/orders/{orderId}', operationId: 'removeOrderTransportationTrip', description: 'Removes the order and its stops from a planned or dispatched trip; the order stays planned.', summary: 'Take an order off a trip', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId'), new OA\Parameter(name: 'orderId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationTrip')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function removeOrder(string $id, string $orderId): TripResource
    {
        $record = TmsTrip::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new TripResource($this->trips->removeOrder($record, $orderId));
    }

    #[OA\Put(path: '/api/v1/transportation/trips/{id}/stops/sequence', operationId: 'reorderStopsTransportationTrip', description: 'Sets the visiting order of all the stops of a planned or dispatched trip. The list must hold every stop once and each pickup must come before its delivery.', summary: 'Order the stops', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['stop_ids'], properties: [new OA\Property(property: 'stop_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))])), responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationTrip')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function reorderStops(Request $request, string $id): TripResource
    {
        $record = TmsTrip::query()->findOrFail($id);
        $this->authorize('update', $record);
        $data = $request->validate([
            'stop_ids' => ['required', 'array', 'min:1'],
            'stop_ids.*' => ['uuid', 'distinct'],
        ]);

        return new TripResource($this->trips->reorderStops($record, $data['stop_ids']));
    }

    #[OA\Post(path: '/api/v1/transportation/trips/{id}/stops/{stopId}/complete', operationId: 'completeStopTransportationTrip', description: 'Records the arrival and departure at the next pending stop of a trip in progress. A completed pickup puts the order on the road (picked_up event), a completed delivery delivers it (delivered event, on time against its delivery window).', summary: 'Complete a stop', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId'), new OA\Parameter(name: 'stopId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'arrived_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'departed_at', type: 'string', format: 'date-time', nullable: true)])), responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationTrip')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function completeStop(Request $request, string $id, string $stopId): TripResource
    {
        $record = TmsTrip::query()->findOrFail($id);
        $this->authorize('update', $record);
        $data = $request->validate([
            'arrived_at' => ['nullable', 'date'],
            'departed_at' => ['nullable', 'date'],
        ]);

        return new TripResource($this->trips->completeStop($record, $stopId, $data['arrived_at'] ?? null, $data['departed_at'] ?? null));
    }

    #[OA\Post(path: '/api/v1/transportation/trips/{id}/dispatch', operationId: 'dispatchTransportationTrip', description: 'A planned trip with at least one stop is handed to the driver or carrier.', summary: 'Dispatch a trip', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationTrip')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function dispatch(string $id): TripResource
    {
        $record = TmsTrip::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new TripResource($this->trips->dispatch($record));
    }

    #[OA\Post(path: '/api/v1/transportation/trips/{id}/start', operationId: 'startTransportationTrip', description: 'A dispatched trip leaves: it is now in progress.', summary: 'Start a trip', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationTrip')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function start(string $id): TripResource
    {
        $record = TmsTrip::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new TripResource($this->trips->start($record));
    }

    #[OA\Post(path: '/api/v1/transportation/trips/{id}/complete', operationId: 'completeTransportationTrip', description: 'Closes a trip in progress whose stops are all done, with the kilometres driven (the planned ones by default) and the cost. They feed the cost per km of the dashboard.', summary: 'Complete a trip', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'actual_distance_km', type: 'string', example: '420', nullable: true), new OA\Property(property: 'actual_cost', type: 'string', example: '510.00', nullable: true)])), responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationTrip')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function complete(Request $request, string $id): TripResource
    {
        $record = TmsTrip::query()->findOrFail($id);
        $this->authorize('update', $record);
        $data = $request->validate([
            'actual_distance_km' => ['nullable', 'numeric', 'gt:0', 'max:999999'],
            'actual_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ]);

        return new TripResource($this->trips->complete($record, isset($data['actual_distance_km']) ? (string) $data['actual_distance_km'] : null, isset($data['actual_cost']) ? (string) $data['actual_cost'] : null));
    }

    #[OA\Post(path: '/api/v1/transportation/trips/{id}/cancel', operationId: 'cancelTransportationTrip', description: 'Cancels a planned or dispatched trip; its orders go back to planned and free to join another trip.', summary: 'Cancel a trip', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationTrip')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function cancel(string $id): TripResource
    {
        $record = TmsTrip::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new TripResource($this->trips->cancel($record));
    }
}
