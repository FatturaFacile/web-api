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

        return AppointmentResource::collection($query->paginate($perPage));
    }

    /**
     * Calendar view: retrieve all events in an interval for day/week/month/agenda views.
     */
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
    public function convertToServiceReport(string $id, ConvertAppointmentToServiceReportAction $action): ServiceReportResource
    {
        /** @var Appointment $appointment */
        $appointment = Appointment::with(['attendees'])->findOrFail($id);

        $this->authorize('convert', $appointment);

        $serviceReport = $action->execute($appointment);

        return new ServiceReportResource($serviceReport->load(['lines', 'technician', 'contact', 'status']));
    }
}
