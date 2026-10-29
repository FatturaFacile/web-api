<?php

declare(strict_types=1);

namespace App\Actions\Appointments;

use App\Events\Appointments\AppointmentUpdated;
use App\Models\Appointment;
use App\Models\AppointmentAttendee;
use App\Services\Appointments\AppointmentConflictService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateAppointmentAction
{
    public function __construct(
        private readonly AppointmentConflictService $conflictService,
    ) {}

    /**
     * Update an existing appointment and synchronise attendees.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(Appointment $appointment, array $data): Appointment
    {
        $startsAt = isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : $appointment->starts_at;
        $endsAt = isset($data['ends_at']) ? Carbon::parse($data['ends_at']) : $appointment->ends_at;

        if ($endsAt->lte($startsAt)) {
            throw ValidationException::withMessages([
                'ends_at' => 'The end time must be after the start time.',
            ]);
        }

        $attendeesData = $data['attendees'] ?? null;
        if ($attendeesData !== null) {
            $userIds = array_values(array_unique(array_filter(array_column($attendeesData, 'user_id'))));
        } else {
            $userIds = $appointment->attendees()->pluck('user_id')->all();
        }

        $allowConflicts = (bool) ($data['allow_conflicts'] ?? false);
        if (! $allowConflicts && ! empty($userIds)) {
            $conflictResult = $this->conflictService->detectConflicts($userIds, $startsAt, $endsAt, $appointment->id);
            if ($conflictResult['has_conflict']) {
                throw ValidationException::withMessages([
                    'attendees' => 'One or more assigned technicians have a scheduling conflict.',
                    'conflicts' => $conflictResult['conflicts'],
                ]);
            }
        }

        return DB::transaction(function () use ($appointment, $data, $startsAt, $endsAt, $attendeesData): Appointment {
            $fields = [
                'title' => $data['title'] ?? $appointment->title,
                'description' => array_key_exists('description', $data) ? $data['description'] : $appointment->description,
                'status_id' => $data['status_id'] ?? $appointment->status_id,
                'contact_id' => $data['contact_id'] ?? $appointment->contact_id,
                'ticket_id' => array_key_exists('ticket_id', $data) ? $data['ticket_id'] : $appointment->ticket_id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'all_day' => array_key_exists('all_day', $data) ? (bool) $data['all_day'] : $appointment->all_day,
                'address' => array_key_exists('address', $data) ? $data['address'] : $appointment->address,
                'latitude' => array_key_exists('latitude', $data) ? $data['latitude'] : $appointment->latitude,
                'longitude' => array_key_exists('longitude', $data) ? $data['longitude'] : $appointment->longitude,
                'estimated_travel_minutes' => array_key_exists('estimated_travel_minutes', $data) ? (int) $data['estimated_travel_minutes'] : $appointment->estimated_travel_minutes,
                'estimated_duration_minutes' => array_key_exists('estimated_duration_minutes', $data) ? (int) $data['estimated_duration_minutes'] : $startsAt->diffInMinutes($endsAt),
                'technician_notes' => array_key_exists('technician_notes', $data) ? $data['technician_notes'] : $appointment->technician_notes,
                'customer_notes' => array_key_exists('customer_notes', $data) ? $data['customer_notes'] : $appointment->customer_notes,
                'is_portal_visible' => array_key_exists('is_portal_visible', $data) ? (bool) $data['is_portal_visible'] : $appointment->is_portal_visible,
                'updated_by' => Auth::id(),
            ];

            $appointment->update($fields);

            if ($attendeesData !== null) {
                $appointment->attendees()->delete();

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
            }

            AppointmentUpdated::dispatch($appointment);

            return $appointment->fresh(['attendees.user', 'contact', 'ticket', 'status']);
        });
    }
}
