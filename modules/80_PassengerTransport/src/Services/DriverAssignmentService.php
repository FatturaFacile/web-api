<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Services;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\PassengerTransport\Domain\Enums\BookingStatus;
use Modules\PassengerTransport\Domain\Enums\IssueSeverity;
use Modules\PassengerTransport\Domain\Enums\ServiceType;
use Modules\PassengerTransport\Domain\Support\DriverDocumentValidator;
use Modules\PassengerTransport\Domain\Support\DrivingTimeEvaluator;
use Modules\PassengerTransport\Domain\ValueObjects\ComplianceIssue;
use Modules\PassengerTransport\Domain\ValueObjects\DriverCounters;
use Modules\PassengerTransport\Domain\ValueObjects\TripPlan;
use Modules\PassengerTransport\Exceptions\PassengerTransportException;
use Modules\PassengerTransport\Models\DriverComplianceState;
use Modules\PassengerTransport\Models\FleetVehicle;
use Modules\PassengerTransport\Models\PassengerBooking;

/**
 * Assigns a driver and a vehicle to a booking after simulating the trip against the driver documents (licence, CQC Persone, CZV,
 * tachograph card on the day of the trip), the overlap with other trips and the driving and rest limits of OLR 1 / OLR 2 /
 * Reg. (EC) 561/2006. Blocking violations refuse the assignment; warnings need to be acknowledged.
 */
final class DriverAssignmentService
{
    private const array ACTIVE_STATUSES = [BookingStatus::Confirmed, BookingStatus::Assigned, BookingStatus::EnRouteToPickup, BookingStatus::AtPickupWaiting, BookingStatus::PassengerOnBoard];

    public function __construct(private readonly RegulatoryStrategyResolver $strategies, private readonly DriverDocumentValidator $documents = new DriverDocumentValidator) {}

    /**
     * @return array{regime: string, issues: list<array<string, mixed>>, blocking_count: int, warning_count: int, can_assign: bool, remaining_minutes: int, next_mandatory_break_or_rest_at: string|null}
     */
    public function check(PassengerBooking $booking, FleetVehicle $vehicle, DriverComplianceState $state, int $plannedBreakMinutes = 0): array
    {
        $strategy = $this->strategies->forCurrentTenant();
        $regime = $strategy->drivingRegime((float) $vehicle->gross_weight_tons, $vehicle->passenger_capacity);
        $trip = $this->tripPlan($booking, $plannedBreakMinutes);
        $issues = [];

        if (! $vehicle->active) {
            $issues[] = new ComplianceIssue('vehicle_inactive', IssueSeverity::Blocking, 'Art. 82 D.Lgs. 285/1992');
        }

        if ($booking->passengers_count > $vehicle->passenger_capacity) {
            $issues[] = new ComplianceIssue('vehicle_capacity_exceeded', IssueSeverity::Blocking, 'Art. 82 D.Lgs. 285/1992', ['passengers' => $booking->passengers_count, 'capacity' => $vehicle->passenger_capacity]);
        }

        $issues = [...$issues, ...$this->overlaps($booking, $vehicle->id, $state->driver_employee_id)];
        $tripDate = DateTimeImmutable::createFromInterface($booking->pickup_datetime);
        $touchesIt = $booking->pickup_country_code === 'IT' || $booking->dropoff_country_code === 'IT' || (float) $booking->it_territory_km > 0;
        $touchesCh = $booking->pickup_country_code === 'CH' || $booking->dropoff_country_code === 'CH' || (float) $booking->ch_territory_km > 0;

        $issues = [...$issues, ...$this->documents->validate(
            $vehicle->vehicle_category,
            $vehicle->has_digital_tachograph,
            $state->driving_license_categories ?? [],
            $state->driving_license_expiry_date?->toDateTimeImmutable(),
            $state->cqc_persone_expiry_date?->toDateTimeImmutable(),
            $state->czv_expiry_date?->toDateTimeImmutable(),
            $state->tachograph_card_expiry_date?->toDateTimeImmutable(),
            $tripDate,
            $touchesIt,
            $touchesCh,
        )];

        $counters = $this->effectiveCounters($booking, $state);
        $issues = [...$issues, ...$strategy->evaluateDriving($regime, $counters, $trip, $touchesCh)];

        if ($trip->endsAt->getTimestamp() - $trip->startsAt->getTimestamp() > 86400) {
            $issues[] = new ComplianceIssue('multi_day_first_day_only', IssueSeverity::Warning, 'Art. 6 Reg. (EC) 561/2006');
        }

        $evaluator = new DrivingTimeEvaluator(config('passenger_transport.olr2'));
        $blocking = count(array_filter($issues, static fn (ComplianceIssue $issue): bool => $issue->isBlocking()));

        return [
            'regime' => $regime->value,
            'issues' => array_map(static fn (ComplianceIssue $issue): array => $issue->toArray(), $issues),
            'blocking_count' => $blocking,
            'warning_count' => count($issues) - $blocking,
            'can_assign' => $blocking === 0,
            'remaining_minutes' => $evaluator->remainingMinutes($regime, $counters),
            'next_mandatory_break_or_rest_at' => $evaluator->nextMandatoryBreakAt($regime, $counters, $trip->startsAt)->format(DATE_ATOM),
        ];
    }

