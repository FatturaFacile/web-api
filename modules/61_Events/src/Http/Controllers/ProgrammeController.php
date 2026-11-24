<?php

declare(strict_types=1);

namespace Modules\Events\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Modules\Events\Http\Controllers\Concerns\InteractsWithEvents;
use Modules\Events\Http\Requests\SessionRequest;
use Modules\Events\Http\Requests\TicketTypeRequest;
use Modules\Events\Http\Resources\SessionResource;
use Modules\Events\Http\Resources\TicketTypeResource;
use Modules\Events\Models\EventRegistration;
use Modules\Events\Models\EventSession;
use Modules\Events\Models\EventTicketType;
use Modules\Events\Services\EventRegistrationService;
use OpenApi\Attributes as OA;

/**
 * Sessions of the programme and ticket types of an event.
 */
final class ProgrammeController extends Controller
{
    use InteractsWithEvents;

    public function __construct(private readonly EventRegistrationService $registrations) {}

    #[OA\Get(path: '/api/v1/events/sessions', operationId: 'listEventSessions', description: 'Sessions by start time. Filter: event_id.', summary: 'List sessions', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Events'], responses: [
        new OA\Response(response: 200, description: 'Sessions', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/EventSession'))])),
        new OA\Response(ref: '#/components/responses/EventsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/EventsForbidden', response: 403),
    ])]
    public function sessions(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', EventSession::class);
        $filters = $request->validate(['event_id' => ['nullable', 'uuid']]);

        return SessionResource::collection(
            EventSession::query()->where('tenant_id', $this->tenantId())->when(isset($filters['event_id']), static fn ($query) => $query->where('event_id', $filters['event_id']))->orderBy('starts_at')->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/events/sessions', operationId: 'storeEventSession', description: 'Add a session to the programme of an event.', summary: 'Create a session', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/EventSessionRequest')), tags: ['Events'], responses: [
        new OA\Response(response: 201, description: 'Session created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/EventSession')])),
        new OA\Response(ref: '#/components/responses/EventsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/EventsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/EventsUnprocessable', response: 422),
    ])]
    public function storeSession(SessionRequest $request): JsonResponse
    {
        $this->authorize('create', EventSession::class);

        return (new SessionResource(EventSession::create([...$request->validated(), 'tenant_id' => $this->tenantId()])->refresh()))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/events/sessions/{id}', operationId: 'updateEventSession', description: 'Change a session.', summary: 'Update a session', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/EventSessionRequest')), tags: ['Events'], parameters: [new OA\Parameter(ref: '#/components/parameters/EventsId')], responses: [
        new OA\Response(response: 200, description: 'Session updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/EventSession')])),
        new OA\Response(ref: '#/components/responses/EventsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/EventsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/EventsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/EventsUnprocessable', response: 422),
    ])]
    public function updateSession(SessionRequest $request, string $id): SessionResource
    {
        $session = EventSession::query()->findOrFail($id);
        $this->authorize('update', $session);
        $session->update($request->validated());

        if ($session->ends_at->lt($session->starts_at)) {
            throw ValidationException::withMessages(['ends_at' => [__('event_errors.event.period')]]);
        }

        return new SessionResource($session->refresh());
    }

    #[OA\Delete(path: '/api/v1/events/sessions/{id}', operationId: 'deleteEventSession', description: 'Remove a session (soft delete).', summary: 'Delete a session', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Events'], parameters: [new OA\Parameter(ref: '#/components/parameters/EventsId')], responses: [
        new OA\Response(response: 204, description: 'Session deleted'),
        new OA\Response(ref: '#/components/responses/EventsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/EventsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/EventsNotFound', response: 404),
    ])]
    public function destroySession(string $id): Response
    {
        $session = EventSession::query()->findOrFail($id);
        $this->authorize('delete', $session);
        $session->delete();

        return response()->noContent();
    }

    #[OA\Get(path: '/api/v1/events/ticket-types', operationId: 'listEventTicketTypes', description: 'Ticket types with the places sold. Filter: event_id.', summary: 'List ticket types', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Events'], responses: [
        new OA\Response(response: 200, description: 'Ticket types', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/EventTicketType'))])),
        new OA\Response(ref: '#/components/responses/EventsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/EventsForbidden', response: 403),
    ])]
    public function ticketTypes(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', EventTicketType::class);
        $filters = $request->validate(['event_id' => ['nullable', 'uuid']]);

        return TicketTypeResource::collection(
            EventTicketType::query()
                ->withCount(['registrations as sold_count' => static fn ($query) => $query->where('status', EventRegistration::Confirmed)])
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['event_id']), static fn ($query) => $query->where('event_id', $filters['event_id']))
                ->orderBy('price')
                ->orderBy('name')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/events/ticket-types', operationId: 'storeEventTicketType', description: 'Add a ticket type to an event. Quantity left out means unlimited.', summary: 'Create a ticket type', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/EventTicketTypeRequest')), tags: ['Events'], responses: [
        new OA\Response(response: 201, description: 'Ticket type created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/EventTicketType')])),
        new OA\Response(ref: '#/components/responses/EventsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/EventsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/EventsUnprocessable', response: 422),
    ])]
    public function storeTicketType(TicketTypeRequest $request): JsonResponse
    {
        $this->authorize('create', EventTicketType::class);

        return (new TicketTypeResource(EventTicketType::create([...$request->validated(), 'tenant_id' => $this->tenantId()])->refresh()))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/events/ticket-types/{id}', operationId: 'updateEventTicketType', description: 'Change a ticket type. The price does not change registrations already made; more places promote waiting registrations.', summary: 'Update a ticket type', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/EventTicketTypeRequest')), tags: ['Events'], parameters: [new OA\Parameter(ref: '#/components/parameters/EventsId')], responses: [
        new OA\Response(response: 200, description: 'Ticket type updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/EventTicketType')])),
        new OA\Response(ref: '#/components/responses/EventsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/EventsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/EventsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/EventsUnprocessable', response: 422),
    ])]
    public function updateTicketType(TicketTypeRequest $request, string $id): TicketTypeResource
    {
        $ticket = EventTicketType::query()->findOrFail($id);
        $this->authorize('update', $ticket);
        $ticket->update($request->validated());
        $this->registrations->promoteWaitlist((string) $ticket->event_id);

        return new TicketTypeResource($ticket->refresh());
    }

    #[OA\Delete(path: '/api/v1/events/ticket-types/{id}', operationId: 'deleteEventTicketType', description: 'Delete a ticket type that has no registrations (soft delete); otherwise switch it off with is_active.', summary: 'Delete a ticket type', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Events'], parameters: [new OA\Parameter(ref: '#/components/parameters/EventsId')], responses: [
        new OA\Response(response: 204, description: 'Ticket type deleted'),
        new OA\Response(ref: '#/components/responses/EventsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/EventsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/EventsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/EventsUnprocessable', response: 422),
    ])]
    public function destroyTicketType(string $id): Response
    {
        $ticket = EventTicketType::query()->findOrFail($id);
        $this->authorize('delete', $ticket);

        if ($ticket->registrations()->exists()) {
            throw ValidationException::withMessages(['ticket_type' => [__('event_errors.ticket.has_registrations')]]);
        }

        $ticket->delete();

        return response()->noContent();
    }
}
