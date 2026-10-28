<?php

declare(strict_types=1);

namespace App\Services\Appointments;

use App\Models\Appointment;
use App\Models\LookupItem;
use App\Services\Sales\DocumentNumberingService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class AppointmentSchedulingService
{
    public function __construct(
        private readonly DocumentNumberingService $numbering,
    ) {}

    /**
     * Reserve the next sequential appointment number for the fiscal year.
     */
    public function generateAppointmentNumber(?int $year = null): string
    {
        $year ??= (int) date('Y');
        $seq = $this->numbering->next('appointment', $year);

        return $seq['number'];
    }

    /**
     * Retrieve status LookupItem by code.
     */
    public function getStatusByCode(string $code): ?LookupItem
    {
        return LookupItem::whereHas('category', function (Builder $q): void {
            $q->where('code', 'appointment_status');
        })
            ->where('code', $code)
            ->first();
    }

    /**
     * Retrieve calendar events between two timestamps with optional filters.
     *
     * @return Collection<int, Appointment>
     */
    public function getCalendarEvents(
        CarbonInterface $start,
        CarbonInterface $end,
        ?string $technicianId = null,
        ?string $contactId = null,
        ?string $statusId = null
    ): Collection {
        $query = Appointment::query()
            ->with(['contact', 'ticket', 'attendees.user', 'status'])
            ->between($start, $end)
            ->orderBy('starts_at', 'asc');

        if ($technicianId !== null) {
            $query->forTechnician($technicianId);
        }

        if ($contactId !== null) {
            $query->where('contact_id', $contactId);
        }

        if ($statusId !== null) {
            $query->where('status_id', $statusId);
        }

        return $query->get();
    }
}
