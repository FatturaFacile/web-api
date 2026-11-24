<?php

declare(strict_types=1);

namespace App\Actions\Appointments;

use App\Events\Appointments\AppointmentCancelled;
use App\Models\Appointment;
use App\Services\Appointments\AppointmentSchedulingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelAppointmentAction
{
    public function __construct(
        private readonly AppointmentSchedulingService $schedulingService,
    ) {}

    /**
     * Cancel an appointment with an explicit reason.
     */
    public function execute(Appointment $appointment, string $reason): Appointment
    {
        if ($appointment->isCancelled()) {
            throw ValidationException::withMessages([
                'appointment' => __('appointments_errors.already_cancelled'),
            ]);
        }

        if ($appointment->service_report_id !== null) {
            throw ValidationException::withMessages([
                'appointment' => __('appointments_errors.cancel_already_converted'),
            ]);
        }

        return DB::transaction(function () use ($appointment, $reason): Appointment {
            $cancelledStatus = $this->schedulingService->getStatusByCode('cancelled');

            $appointment->update([
                'status_id' => $cancelledStatus?->id ?? $appointment->status_id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'updated_by' => Auth::id(),
            ]);

            AppointmentCancelled::dispatch($appointment, $reason);

            return $appointment->fresh(['status', 'attendees.user']);
        });
    }
}
