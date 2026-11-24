<?php

declare(strict_types=1);

namespace App\Services\Appointments;

use App\Models\Appointment;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class AppointmentConflictService
{
    /**
     * Check if any of the given technician user IDs have conflicting appointments in the specified interval.
     *
     * @param  array<int, string>  $userIds
     * @return array{has_conflict: bool, conflicts: array<int, array{user_id: string, appointment_id: string, appointment_number: string, title: string, starts_at: string, ends_at: string}>}
     */
    public function detectConflicts(
        array $userIds,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?string $ignoreAppointmentId = null
    ): array {
        if (empty($userIds)) {
            return [
                'has_conflict' => false,
                'conflicts' => [],
            ];
        }

        $conflicts = [];

        $query = Appointment::query()
            ->with(['attendees.user', 'status'])
            ->where(function (Builder $q) use ($startsAt, $endsAt): void {
                $q->where('starts_at', '<', $endsAt)
                    ->where('ends_at', '>', $startsAt);
            })
            ->whereNull('cancelled_at')
            ->whereHas('status', function (Builder $q): void {
                $q->where('code', '!=', 'cancelled');
            })
            ->whereHas('attendees', function (Builder $q) use ($userIds): void {
                $q->whereIn('user_id', $userIds);
            });

        if ($ignoreAppointmentId !== null) {
            $query->where('id', '!=', $ignoreAppointmentId);
        }

        $overlappingAppointments = $query->get();

        foreach ($overlappingAppointments as $appointment) {
            foreach ($appointment->attendees as $attendee) {
                if (in_array($attendee->user_id, $userIds, true)) {
                    $conflicts[] = [
                        'user_id' => $attendee->user_id,
                        'appointment_id' => $appointment->id,
                        'appointment_number' => $appointment->appointment_number,
                        'title' => $appointment->title,
                        'starts_at' => $appointment->starts_at->toIso8601String(),
                        'ends_at' => $appointment->ends_at->toIso8601String(),
                    ];
                }
            }
        }

        return [
            'has_conflict' => ! empty($conflicts),
            'conflicts' => $conflicts,
        ];
    }
}
