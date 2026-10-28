<?php

declare(strict_types=1);

namespace App\Actions\Appointments;

use App\Events\Appointments\AppointmentScheduled;
use App\Jobs\Appointments\NotifyTechniciansAssignedJob;
use App\Models\Appointment;
use App\Models\AppointmentAttendee;
use App\Services\Appointments\AppointmentConflictService;
use App\Services\Appointments\AppointmentSchedulingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleAppointmentAction
{
    public function __construct(
        private readonly AppointmentConflictService $conflictService,
        private readonly AppointmentSchedulingService $schedulingService,
    ) {}

    /**
     * Schedule a new appointment and assign technicians.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data): Appointment
    {
        $startsAt = Carbon::parse($data['starts_at']);
        $endsAt = Carbon::parse($data['ends_at']);

        if ($endsAt->lte($startsAt)) {
            throw ValidationException::withMessages([
                'ends_at' => 'The end time must be after the start time.',
            ]);
        }

        $attendeesData = $data['attendees'] ?? [];
        $userIds = array_values(array_unique(array_filter(array_column($attendeesData, 'user_id'))));

        $allowConflicts = (bool) ($data['allow_conflicts'] ?? false);
        if (! $allowConflicts && ! empty($userIds)) {
            $conflictResult = $this->conflictService->detectConflicts($userIds, $startsAt, $endsAt);
            if ($conflictResult['has_conflict']) {
                throw ValidationException::withMessages([
                    'attendees' => 'One or more assigned technicians have a scheduling conflict.',
                    'conflicts' => $conflictResult['conflicts'],
                ]);
            }
        }

        return DB::transaction(function () use ($data, $startsAt, $endsAt, $attendeesData): Appointment {
            $statusId = $data['status_id'] ?? null;
            if (empty($statusId)) {
                $scheduledStatus = $this->schedulingService->getStatusByCode('scheduled');
                $statusId = $scheduledStatus?->id;
            }

            $year = (int) $startsAt->format('Y');
            $number = $this->schedulingService->generateAppointmentNumber($year);

            /** @var Appointment $appointment */
            $appointment = Appointment::create([
                'appointment_number' => $number,
                'status_id' => $statusId,
                'contact_id' => $data['contact_id'],
                'ticket_id' => $data['ticket_id'] ?? null,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'all_day' => (bool) ($data['all_day'] ?? false),
                'address' => $data['address'] ?? null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'estimated_travel_minutes' => (int) ($data['estimated_travel_minutes'] ?? 0),
                'estimated_duration_minutes' => (int) ($data['estimated_duration_minutes'] ?? $startsAt->diffInMinutes($endsAt)),
                'technician_notes' => $data['technician_notes'] ?? null,
                'customer_notes' => $data['customer_notes'] ?? null,
                'is_portal_visible' => (bool) ($data['is_portal_visible'] ?? true),
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            foreach ($attendeesData as $attendee) {
                AppointmentAttendee::create([
                    'appointment_id' => $appointment->id,
                    'user_id' => $attendee['user_id'],
                    'role' => $attendee['role'] ?? 'lead',
                    'is_organizer' => (bool) ($attendee['is_organizer'] ?? false),
                    'status' => $attendee['status'] ?? 'accepted',
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);
            }

            AppointmentScheduled::dispatch($appointment);
            NotifyTechniciansAssignedJob::dispatch($appointment)->afterCommit();

            return $appointment->load(['attendees.user', 'contact', 'ticket', 'status']);
        });
    }
}
