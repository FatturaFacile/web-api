<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\PassengerTransport\Models\FleetVehicle;

/**
 * @mixin FleetVehicle
 */
final class VehicleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'license_plate' => $this->license_plate,
            'vehicle_category' => $this->vehicle_category->value,
            'gross_weight_tons' => $this->gross_weight_tons,
            'passenger_capacity' => $this->passenger_capacity,
            'has_digital_tachograph' => $this->has_digital_tachograph,
            'psva_subject' => $this->psva_subject,
            'ncc_authorization_number' => $this->ncc_authorization_number,
            'ncc_issuing_municipality' => $this->ncc_issuing_municipality,
            'odometer_km' => $this->odometer_km,
            'active' => $this->active,
        ];
    }
}