    /**
     * @return array{regime: string, issues: list<array<string, mixed>>, blocking_count: int, warning_count: int, can_assign: bool, remaining_minutes: int, next_mandatory_break_or_rest_at: string|null}
     */
    public function assign(PassengerBooking $booking, FleetVehicle $vehicle, DriverComplianceState $state, int $plannedBreakMinutes, bool $acknowledgeWarnings, ?string $userId): array
    {
        if (! $booking->isEditable()) {
            throw new PassengerTransportException('booking_locked', 409);
        }

        $result = $this->check($booking, $vehicle, $state, $plannedBreakMinutes);

        if (! $result['can_assign']) {
            throw new PassengerTransportException('assignment_blocked', 422, ['issues' => $result['issues'], 'blocking_count' => $result['blocking_count']]);
        }

        if ($result['warning_count'] > 0 && ! $acknowledgeWarnings) {
            throw new PassengerTransportException('assignment_warnings_unacknowledged', 409, ['issues' => $result['issues'], 'warning_count' => $result['warning_count']]);
        }

        DB::transaction(function () use ($booking, $vehicle, $state, $userId): void {
            $booking->update([
                'assigned_vehicle_id' => $vehicle->id,
                'assigned_driver_id' => $state->driver_employee_id,
                'status' => BookingStatus::Assigned->value,
                'updated_by' => $userId,
            ]);
        });

        return $result;
    }

    /**
     * Adds the driving of a completed trip to the counters of the driver.
     */
    public function recordCompletedTrip(PassengerBooking $booking): void
    {
        if ($booking->assigned_driver_id === null || $booking->assigned_vehicle_id === null) {
            return;
        }

        $state = DriverComplianceState::query()->where('driver_employee_id', $booking->assigned_driver_id)->first();
        $vehicle = FleetVehicle::query()->find($booking->assigned_vehicle_id);

        if ($state === null || $vehicle === null) {
            return;
        }

        $minutes = $this->tripPlan($booking, 0)->drivingMinutes;
        $strategy = $this->strategies->forCurrentTenant();
        $regime = $strategy->drivingRegime((float) $vehicle->gross_weight_tons, $vehicle->passenger_capacity);
        $counters = $this->effectiveCounters($booking, $state, false);
        $daily = $counters->dailyDrivingMinutesUsed + $minutes;
        $extended = $daily > 540 && $counters->dailyDrivingMinutesUsed <= 540 ? $counters->extended10hDaysUsedThisWeek + 1 : $counters->extended10hDaysUsedThisWeek;
        $updated = new DriverCounters($daily, $extended, $counters->continuousDrivingMinutesSinceBreak + $minutes, $counters->weeklyDrivingMinutesUsed + $minutes, $counters->biweeklyDrivingMinutesUsed + $minutes, $counters->lastDailyRestDurationMinutes, $counters->reduced9hRestsUsedBetweenWeeklyRests, $counters->lastWeeklyRestEndedAt);
        $evaluator = new DrivingTimeEvaluator(config('passenger_transport.olr2'));
        $end = CarbonImmutable::parse($booking->estimated_dropoff_datetime ?? $booking->pickup_datetime)->toDateTimeImmutable();

        $state->update([
            'daily_driving_minutes_used' => $updated->dailyDrivingMinutesUsed,
            'extended_10h_days_used_this_week' => $updated->extended10hDaysUsedThisWeek,
            'continuous_driving_minutes_since_break' => $updated->continuousDrivingMinutesSinceBreak,
            'weekly_driving_minutes_used' => $updated->weeklyDrivingMinutesUsed,
            'biweekly_driving_minutes_used' => $updated->biweeklyDrivingMinutesUsed,
            'available_for_next_trip' => $evaluator->remainingMinutes($regime, $updated) > 0,
            'next_mandatory_break_or_rest_at' => $evaluator->nextMandatoryBreakAt($regime, $updated, $end),
            'counters_updated_at' => $end,
        ]);
    }

