<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\PassengerTransport\Models\ServiceSheet;

/**
 * @mixin ServiceSheet
 */
final class ServiceSheetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number(),
            'sheet_year' => $this->sheet_year,
            'progressive_sheet_number' => $this->progressive_sheet_number,
            'booking_id' => $this->booking_id,
            'vehicle_id' => $this->vehicle_id,
            'driver_employee_id' => $this->driver_employee_id,
            'status' => $this->status->value,
            'garage_departure_at' => $this->garage_departure_at?->toIso8601String(),
            'garage_departure_km' => $this->garage_departure_km,
            'service_start_at' => $this->service_start_at?->toIso8601String(),
            'service_start_km' => $this->service_start_km,
            'service_end_at' => $this->service_end_at?->toIso8601String(),
            'service_end_km' => $this->service_end_km,
            'garage_return_at' => $this->garage_return_at?->toIso8601String(),
            'garage_return_km' => $this->garage_return_km,
            'mit_fdse_protocol_code_it' => $this->mit_fdse_protocol_code_it,
            'mit_fdse_transmitted_at' => $this->mit_fdse_transmitted_at?->toIso8601String(),
            'interbus_eu_waybill_number' => $this->interbus_eu_waybill_number,
            'passenger_manifest' => $this->passenger_manifest ?? [],
            'digital_signature_hash' => $this->digital_signature_hash,
            'has_pdf' => $this->pdf_storage_path !== null,
        ];
    }
}
