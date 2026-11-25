<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\PassengerTransport\Domain\Enums\SheetStatus;

/**
 * The electronic NCC service sheet (foglio di servizio elettronico, Art. 11 Law 21/1992) of a booking, or the EU Interbus waybill
 * of an international coach tour.
 *
 * @property int $sheet_year
 * @property int $progressive_sheet_number
 * @property string $booking_id
 * @property string|null $vehicle_id
 * @property string|null $driver_employee_id
 * @property SheetStatus $status
 * @property Carbon|null $garage_departure_at
 * @property int|null $garage_departure_km
 * @property Carbon|null $service_start_at
 * @property int|null $service_start_km
 * @property Carbon|null $service_end_at
 * @property int|null $service_end_km
 * @property Carbon|null $garage_return_at
 * @property int|null $garage_return_km
 * @property string|null $mit_fdse_protocol_code_it
 * @property Carbon|null $mit_fdse_transmitted_at
 * @property string|null $interbus_eu_waybill_number
 * @property list<array<string, mixed>>|null $passenger_manifest
 * @property string|null $digital_signature_hash
 * @property string|null $pdf_storage_path
 */
final class ServiceSheet extends BaseEnterpriseModel
{
    protected $table = 'ptr_service_sheets';

    protected function casts(): array
    {
        return [
            'sheet_year' => 'integer',
            'progressive_sheet_number' => 'integer',
            'status' => SheetStatus::class,
            'garage_departure_at' => 'datetime',
            'garage_departure_km' => 'integer',
            'service_start_at' => 'datetime',
            'service_start_km' => 'integer',
            'service_end_at' => 'datetime',
            'service_end_km' => 'integer',
            'garage_return_at' => 'datetime',
            'garage_return_km' => 'integer',
            'mit_fdse_transmitted_at' => 'datetime',
            'passenger_manifest' => 'array',
        ];
    }

    /**
     * @return BelongsTo<PassengerBooking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(PassengerBooking::class, 'booking_id');
    }

    /**
     * @return BelongsTo<FleetVehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(FleetVehicle::class, 'vehicle_id');
    }

    public function number(): string
    {
        return sprintf('FDS-%d-%06d', $this->sheet_year, $this->progressive_sheet_number);
    }
}
