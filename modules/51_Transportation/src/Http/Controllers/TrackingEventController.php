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
use Modules\Transportation\Http\Requests\TrackingEventRequest;
use Modules\Transportation\Http\Resources\TrackingEventResource;
use Modules\Transportation\Models\TmsTrackingEvent;
use Modules\Transportation\Services\TrackingEventService;
use OpenApi\Attributes as OA;

/**
 * Tracking events of the active company.
 */
final class TrackingEventController extends Controller
{
    use InteractsWithTransportation;

    public function __construct(private readonly TrackingEventService $service) {}

    #[OA\Get(path: '/api/v1/transportation/tracking-events', operationId: 'listTransportationTrackingEvents', description: 'Tracking events of the active company. Filters: transport_order_id, event_type, from, to.', summary: 'List tracking events', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'transport_order_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'event_type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['picked_up', 'in_transit', 'at_hub', 'out_for_delivery', 'delivered', 'exception', 'delay'])), new OA\Parameter(name: 'from', description: 'Y-m-d', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to', description: 'Y-m-d', in: 'query', schema: new OA\Schema(type: 'string', format: 'date'))], responses: [
        new OA\Response(response: 200, description: 'Tracking events', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TransportationTrackingEvent'))])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TmsTrackingEvent::class);
        $filters = $request->validate(['transport_order_id' => ['nullable', 'uuid'], 'event_type' => ['nullable', Rule::in(TmsTrackingEvent::EventTypes)], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);

        return TrackingEventResource::collection(
            TmsTrackingEvent::query()
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['transport_order_id']), static fn ($query) => $query->where('transport_order_id', $filters['transport_order_id']))
                ->when(isset($filters['event_type']), static fn ($query) => $query->where('event_type', $filters['event_type']))
                ->when(isset($filters['from']), static fn ($query) => $query->whereDate('occurred_at', '>=', $filters['from']))
                ->when(isset($filters['to']), static fn ($query) => $query->whereDate('occurred_at', '<=', $filters['to']))
                ->orderByDesc('occurred_at')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/transportation/tracking-events', operationId: 'storeTransportationTrackingEvent', description: 'Record a tracking event. picked_up, in_transit, at_hub and out_for_delivery put a planned order on the road; delivered closes it (on time against the end of its delivery window); exception flags it; delay changes nothing. Events of a draft, cancelled or delivered order are refused.', summary: 'Create a tracking event', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TransportationTrackingEventRequest')), responses: [
        new OA\Response(response: 201, description: 'Tracking event created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationTrackingEvent')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationUnprocessable', response: 422),
    ])]
    public function store(TrackingEventRequest $request): JsonResponse
    {
        $this->authorize('create', TmsTrackingEvent::class);

        return (new TrackingEventResource($this->service->create($this->tenantId(), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/transportation/tracking-events/{id}', operationId: 'showTransportationTrackingEvent', description: 'Return a tracking event.', summary: 'Show a tracking event', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Transportation'], parameters: [new OA\Parameter(ref: '#/components/parameters/TransportationId')], responses: [
        new OA\Response(response: 200, description: 'Tracking event', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TransportationTrackingEvent')])),
        new OA\Response(ref: '#/components/responses/TransportationUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TransportationForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TransportationNotFound', response: 404),
    ])]
    public function show(string $id): TrackingEventResource
    {
        $record = TmsTrackingEvent::query()->findOrFail($id);
        $this->authorize('view', $record);

        return new TrackingEventResource($record);
    }
}