    /**
     * Counters at the start of the trip: a new day starts without driving and with a fresh break clock; the other trips already
     * assigned to the driver on that day are added to the daily driving.
     */
    private function effectiveCounters(PassengerBooking $booking, DriverComplianceState $state, bool $withPlannedLoad = true): DriverCounters
    {
        $counters = $state->counters();
        $tripDay = $booking->pickup_datetime->format('Y-m-d');
        $newDay = $state->counters_updated_at === null || $state->counters_updated_at->format('Y-m-d') < $tripDay;
        $daily = $newDay ? 0 : $counters->dailyDrivingMinutesUsed;
        $continuous = $newDay ? 0 : $counters->continuousDrivingMinutesSinceBreak;

        if ($withPlannedLoad) {
            $others = PassengerBooking::query()
                ->where('assigned_driver_id', $state->driver_employee_id)
                ->whereKeyNot($booking->id)
                ->whereIn('status', array_map(static fn (BookingStatus $status): string => $status->value, self::ACTIVE_STATUSES))
                ->whereDate('pickup_datetime', $tripDay)
                ->get();

            foreach ($others as $other) {
                $daily += $this->tripPlan($other, 0)->drivingMinutes;
            }
        }

        return new DriverCounters(
            $daily,
            $counters->extended10hDaysUsedThisWeek,
            $continuous,
            $counters->weeklyDrivingMinutesUsed,
            $counters->biweeklyDrivingMinutesUsed,
            $counters->lastDailyRestDurationMinutes,
            $counters->reduced9hRestsUsedBetweenWeeklyRests,
            $counters->lastWeeklyRestEndedAt,
        );
    }

    private function tripPlan(PassengerBooking $booking, int $plannedBreakMinutes): TripPlan
    {
        $garage = (int) config('passenger_transport.garage_run_minutes');
        $start = CarbonImmutable::parse($booking->pickup_datetime)->subMinutes($garage);
        $dropoff = $booking->estimated_dropoff_datetime !== null
            ? CarbonImmutable::parse($booking->estimated_dropoff_datetime)
            : CarbonImmutable::parse($booking->pickup_datetime)->addMinutes(max(30, (int) ceil((float) $booking->total_distance_km)));
        $end = $dropoff->addMinutes($garage);
        $window = max(0, (int) (($end->getTimestamp() - $start->getTimestamp()) / 60));
        $driving = match (true) {
            $booking->service_type === ServiceType::HourlyDisposal => (int) round($window * 0.7),
            $window > 1440 => 540,
            default => $window,
        };

        return new TripPlan($start->toDateTimeImmutable(), $end->toDateTimeImmutable(), $driving, $plannedBreakMinutes);
    }

    /**
     * Overlaps of the booking, on its current window, with other trips of its assigned vehicle and driver.
     *
     * @return list<ComplianceIssue>
     */
    public function conflicts(PassengerBooking $booking): array
    {
        if ($booking->assigned_vehicle_id === null && $booking->assigned_driver_id === null) {
            return [];
        }

        return $this->overlaps($booking, (string) $booking->assigned_vehicle_id, (string) $booking->assigned_driver_id);
    }

    /**
     * @return list<ComplianceIssue>
     */
    private function overlaps(PassengerBooking $booking, string $vehicleId, string $driverId): array
    {
        $turnaround = (int) config('passenger_transport.flights.turnaround_minutes');
        $trip = $this->tripPlan($booking, 0);
        $from = CarbonImmutable::instance($trip->startsAt)->subMinutes($turnaround);
        $to = CarbonImmutable::instance($trip->endsAt)->addMinutes($turnaround);
        $issues = [];

        $candidates = PassengerBooking::query()
            ->whereKeyNot($booking->id)
            ->whereIn('status', array_map(static fn (BookingStatus $status): string => $status->value, self::ACTIVE_STATUSES))
            ->where(fn ($query) => $query->where('assigned_vehicle_id', $vehicleId)->orWhere('assigned_driver_id', $driverId))
            ->whereBetween('pickup_datetime', [$from->subDay(), $to])
            ->get();

        foreach ($candidates as $other) {
            $otherPlan = $this->tripPlan($other, 0);

            if ($otherPlan->startsAt >= $to || $otherPlan->endsAt <= $from) {
                continue;
            }

            if ($other->assigned_vehicle_id === $vehicleId) {
                $issues[] = new ComplianceIssue('vehicle_overlap', IssueSeverity::Blocking, 'Art. 11 Law 21/1992', ['booking' => $other->booking_code]);
            }

            if ($other->assigned_driver_id === $driverId) {
                $issues[] = new ComplianceIssue('driver_overlap', IssueSeverity::Blocking, 'Art. 6 Reg. (EC) 561/2006', ['booking' => $other->booking_code]);
            }
        }

        return $issues;
    }
}
