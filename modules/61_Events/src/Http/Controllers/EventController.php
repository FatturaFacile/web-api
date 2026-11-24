<?php

declare(strict_types=1);

namespace Modules\Events\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Events\Http\Controllers\Concerns\InteractsWithEvents;
use Modules\Events\Http\Requests\EventRequest;
use Modules\Events\Http\Resources\EventResource;
use Modules\Events\Models\EventRegistration;
use Modules\Events\Models\ManagedEvent;
use Modules\Events\Services\EventLifecycleService;
use Modules\Events\Services\EventReportService;
use OpenApi\Attributes as OA;

/**
 * Events: lifecycle (draft, published, closed, cancelled) and the revenue and attendance report.
 */
final class EventController extends Controller
{
    use InteractsWithEvents;

    public function __construct(private readonly EventLifecycleService $events, private readonly EventReportService $reports) {}

    #[OA\Get(path: '/api/v1/events', operationId: 'listManagedEvents', description: 'Events, the latest start first, with the places taken. Filters: status, from, to (Y-m-d, on the start date), search (code or name).', summary: 'List events', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Events'], responses: [
        new OA\Response(response: 200, description: 'Events', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ManagedEvent'))])),
        new OA\Response(ref: '#/components/responses/EventsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/EventsForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ManagedEvent::class);
        $filters = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', ManagedEvent::Statuses)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return EventResource::collection(
            $this->counted(ManagedEvent::query())
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->when(isset($filters['from']), static fn ($query) => $query->whereDate('starts_at', '>=', $filters['from']))
                ->when(isset($filters['to']), static fn ($query) => $query->whereDate('starts_at', '<=', $filters['to']))
                ->when(isset($filters['search']), static fn ($query) => $query->where(static fn ($inner) => $inner->where('code', 'like', '%'.$filters['search'].'%')->orWhere('name', 'like', '%'.$filters['search'].'%')))
                ->orderByDesc('starts_at')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/events', operationId: 'storeManagedEvent', description: 'Create a draft event. Timestamps are Y-m-d H:i:s. Capacity left out means unlimited; with the waiting list on, registrations beyond capacity wait for a free place.', summary: 'Create an event', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ManagedEventRequest')), tags: ['Events'], responses: [
        new OA\Response(response: 201, description: 'Event created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ManagedEvent')])),
        new OA\Response(ref: '#/components/responses/EventsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/EventsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/EventsUnprocessable', response: 422),
    ])]
    public function store(EventRequest $request): JsonResponse
    {
        $this->authorize('create', ManagedEvent::class);

        return (new EventResource($this->events->create($this->tenantId(), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/events/{id}', operationId: 'showManagedEvent', description: 'Return an event with its sessions and ticket types.', summary: 'Show an event', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Events'], parameters: [new OA\Parameter(ref: '#/components/parameters/EventsId')], responses: [
        new OA\Response(response: 200, description: 'Event', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ManagedEvent')])),
        new OA\Response(ref: '#/components/responses/EventsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/EventsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/EventsNotFound', response: 404),
    ])]
    public function show(string $id): EventResource
    {
        $event = $this->counted(ManagedEvent::query())->with(['sessions', 'ticketTypes' => static fn ($query) => $query->withCount(['registrations as sold_count' => static fn ($inner) => $inner->where('status', EventRegistration::Confirmed)])])->findOrFail($id);
        $this->authorize('view', $event);

        return new EventResource($event);
    }

    #[OA\Put(path: '/api/v1/events/{id}', operationId: 'updateManagedEvent', description: 'Change an event. Raising the capacity promotes waiting registrations; it cannot go below the confirmed registrations.', summary: 'Update an event', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ManagedEventRequest')), tags: ['Events'], parameters: [new OA\Parameter(ref: '#/components/parameters/EventsId')], responses: [
        new OA\Response(response: 200, description: 'Event updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ManagedEvent')])),
        new OA\Response(ref: '#/components/responses/EventsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/EventsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/EventsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/EventsUnprocessable', response: 422),
    ])]
    public function update(EventRequest $request, string $id): EventResource
    {
        $event = ManagedEvent::query()->findOrFail($id);
        $this->authorize('update', $event);

        return new EventResource($this->events->update($event, $request->validated()));
    }

    #[OA\Delete(path: '/api/v1/events/{id}', operationId: 'deleteManagedEvent', description: 'Delete a draft event without registrations (soft delete).', summary: 'Delete an event', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Events'], parameters: [new OA\Parameter(ref: '#/components/parameters/EventsId')], responses: [
        new OA\Response(response: 204, description: 'Event deleted'),
        new OA\Response(ref: '#/components/responses/EventsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/EventsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/EventsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/EventsUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $event = ManagedEvent::query()->findOrFail($id);
        $this->authorize('delete', $event);
        $this->events->delete($event);

        return response()->noContent();
    }

    #[OA\Post(path: '/api/v1/events/{id}/publish', operationId: 'publishManagedEvent', description: 'Open a draft event for registration; it needs at least one active ticket type.', summary: 'Publish an event', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Events'], parameters: [new OA\Parameter(ref: '#/components/parameters/EventsId')], responses: [
        new OA\Response(response: 200, description: 'Event published', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ManagedEvent')])),
        new OA\Response(ref: '#/components/responses/EventsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/EventsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/EventsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/EventsUnprocessable', response: 422),
    ])]
    public function publish(string $id): EventResource
    {
        $event = ManagedEvent::query()->findOrFail($id);
        $this->authorize('update', $event);

        return new EventResource($this->events->publish($event));
    }

    #[OA\Post(path: '/api/v1/events/{id}/close', operationId: 'closeManagedEvent', description: 'Close a published event: registrations stop, check-in keeps working.', summary: 'Close an event', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Events'], parameters: [new OA\Parameter(ref: '#/components/parameters/EventsId')], responses: [
        new OA\Response(response: 200, description: 'Event closed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ManagedEvent')])),
        new OA\Response(ref: '#/components/responses/EventsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/EventsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/EventsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/EventsUnprocessable', response: 422),
    ])]
    public function close(string $id): EventResource
    {
        $event = ManagedEvent::query()->findOrFail($id);
        $this->authorize('update', $event);

        return new EventResource($this->events->close($event));
    }

    #[OA\Post(path: '/api/v1/events/{id}/cancel', operationId: 'cancelManagedEvent', description: 'Cancel a draft or published event; its registrations that are not checked in are cancelled too.', summary: 'Cancel an event', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Events'], parameters: [new OA\Parameter(ref: '#/components/parameters/EventsId')], responses: [
        new OA\Response(response: 200, description: 'Event cancelled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ManagedEvent')])),
        new OA\Response(ref: '#/components/responses/EventsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/EventsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/EventsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/EventsUnprocessable', response: 422),
    ])]
    public function cancel(string $id): EventResource
    {
        $event = ManagedEvent::query()->findOrFail($id);
        $this->authorize('update', $event);

        return new EventResource($this->events->cancel($event));
    }

    #[OA\Get(path: '/api/v1/events/{id}/report', operationId: 'showManagedEventReport', description: 'Revenue and attendance: expected revenue (confirmed registrations), collected revenue (paid ones), outstanding, occupancy, waiting list, cancellations, check-ins and no-shows, in total and per ticket type.', summary: 'Event revenue report', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Events'], parameters: [new OA\Parameter(ref: '#/components/parameters/EventsId')], responses: [
        new OA\Response(response: 200, description: 'Event report', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ManagedEventReport')])),
        new OA\Response(ref: '#/components/responses/EventsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/EventsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/EventsNotFound', response: 404),
    ])]
    public function report(string $id): JsonResponse
    {
        $event = ManagedEvent::query()->findOrFail($id);
        $this->authorize('view', $event);

        return response()->json(['data' => $this->reports->report($event)]);
    }

    /**
     * @param  Builder<ManagedEvent>  $query
     * @return Builder<ManagedEvent>
     */
    private function counted(Builder $query): Builder
    {
        return $query->withCount([
            'registrations as confirmed_count' => static fn ($inner) => $inner->where('status', EventRegistration::Confirmed),
            'registrations as waitlisted_count' => static fn ($inner) => $inner->where('status', EventRegistration::Waitlisted),
        ]);
    }
}
