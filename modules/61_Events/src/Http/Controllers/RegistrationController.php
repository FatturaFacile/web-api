<?php

declare(strict_types=1);

namespace Modules\Events\Http\Controllers;

use App\Enums\BarcodeType;
use App\Http\Controllers\Controller;
use App\Services\Inventory\BarcodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Events\Http\Controllers\Concerns\InteractsWithEvents;
use Modules\Events\Http\Requests\CheckInRequest;
use Modules\Events\Http\Requests\RegistrationRequest;
use Modules\Events\Http\Resources\RegistrationResource;
use Modules\Events\Models\EventRegistration;
use Modules\Events\Models\ManagedEvent;
use Modules\Events\Services\EventRegistrationService;
use OpenApi\Attributes as OA;

/**
 * Registrations of the events: capacity and waiting list, payment, cancellation, QR code and check-in.
 */
final class RegistrationController extends Controller
{
    use InteractsWithEvents;

    public function __construct(private readonly EventRegistrationService $registrations) {}

    #[OA\Get(path: '/api/v1/events/registrations', operationId: 'listEventRegistrations', description: 'Registrations, the oldest first. Filters: event_id, status (confirmed, waitlisted, cancelled), checked_in (boolean), search (attendee name or email).', summary: 'List registrations', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Events'], responses: [
        new OA\Response(response: 200, description: 'Registrations', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/EventRegistration'))])),
        new OA\Response(ref: '#/components/responses/EventsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/EventsForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', EventRegistration::class);
        $filters = $request->validate([
            'event_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:'.implode(',', EventRegistration::Statuses)],
            'checked_in' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return RegistrationResource::collection(
            EventRegistration::query()
                ->with(['event:id,code,name', 'ticketType:id,name'])
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['event_id']), static fn ($query) => $query->where('event_id', $filters['event_id']))
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->when(isset($filters['checked_in']), static fn ($query) => $filters['checked_in'] ? $query->whereNotNull('checked_in_at') : $query->whereNull('checked_in_at'))
                ->when(isset($filters['search']), static fn ($query) => $query->where(static fn ($inner) => $inner->where('attendee_name', 'like', '%'.$filters['search'].'%')->orWhere('attendee_email', 'like', '%'.$filters['search'].'%')))
                ->orderBy('registered_at')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/events/registrations', operationId: 'storeEventRegistration', description: 'Register an attendee for a published event. The registration is confirmed while the event capacity and the quantity of the ticket type allow it, otherwise it joins the waiting list (422 when the waiting list is off). The price is the price of the ticket type today. The response carries the QR token.', summary: 'Register an attendee', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/EventRegistrationRequest')), tags: ['Events'], responses: [
        new OA\Response(response: 201, description: 'Registration created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/EventRegistration')])),
        new OA\Response(ref: '#/components/responses/EventsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/EventsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/EventsUnprocessable', response: 422),
    ])]
    public function store(RegistrationRequest $request): JsonResponse
    {
        $this->authorize('create', EventRegistration::class);
        $event = ManagedEvent::query()->findOrFail($request->validated('event_id'));

        return (new RegistrationResource($this->registrations->register($this->tenantId(), $event, $request->validated())->load(['event:id,code,name', 'ticketType:id,name'])))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/events/registrations/{id}', operationId: 'showEventRegistration', description: 'Return a registration.', summary: 'Show a registration', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Events'], parameters: [new OA\Parameter(ref: '#/components/parameters/EventsId')], responses: [
        new OA\Response(response: 200, description: 'Registration', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/EventRegistration')])),
        new OA\Response(ref: '#/components/responses/EventsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/EventsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/EventsNotFound', response: 404),
    ])]
    public function show(string $id): RegistrationResource
    {
        $registration = EventRegistration::query()->with(['event:id,code,name', 'ticketType:id,name'])->findOrFail($id);
        $this->authorize('view', $registration);

        return new RegistrationResource($registration);
    }

    #[OA\Put(path: '/api/v1/events/registrations/{id}', operationId: 'updateEventRegistration', description: 'Change the attendee data; the event and the ticket type are fixed.', summary: 'Update a registration', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/EventRegistrationRequest')), tags: ['Events'], parameters: [new OA\Parameter(ref: '#/components/parameters/EventsId')], responses: [
        new OA\Response(response: 200, description: 'Registration updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/EventRegistration')])),
        new OA\Response(ref: '#/components/responses/EventsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/EventsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/EventsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/EventsUnprocessable', response: 422),
    ])]
    public function update(RegistrationRequest $request, string $id): RegistrationResource
    {
        $registration = EventRegistration::query()->findOrFail($id);
        $this->authorize('update', $registration);
        $registration->update($request->validated());

        return new RegistrationResource($registration->refresh()->load(['event:id,code,name', 'ticketType:id,name']));
    }

    #[OA\Post(path: '/api/v1/events/registrations/{id}/cancel', operationId: 'cancelEventRegistration', description: 'Cancel a registration that is not checked in; a freed place goes to the oldest waiting registration that fits.', summary: 'Cancel a registration', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Events'], parameters: [new OA\Parameter(ref: '#/components/parameters/EventsId')], responses: [
        new OA\Response(response: 200, description: 'Registration cancelled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/EventRegistration')])),
        new OA\Response(ref: '#/components/responses/EventsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/EventsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/EventsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/EventsUnprocessable', response: 422),
    ])]
    public function cancel(string $id): RegistrationResource
    {
        $registration = EventRegistration::query()->findOrFail($id);
        $this->authorize('update', $registration);

        return new RegistrationResource($this->registrations->cancel($registration));
    }

    #[OA\Post(path: '/api/v1/events/registrations/{id}/pay', operationId: 'payEventRegistration', description: 'Record the payment of a confirmed registration; it counts as collected revenue in the report.', summary: 'Mark a registration as paid', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Events'], parameters: [new OA\Parameter(ref: '#/components/parameters/EventsId')], responses: [
        new OA\Response(response: 200, description: 'Registration paid', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/EventRegistration')])),
        new OA\Response(ref: '#/components/responses/EventsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/EventsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/EventsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/EventsUnprocessable', response: 422),
    ])]
    public function pay(string $id): RegistrationResource
    {
        $registration = EventRegistration::query()->findOrFail($id);
        $this->authorize('update', $registration);

        return new RegistrationResource($this->registrations->markPaid($registration));
    }

    #[OA\Get(path: '/api/v1/events/registrations/{id}/qr', operationId: 'showEventRegistrationQr', description: 'The QR code of the registration as a PNG data URI, to print on the ticket or send to the attendee. It encodes the QR token.', summary: 'QR code of a registration', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Events'], parameters: [new OA\Parameter(ref: '#/components/parameters/EventsId')], responses: [
        new OA\Response(response: 200, description: 'QR code', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/EventRegistrationQr')])),
        new OA\Response(ref: '#/components/responses/EventsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/EventsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/EventsNotFound', response: 404),
    ])]
    public function qr(string $id): JsonResponse
    {
        $registration = EventRegistration::query()->findOrFail($id);
        $this->authorize('view', $registration);

        return response()->json(['data' => ['qr_token' => $registration->qr_token, 'data_uri' => app(BarcodeService::class)->pngDataUri(BarcodeType::QrCode, $registration->qr_token, 4)]]);
    }

    #[OA\Post(path: '/api/v1/events/check-in', operationId: 'checkInEventRegistration', description: 'Check an attendee in by the QR token. Only confirmed registrations can check in, once; unknown tokens, waiting or cancelled registrations and a second check-in are refused with 422.', summary: 'Check in by QR token', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/EventCheckInRequest')), tags: ['Events'], responses: [
        new OA\Response(response: 200, description: 'Checked in', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/EventRegistration')])),
        new OA\Response(ref: '#/components/responses/EventsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/EventsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/EventsUnprocessable', response: 422),
    ])]
    public function checkIn(CheckInRequest $request): RegistrationResource
    {
        $this->authorize('create', EventRegistration::class);
        $data = $request->validated();
        $registration = $this->registrations->checkIn($this->tenantId(), (string) $data['qr_token'], $data['event_id'] ?? null, $this->userId());

        return new RegistrationResource($registration->load(['event:id,code,name', 'ticketType:id,name']));
    }
}
