<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\GreenCare\Http\Support\ContactName;
use Modules\GreenCare\Models\GreenWasteSlip;

/**
 * @mixin GreenWasteSlip
 */
final class WasteSlipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slip_number' => $this->slip_number,
            'site_id' => $this->site_id,
            'site_name' => $this->site->name ?? null,
            'route_schedule_id' => $this->route_schedule_id,
            'disposal_date' => $this->disposal_date->format('Y-m-d'),
            'waste_code' => $this->waste_code,
            'waste_description' => $this->waste_description,
            'green_waste_weight_kg' => (float) $this->green_waste_weight_kg,
            'compost_facility_id' => $this->compost_facility_id,
            'compost_facility_name' => ContactName::of($this->compostFacility),
            'transporter_vehicle_plate' => $this->transporter_vehicle_plate,
            'document_reference' => $this->document_reference,
            'disposal_cost' => (float) $this->disposal_cost,
            'is_billable_to_customer' => $this->is_billable_to_customer,
        ];
    }
}
