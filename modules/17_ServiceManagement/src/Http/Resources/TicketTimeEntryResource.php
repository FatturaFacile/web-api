<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ServiceManagement\Models\TicketTimeEntry;

/**
 * @mixin TicketTimeEntry
 */
final class TicketTimeEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_id' => $this->ticket_id,
            'technician_id' => $this->technician_id,
            'work_date' => $this->work_date?->toDateString(),
            'hours' => (string) $this->hours,
            'minutes' => (int) round(((float) $this->hours) * 60),
            'description' => $this->description,
            'is_billable' => (bool) $this->is_billable,
            'service_contract_id' => $this->service_contract_id,
            'contract_hours' => (string) $this->contract_hours,
            'billable_hours' => (string) $this->billable_hours,
            'billing_status' => $this->billing_status,
            'total_charge' => (string) $this->total_charge,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
