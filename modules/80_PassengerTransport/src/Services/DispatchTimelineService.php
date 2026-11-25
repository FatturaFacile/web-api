<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Services;

use Carbon\CarbonImmutable;
use Modules\PassengerTransport\Domain\Enums\BookingStatus;
use Modules\PassengerTransport\Domain\Enums\DrivingRegime;
use Modules\PassengerTransport\Domain\Support\DrivingTimeEvaluator;
use Modules\PassengerTransport\Models\DriverComplianceState;
use Modules\PassengerTransport\Models\FleetVehicle;
use Modules\PassengerTransport\Models\PassengerBooking;

/**
 * The dispatcher view: vehicles and drivers on one axis, their trips in a window of time on the other, with the flight status, the
 * cross-border and subcontracted flags, and the driving-time gauges of each driver.
 */
final class DispatchTimelineService
{
    public function __construct(private readonly RegulatoryStrategyResolver $strategies) {}

    /**
     * @return array{from: string, to: string, vehicles: list<array<string, mixed>>, drivers: list<array<string, mixed>>, unassigned: list<array<string, mixed>>, subcontracted: list<array<string, mixed>>}
     */
    public function timeline(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $bookings = PassengerBooking::query()
            ->with('carrier:id,company_name')
            ->where('status', '!=', BookingStatus::Cancelled->value)
            ->where('pickup_datetime', '<=', $to)
            ->where(fn ($query) => $query->where('estimated_dropoff_datetime', '>=', $from)->orWhere(fn ($inner) => $inner->whereNull('estimated_dropoff_datetime')->where('pickup_datetime', '>=', $from)))
            ->orderBy('pickup_datetime')
            ->get();

        $byVehicle = $bookings->groupBy('assigned_vehicle_id');
        $byDriver = $bookings->groupBy('assigned_driver_id');
        $strategy = $this->strategies->forCurrentTenant();
        $evaluator = new DrivingTimeEvaluator(config('passenger_transport.olr2'));

        $vehicles = FleetVehicle::query()->where('active', true)->orderBy('license_plate')->get()->map(fn (FleetVehicle $vehicle): array => [
            'id' => $vehicle->id,
            'license_plate' => $vehicle->license_plate,
            'vehicle_category' => $vehicle->vehicle_category->value,
            'passenger_capacity' => $vehicle->passenger_capacity,
            'bookings' => ($byVehicle->get($vehicle->id) ?? collect())->map(fn (PassengerBooking $booking): array => $this->row($booking))->values()->all(),
        ])->values()->all();

        $drivers = DriverComplianceState::query()->with('employee:id,full_name')->get()->map(function (DriverComplianceState $state) use ($byDriver, $strategy, $evaluator): array {
            $regime = in_array('D', array_map('strtoupper', $state->driving_license_categories ?? []), true) || in_array('DE', array_map('strtoupper', $state->driving_license_categories ?? []), true)
                ? $strategy->drivingRegime(4.0, 20)
                : DrivingRegime::Olr2;
            $counters = $state->counters();

            return [
                'id' => $state->driver_employee_id,
                'name' => $state->employee?->full_name,
                'regime' => $regime->value,
                'available_for_next_trip' => $state->available_for_next_trip,
                'next_mandatory_break_or_rest_at' => $state->next_mandatory_break_or_rest_at?->toIso8601String(),
                'gauges' => [
                    'daily_used_minutes' => $counters->dailyDrivingMinutesUsed,
                    'daily_limit_minutes' => $regime === DrivingRegime::Olr1Ec561 ? ($counters->extended10hDaysUsedThisWeek < 2 ? 600 : 540) : (int) config('passenger_transport.olr2.daily_limit'),
                    'weekly_used_minutes' => $counters->weeklyDrivingMinutesUsed,
                    'weekly_limit_minutes' => $regime === DrivingRegime::Olr1Ec561 ? 3360 : null,
                    'biweekly_used_minutes' => $counters->biweeklyDrivingMinutesUsed,
                    'biweekly_limit_minutes' => $regime === DrivingRegime::Olr1Ec561 ? 5400 : null,
                    'remaining_minutes' => $evaluator->remainingMinutes($regime, $counters),
                ],
                'documents' => [
                    'license_expiry' => $state->driving_license_expiry_date?->toDateString(),
                    'cqc_expiry' => $state->cqc_persone_expiry_date?->toDateString(),
                    'czv_expiry' => $state->czv_expiry_date?->toDateString(),
                    'tachograph_card_expiry' => $state->tachograph_card_expiry_date?->toDateString(),
                ],
                'bookings' => ($byDriver->get($state->driver_employee_id) ?? collect())->map(fn (PassengerBooking $booking): array => $this->row($booking))->values()->all(),
            ];
        })->values()->all();

        $own = $bookings->filter(fn (PassengerBooking $booking): bool => $booking->exchange_direction->value !== 'ceded');

        return [
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'vehicles' => $vehicles,
            'drivers' => $drivers,
            'unassigned' => $own->filter(fn (PassengerBooking $booking): bool => $booking->assigned_vehicle_id === null && $booking->assigned_driver_id === null)->map(fn (PassengerBooking $booking): array => $this->row($booking))->values()->all(),
            'subcontracted' => $bookings->filter(fn (PassengerBooking $booking): bool => $booking->exchange_direction->value === 'ceded')->map(fn (PassengerBooking $booking): array => $this->row($booking))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(PassengerBooking $booking): array
    {
        return [
            'id' => $booking->id,
            'booking_code' => $booking->booking_code,
            'service_type' => $booking->service_type->value,
            'status' => $booking->status->value,
            'pickup_datetime' => $booking->pickup_datetime->toIso8601String(),
            'estimated_dropoff_datetime' => $booking->estimated_dropoff_datetime?->toIso8601String(),
            'pickup_address' => $booking->pickup_address,
            'dropoff_address' => $booking->dropoff_address,
            'lead_passenger_name' => $booking->lead_passenger_name,
            'passengers_count' => $booking->passengers_count,
            'flight_or_train_number' => $booking->flight_or_train_number,
            'flight_status' => $booking->flight_status?->value,
            'flight_delay_minutes' => $booking->flight_delay_minutes,
            'cross_border' => $booking->isCrossBorder(),
            'border_crossing_point' => $booking->border_crossing_point->value,
            'exchange_direction' => $booking->exchange_direction->value,
            'carrier_name' => $booking->carrier?->company_name,
        ];
    }
}
