<?php

declare(strict_types=1);

namespace App\Actions\Appointments;

use App\Actions\ServiceReports\SaveServiceReport;
use App\Events\Appointments\AppointmentConvertedToServiceReport;
use App\Models\Appointment;
use App\Models\LookupItem;
use App\Models\ServiceReport;
use App\Services\Appointments\AppointmentSchedulingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConvertAppointmentToServiceReportAction
{
    public function __construct(
        private readonly SaveServiceReport $saveServiceReport,
        private readonly AppointmentSchedulingService $schedulingService,
    ) {}

    /**
     * Convert a completed field appointment into an official Service Report.
     */
    public function execute(Appointment $appointment): ServiceReport
    {
        return DB::transaction(function () use ($appointment): ServiceReport {
            $lockedAppointment = Appointment::where('id', $appointment->id)->lockForUpdate()->firstOrFail();

            if ($lockedAppointment->service_report_id !== null) {
                throw ValidationException::withMessages([
                    'appointment' => "This appointment has already been converted to service report #{$lockedAppointment->service_report_id}.",
                ]);
            }

            if ($lockedAppointment->isCancelled()) {
                throw ValidationException::withMessages([
                    'appointment' => 'Cannot convert a cancelled appointment to a service report.',
                ]);
            }

            // Determine lead technician
            $leadAttendee = $lockedAppointment->attendees()
                ->where('role', 'lead')
                ->first()
                ?? $lockedAppointment->attendees()->first();

            $technicianId = $leadAttendee?->user_id ?? Auth::id() ?? $lockedAppointment->created_by;

            if (empty($technicianId)) {
                throw ValidationException::withMessages([
                    'technician_id' => 'No technician found on this appointment to assign to the service report.',
                ]);
            }

            // Resolve labor line type lookup
            $laborLineType = LookupItem::whereHas('category', fn ($q) => $q->where('code', 'service_report_line_type'))
                ->where('code', 'labor')
                ->first();

            $hours = round($appointment->starts_at->diffInMinutes($appointment->ends_at) / 60, 2);
            $lines = [];

            if ($laborLineType !== null && $hours > 0) {
                $lines[] = [
                    'line_type_id' => $laborLineType->id,
                    'description' => "Intervento tecnico: {$appointment->title}",
                    'quantity' => $hours,
                    'unit_price' => 0.00,
                    'vat_rate' => 0.00,
                ];
            }

            $reportData = [
                'contact_id' => $appointment->contact_id,
                'ticket_id' => $appointment->ticket_id,
                'technician_id' => $technicianId,
                'report_date' => $appointment->starts_at->toDateString(),
                'time_start' => $appointment->starts_at->format('H:i:s'),
                'time_end' => $appointment->ends_at->format('H:i:s'),
                'total_hours' => $hours,
                'site_address' => $appointment->address,
                'customer_notes' => $appointment->customer_notes,
                'internal_notes' => trim("[Da Appuntamento {$appointment->appointment_number}]\n".($appointment->technician_notes ?? '')),
                'is_portal_visible' => $appointment->is_portal_visible,
                'lines' => $lines,
            ];

            $serviceReport = $this->saveServiceReport->create($reportData);

            $completedStatus = $this->schedulingService->getStatusByCode('completed');

            $appointment->update([
                'service_report_id' => $serviceReport->id,
                'status_id' => $completedStatus?->id ?? $appointment->status_id,
                'updated_by' => Auth::id(),
            ]);

            AppointmentConvertedToServiceReport::dispatch($appointment, $serviceReport);

            return $serviceReport;
        });
    }
}
