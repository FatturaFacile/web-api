<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Appointments\CancelAppointmentAction;
use App\Actions\Appointments\ConvertAppointmentToServiceReportAction;
use App\Actions\Appointments\ScheduleAppointmentAction;
use App\Actions\Appointments\UpdateAppointmentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Appointments\CalendarQueryRequest;
use App\Http\Requests\Appointments\CancelAppointmentRequest;
use App\Http\Requests\Appointments\ConflictCheckRequest;
use App\Http\Requests\Appointments\StoreAppointmentRequest;
use App\Http\Requests\Appointments\UpdateAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\ServiceReportResource;
use App\Models\Appointment;
use App\Services\Appointments\AppointmentConflictService;
use App\Services\Appointments\AppointmentSchedulingService;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Appointments', description: 'Technician scheduling, calendar view, conflict checking, and service report conversion')]
class AppointmentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly AppointmentSchedulingService $schedulingService,
        private readonly AppointmentConflictService $conflictService,
    ) {}

    /**
     * List appointments with optional filters and pagination.
     */
    #[OA\Get(
        path: '/api/v1/appointments',
        summary: 'List appointments',
        description: 'Get paginated list of appointments with filters',
        tags: ['Appointments'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'technician_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'contact_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'ticket_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'status_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Appointments list'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Appointment::class);

        $query = Appointment::query()
            ->with(['contact', 'ticket', 'attendees.user', 'status'])
            ->orderBy('starts_at', 'desc');

        if ($request->filled('technician_id')) {
            $query->forTechnician($request->string('technician_id')->value());
        }

        if ($request->filled('contact_id')) {
            $query->where('contact_id', $request->string('contact_id')->value());
        }

        if ($request->filled('ticket_id')) {
            $query->where('ticket_id', $request->string('ticket_id')->value());
        }

        if ($request->filled('status_id')) {
            $query->where('status_id', $request->string('status_id')->value());
        }

        if ($request->filled('date')) {
            $date = Carbon::parse($request->string('date')->value());
            $query->whereDate('starts_at', $date);
        }

        $perPage = (int) $request->input('per_page', 25);

        return AppointmentResource::collection($query->gridPaginate($perPage));
    }

    /**
     * Calendar view: retrieve all events in an interval for day/week/month/agenda views.
     */
    #[OA\Get(
        path: '/api/v1/appointments/calendar',
        summary: 'Calendar view',
        description: 'Retrieve all events in an interval for day/week/month views',
        tags: ['Appointments'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'start', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'end', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'technician_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'contact_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'status_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Calendar events list'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function calendar(CalendarQueryRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Appointment::class);

        $start = Carbon::parse($request->string('start')->value());
        $end = Carbon::parse($request->string('end')->value());

        $events = $this->schedulingService->getCalendarEvents(
            $start,
            $end,
            $request->input('technician_id'),
            $request->input('contact_id'),
            $request->input('status_id')
        );

        return AppointmentResource::collection($events);
    }

    /**
     * Real-time pre-flight conflict check for dispatch UI.
     */
    #[OA\Post(
        path: '/api/v1/appointments/check-conflicts',
        summary: 'Check appointment conflicts',
        description: 'Real-time pre-flight conflict check for scheduling',
        tags: ['Appointments'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['starts_at', 'ends_at', 'user_ids'],
                properties: [
                    new OA\Property(property: 'starts_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'ends_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'user_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
                    new OA\Property(property: 'ignore_appointment_id', type: 'string', format: 'uuid'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Conflict check result'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function checkConflicts(ConflictCheckRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Appointment::class);

        $startsAt = Carbon::parse($request->string('starts_at')->value());
        $endsAt = Carbon::parse($request->string('ends_at')->value());
        $userIds = (array) $request->input('user_ids');
        $ignoreId = $request->input('ignore_appointment_id');

        $result = $this->conflictService->detectConflicts($userIds, $startsAt, $endsAt, $ignoreId);

        return response()->json($result);
    }

    /**
     * Schedule a new appointment.
     */
    #[OA\Post(
        path: '/api/v1/appointments',
        summary: 'Schedule appointment',
        description: 'Schedule a new appointment',
        tags: ['Appointments'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['starts_at', 'ends_at'],
                properties: [
                    new OA\Property(property: 'starts_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'ends_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'ticket_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'description', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Appointment created successfully'),
            new OA\Response(response: 422, description: 'Validation error or conflict'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function store(StoreAppointmentRequest $request, ScheduleAppointmentAction $action): JsonResponse
    {
        $this->authorize('create', Appointment::class);

        $appointment = $action->execute($request->validated());

        return (new AppointmentResource($appointment))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Show appointment details.
     */
    #[OA\Get(
        path: '/api/v1/appointments/{id}',
        summary: 'Get appointment details',
        description: 'Retrieve details for a single appointment',
        tags: ['Appointments'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Appointment details'),
            new OA\Response(response: 404, description: 'Appointment not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function show(string $id): AppointmentResource
    {
        /** @var Appointment $appointment */
        $appointment = Appointment::with(['contact', 'ticket', 'attendees.user', 'status', 'attachments'])
            ->findOrFail($id);

        $this->authorize('view', $appointment);

        return new AppointmentResource($appointment);
    }

    /**
     * Update an appointment.
     */
    #[OA\Patch(
        path: '/api/v1/appointments/{id}',
        operationId: 'patchAppointment',
        description: 'Updates an existing appointment of the active tenant.',
        summary: 'Update appointment',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Appointments'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the appointment', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/UpdateAppointmentRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AppointmentResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    #[OA\Put(
        path: '/api/v1/appointments/{id}',
        summary: 'Update appointment',
        description: 'Update appointment details or rescheduling',
        tags: ['Appointments'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'starts_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'ends_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'description', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Appointment updated successfully'),
            new OA\Response(response: 404, description: 'Appointment not found'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function update(UpdateAppointmentRequest $request, string $id, UpdateAppointmentAction $action): AppointmentResource
    {
        /** @var Appointment $appointment */
        $appointment = Appointment::with(['attendees'])->findOrFail($id);

        $this->authorize('update', $appointment);

        $updated = $action->execute($appointment, $request->validated());

        return new AppointmentResource($updated);
    }

    /**
     * Soft delete an appointment.
     */
    #[OA\Delete(
        path: '/api/v1/appointments/{id}',
        summary: 'Delete appointment',
        description: 'Delete an appointment',
        tags: ['Appointments'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Appointment deleted successfully'),
            new OA\Response(response: 404, description: 'Appointment not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        /** @var Appointment $appointment */
        $appointment = Appointment::findOrFail($id);

        $this->authorize('delete', $appointment);

        $appointment->delete();

        return response()->json(['message' => 'Appointment deleted successfully.']);
    }

    /**
     * Cancel an appointment.
     */
    #[OA\Post(
        path: '/api/v1/appointments/{id}/cancel',
        summary: 'Cancel appointment',
        description: 'Cancel an appointment with a given reason',
        tags: ['Appointments'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason'],
                properties: [
                    new OA\Property(property: 'reason', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Appointment cancelled successfully'),
            new OA\Response(response: 404, description: 'Appointment not found'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function cancel(CancelAppointmentRequest $request, string $id, CancelAppointmentAction $action): AppointmentResource
    {
        /** @var Appointment $appointment */
        $appointment = Appointment::findOrFail($id);

        $this->authorize('cancel', $appointment);

        $cancelled = $action->execute($appointment, $request->string('reason')->value());

        return new AppointmentResource($cancelled);
    }

    /**
     * Convert completed appointment into an official Service Report.
     */
    #[OA\Post(
        path: '/api/v1/appointments/{id}/convert-to-service-report',
        summary: 'Convert appointment to service report',
        description: 'Convert a completed appointment into a service report',
        tags: ['Appointments'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Service report created from appointment'),
            new OA\Response(response: 404, description: 'Appointment not found'),
            new OA\Response(response: 422, description: 'Unprocessable appointment'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function convertToServiceReport(string $id, ConvertAppointmentToServiceReportAction $action): ServiceReportResource
    {
        /** @var Appointment $appointment */
        $appointment = Appointment::with(['attendees'])->findOrFail($id);

        $this->authorize('convert', $appointment);

        $serviceReport = $action->execute($appointment);

        return new ServiceReportResource($serviceReport->load(['lines', 'technician', 'contact', 'status']));
    }
}
