<?php

declare(strict_types=1);

namespace Modules\Hospitality\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Hospitality\Http\Requests\ReservationRequest;
use Modules\Hospitality\Http\Resources\HospitalityResource;
use Modules\Hospitality\Models\Reservation;
use Modules\Hospitality\Services\ReservationService;
use OpenApi\Attributes as OA;

/**
 * Table reservations: the day calendar, availability, booking, rescheduling, seating and closing of a booking.
 */
final class ReservationController extends Controller
{
    public function __construct(private readonly ReservationService $reservations) {}

    /**
     * Return the reservations of one calendar day of the venue (its own wall clock), in time order.
     */
    #[OA\Get(path: '/api/v1/hospitality/reservations', operationId: 'listHospitalityReservations', description: 'Return the reservations of one calendar day of the venue (its own wall clock), in time order, optionally of one room or one status.', summary: 'Reservation calendar', security: [['bearerAuth' => []]], tags: ['Hospitality'], parameters: [
        new OA\Parameter(name: 'date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date', example: '2026-11-20')),
        new OA\Parameter(name: 'room_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['booked', 'seated', 'completed', 'cancelled', 'no_show'])),
    ], responses: [
        new OA\Response(response: 200, description: 'Reservations of the day', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordCollection')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function index(ReservationRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Reservation::class);

        return HospitalityResource::collection($this->reservations->calendar((string) $request->validated('date'), $request->validated('room_id'), $request->validated('status')));
    }

    /**
     * Return the active tables that seat the party and are free for the whole interval.
     */
    #[OA\Get(path: '/api/v1/hospitality/reservations/availability', operationId: 'hospitalityReservationAvailability', description: 'Return the active tables that seat the party and are free for the whole interval: no overlapping booking and, when the interval includes now, no open order.', summary: 'Table availability', security: [['bearerAuth' => []]], tags: ['Hospitality'], parameters: [
        new OA\Parameter(name: 'starts_at', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date-time', example: '2026-11-20T19:30:00+01:00')),
        new OA\Parameter(name: 'duration_minutes', in: 'query', required: true, schema: new OA\Schema(type: 'integer', example: 90)),
        new OA\Parameter(name: 'party_size', in: 'query', required: true, schema: new OA\Schema(type: 'integer', example: 4)),
    ], responses: [
        new OA\Response(response: 200, description: 'Free tables, smallest first', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordCollection')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function availability(ReservationRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Reservation::class);

        return HospitalityResource::collection($this->reservations->availableTables((string) $request->validated('starts_at'), (int) $request->validated('duration_minutes'), (int) $request->validated('party_size')));
    }

    /**
     * Book a table for a party. The table may be left out and assigned later; when given it must seat the party and be
     * free for the interval.
     */
    #[OA\Post(path: '/api/v1/hospitality/reservations', operationId: 'storeHospitalityReservation', description: 'Book a table for a party. The table may be left out and assigned later; when given it must seat the party and be free for the interval. The instant carries its offset and is stored in UTC.', summary: 'Book table', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HospitalityReservationRequest')), tags: ['Hospitality'], responses: [
        new OA\Response(response: 201, description: 'Reservation booked', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function store(ReservationRequest $request): JsonResponse
    {
        $this->authorize('create', Reservation::class);

        return (new HospitalityResource($this->reservations->book($request->validated(), $request->user()?->getKey())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Return a reservation.
     */
    #[OA\Get(path: '/api/v1/hospitality/reservations/{id}', operationId: 'showHospitalityReservation', description: 'Return a reservation with its table.', summary: 'Show reservation', security: [['bearerAuth' => []]], tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 200, description: 'Reservation', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
    ])]
    public function show(string $id): HospitalityResource
    {
        return new HospitalityResource($this->reservation($id, 'view')->load('diningTable'));
    }

    /**
     * Move a booked reservation: time, duration, party, table or guest details, with the same checks as a booking.
     */
    #[OA\Put(path: '/api/v1/hospitality/reservations/{id}', operationId: 'updateHospitalityReservation', description: 'Move a booked reservation: time, duration, party, table or guest details, with the same checks as a booking. Only a booked reservation changes.', summary: 'Reschedule reservation', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HospitalityReservationRequest')), tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 200, description: 'Reservation changed', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function update(ReservationRequest $request, string $id): HospitalityResource
    {
        return new HospitalityResource($this->reservations->reschedule($this->reservation($id, 'update'), $request->validated()));
    }

    /**
     * Seat the guests of a booked reservation: opens the order of the table with the party size as covers.
     */
    #[OA\Post(path: '/api/v1/hospitality/reservations/{id}/seat', operationId: 'seatHospitalityReservation', description: 'Seat the guests of a booked reservation: opens the order of the table with the party size as covers. A table can be named now when the booking had none.', summary: 'Seat guests', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/HospitalityReservationSeatRequest')), tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 200, description: 'Guests seated', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function seat(ReservationRequest $request, string $id): HospitalityResource
    {
        return new HospitalityResource($this->reservations->seat($this->reservation($id, 'update'), $request->validated('table_id'), $request->user()?->getKey()));
    }

    /**
     * Cancel, mark no-show or complete a reservation.
     */
    #[OA\Post(path: '/api/v1/hospitality/reservations/{id}/status', operationId: 'statusHospitalityReservation', description: 'Cancel a booked reservation, mark it no-show, or complete a seated one. Cancelling and no-show need a reason.', summary: 'Close reservation', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HospitalityReservationStatusRequest')), tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 200, description: 'Status changed', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function status(ReservationRequest $request, string $id): HospitalityResource
    {
        return new HospitalityResource($this->reservations->transition($this->reservation($id, 'update'), (string) $request->validated('status'), $request->validated('reason')));
    }

    private function reservation(string $id, string $ability): Reservation
    {
        $reservation = Reservation::query()->findOrFail($id);
        $this->authorize($ability, $reservation);

        return $reservation;
    }
}
