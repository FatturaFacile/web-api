<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Services;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\PassengerTransport\Contracts\FdseGatewayInterface;
use Modules\PassengerTransport\Domain\Enums\BookingStatus;
use Modules\PassengerTransport\Domain\Enums\ServiceType;
use Modules\PassengerTransport\Domain\Enums\SheetStatus;
use Modules\PassengerTransport\Exceptions\PassengerTransportException;
use Modules\PassengerTransport\Models\PassengerBooking;
use Modules\PassengerTransport\Models\ServiceSheet;
use Modules\PassengerTransport\Services\Documents\PassengerTransportDocuments;

/**
 * Lifecycle of the NCC electronic service sheet (FDSE) and of the EU Interbus waybill of an international coach tour: opened with the
 * progressive number before the vehicle leaves the garage, completed with times and kilometres, closed with its signature hash and
 * PDF, and transmitted to the register.
 */
final class ServiceSheetService
{
    private const array EDITABLE_FIELDS = ['garage_departure_at', 'garage_departure_km', 'service_start_at', 'service_start_km', 'service_end_at', 'service_end_km', 'garage_return_at', 'garage_return_km', 'passenger_manifest'];

    public function __construct(
        private readonly RegulatoryStrategyResolver $strategies,
        private readonly FdseGatewayInterface $gateway,
        private readonly PassengerTransportDocuments $documents,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function open(PassengerBooking $booking, array $data, ?string $userId): ServiceSheet
    {
        if ($booking->assigned_vehicle_id === null || $booking->assigned_driver_id === null) {
            throw new PassengerTransportException('sheet_requires_assignment', 422);
        }

        if (in_array($booking->status, [BookingStatus::Draft, BookingStatus::Cancelled], true)) {
            throw new PassengerTransportException('sheet_booking_status', 409, ['status' => $booking->status->value]);
        }

        if (ServiceSheet::query()->where('booking_id', $booking->id)->where('status', SheetStatus::Open->value)->exists()) {
            throw new PassengerTransportException('sheet_already_open', 409);
        }

        $year = (int) $booking->pickup_datetime->format('Y');

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($booking, $data, $userId, $year): ServiceSheet {
                    $number = ((int) ServiceSheet::withTrashed()->where('sheet_year', $year)->max('progressive_sheet_number')) + 1;

                    return ServiceSheet::query()->create([
                        'tenant_id' => $booking->tenant_id,
                        'sheet_year' => $year,
                        'progressive_sheet_number' => $number,
                        'booking_id' => $booking->id,
                        'vehicle_id' => $booking->assigned_vehicle_id,
                        'driver_employee_id' => $booking->assigned_driver_id,
                        'status' => SheetStatus::Open->value,
                        'interbus_eu_waybill_number' => $this->waybillNumber($booking, $year, $number),
                        'created_by' => $userId,
                        ...array_intersect_key($data, array_flip(self::EDITABLE_FIELDS)),
                    ]);
                });
            } catch (QueryException $exception) {
                if ($attempt >= 3 || ! str_contains($exception->getMessage(), 'progressive')) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ServiceSheet $sheet, array $data, ?string $userId): ServiceSheet
    {
        if ($sheet->status !== SheetStatus::Open) {
            throw new PassengerTransportException('sheet_locked', 409);
        }

        $sheet->update([...array_intersect_key($data, array_flip(self::EDITABLE_FIELDS)), 'updated_by' => $userId]);

        return $sheet->refresh();
    }

    public function close(ServiceSheet $sheet, ?string $userId): ServiceSheet
    {
        if ($sheet->status !== SheetStatus::Open) {
            throw new PassengerTransportException('sheet_locked', 409);
        }

        $sheet->loadMissing(['booking.orderingCustomer', 'booking.driver', 'vehicle']);
        $missing = $this->strategies->forCurrentTenant()->serviceSheetMissingFields($this->checkData($sheet));

        if ($missing !== []) {
            throw new PassengerTransportException('sheet_incomplete', 422, ['fields' => implode(', ', $missing), 'missing' => $missing]);
        }

        $this->assertKilometres($sheet);

        return DB::transaction(function () use ($sheet, $userId): ServiceSheet {
            $sheet->forceFill(['digital_signature_hash' => $this->signature($sheet), 'status' => SheetStatus::Closed->value, 'updated_by' => $userId])->save();
            $this->storePdf($sheet);

            if ($sheet->garage_return_km !== null && $sheet->vehicle !== null && $sheet->garage_return_km > $sheet->vehicle->odometer_km) {
                $sheet->vehicle->update(['odometer_km' => $sheet->garage_return_km]);
            }

            return $sheet->refresh();
        });
    }

    public function transmit(ServiceSheet $sheet, ?string $userId): ServiceSheet
    {
        if ($sheet->status !== SheetStatus::Closed) {
            throw new PassengerTransportException('sheet_not_closed', 409);
        }

        $sheet->loadMissing(['booking.orderingCustomer', 'booking.driver', 'vehicle']);
        $protocol = $this->gateway->transmit($this->payload($sheet));

        return DB::transaction(function () use ($sheet, $protocol, $userId): ServiceSheet {
            $sheet->forceFill(['mit_fdse_protocol_code_it' => $protocol, 'mit_fdse_transmitted_at' => now(), 'status' => SheetStatus::Transmitted->value, 'updated_by' => $userId])->save();
            $this->storePdf($sheet);

            return $sheet->refresh();
        });
    }

    /**
     * The payload of the transmission to the MIT register.
     *
     * @return array<string, mixed>
     */
    public function payload(ServiceSheet $sheet): array
    {
        $booking = $sheet->booking;

        return [
            'sheet_number' => $sheet->number(),
            'vehicle_plate' => $sheet->vehicle?->license_plate,
            'ncc_authorization_number' => $sheet->vehicle?->ncc_authorization_number,
            'garage_municipality' => $sheet->vehicle?->ncc_issuing_municipality,
            'driver_employee_id' => $sheet->driver_employee_id,
            'customer' => $booking->orderingCustomer->company_name ?? $booking->lead_passenger_name,
            'pickup_address' => $booking->pickup_address,
            'dropoff_address' => $booking->dropoff_address,
            'garage_departure_at' => $sheet->garage_departure_at?->toIso8601String(),
            'service_start_at' => $sheet->service_start_at?->toIso8601String(),
            'service_end_at' => $sheet->service_end_at?->toIso8601String(),
            'garage_return_at' => $sheet->garage_return_at?->toIso8601String(),
            'kilometres' => ['garage_departure' => $sheet->garage_departure_km, 'service_start' => $sheet->service_start_km, 'service_end' => $sheet->service_end_km, 'garage_return' => $sheet->garage_return_km],
            'signature_hash' => $sheet->digital_signature_hash,
        ];
    }

    public function pdf(ServiceSheet $sheet): string
    {
        $sheet->loadMissing(['booking.orderingCustomer', 'booking.driver', 'vehicle']);

        return $this->documents->serviceSheetPdf($sheet, $this->companyName());
    }

    public function interbusPdf(ServiceSheet $sheet): string
    {
        $sheet->loadMissing(['booking.driver', 'vehicle']);

        if ($sheet->interbus_eu_waybill_number === null) {
            throw new PassengerTransportException('sheet_not_international_coach', 422);
        }

        return $this->documents->interbusPdf($sheet, $this->companyName());
    }

    /**
     * @return array<string, mixed>
     */
    private function checkData(ServiceSheet $sheet): array
    {
        $booking = $sheet->booking;

        return [
            'progressive_sheet_number' => $sheet->progressive_sheet_number,
            'vehicle_plate' => $sheet->vehicle?->license_plate,
            'driver_employee_id' => $sheet->driver_employee_id,
            'garage_departure_at' => $sheet->garage_departure_at,
            'garage_municipality' => $sheet->vehicle?->ncc_issuing_municipality,
            'customer' => $booking->orderingCustomer->company_name ?? $booking->lead_passenger_name,
            'pickup_address' => $booking->pickup_address,
            'dropoff_address' => $booking->dropoff_address,
        ];
    }

    private function assertKilometres(ServiceSheet $sheet): void
    {
        $readings = array_values(array_filter([$sheet->garage_departure_km, $sheet->service_start_km, $sheet->service_end_km, $sheet->garage_return_km], static fn (?int $km): bool => $km !== null));

        for ($index = 1; $index < count($readings); $index++) {
            if ($readings[$index] < $readings[$index - 1]) {
                throw new PassengerTransportException('sheet_kilometres_decreasing', 422);
            }
        }
    }

    private function signature(ServiceSheet $sheet): string
    {
        return hash('sha256', (string) json_encode([...$this->payload($sheet), 'signature_hash' => null, 'manifest' => $sheet->passenger_manifest]));
    }

    private function storePdf(ServiceSheet $sheet): void
    {
        $path = sprintf('passenger-transport/%s/service-sheets/%s.pdf', $sheet->tenant_id, $sheet->number());
        Storage::disk((string) config('passenger_transport.storage_disk'))->put($path, $this->pdf($sheet));
        $sheet->forceFill(['pdf_storage_path' => $path])->save();
    }

    private function waybillNumber(PassengerBooking $booking, int $year, int $number): ?string
    {
        return $booking->service_type === ServiceType::MultiDayTourCoach && $booking->isCrossBorder()
            ? sprintf('IB-%d-%06d', $year, $number)
            : null;
    }

    private function companyName(): string
    {
        $tenant = $this->tenantContext->getTenant();

        return (string) ($tenant->legal_name ?? $tenant->name ?? '');
    }
}
