<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\PassengerTransport\Contracts\FlightStatusProviderInterface;
use Modules\PassengerTransport\Domain\Enums\BookingStatus;
use Modules\PassengerTransport\Domain\Enums\FlightStatus;
use Modules\PassengerTransport\Domain\Support\FlightScheduleAdjuster;
use Modules\PassengerTransport\Domain\ValueObjects\ComplianceIssue;
use Modules\PassengerTransport\Events\FlightScheduleAdjusted;
use Modules\PassengerTransport\Exceptions\PassengerTransportException;
use Modules\PassengerTransport\Models\PassengerBooking;
use Modules\PassengerTransport\Notifications\FlightScheduleChangedNotification;

/**
 * Follows the flights of the airport transfers: reads the status from the provider, moves the pickup to the estimated arrival plus
 * the meet-and-greet buffer, finds the other trips of the assigned vehicle and driver the new time collides with, and tells the
 * dispatchers and the driver.
 */
final class FlightTrackingService
{
    private const array DISPATCH_ROLES = ['owner', 'admin', 'dispatcher', 'operations_manager'];

    public function __construct(
        private readonly FlightStatusProviderInterface $provider,
        private readonly DriverAssignmentService $assignment,
        private readonly FlightScheduleAdjuster $adjuster,
    ) {}

    /**
     * @return array{status: string|null, delay_minutes: int, adjusted: bool, previous_pickup: string|null, new_pickup: string|null, conflicts: list<array<string, mixed>>}
     */
    public function track(PassengerBooking $booking, ?DateTimeImmutable $now = null): array
    {
        if ($booking->flight_or_train_number === null || $booking->arrival_airport_iata === null || $booking->scheduled_flight_arrival_at === null) {
            throw new PassengerTransportException('booking_without_flight', 422);
        }

        $now ??= new DateTimeImmutable;
        $snapshot = $this->provider->lookup($booking->flight_or_train_number, $booking->flight_carrier_iata, $booking->arrival_airport_iata, $booking->scheduled_flight_arrival_at->toDateTimeImmutable());

        if ($snapshot === null) {
            return ['status' => $booking->flight_status?->value, 'delay_minutes' => $booking->flight_delay_minutes, 'adjusted' => false, 'previous_pickup' => null, 'new_pickup' => null, 'conflicts' => []];
        }

        $previous = $booking->pickup_datetime->toDateTimeImmutable();
        $newPickup = $snapshot->status === FlightStatus::Cancelled || $snapshot->estimatedArrivalAt === null || ! $booking->isEditable() && $booking->status !== BookingStatus::EnRouteToPickup
            ? null
            : $this->adjuster->adjustedPickup($previous, $snapshot->estimatedArrivalAt, $now);

        $conflicts = [];

        DB::transaction(function () use ($booking, $snapshot, $newPickup, $previous, $now, &$conflicts): void {
            $changes = [
                'flight_status' => $snapshot->status->value,
                'flight_delay_minutes' => $snapshot->delayMinutes,
                'estimated_flight_arrival_at' => $snapshot->estimatedArrivalAt,
                'flight_checked_at' => $now,
            ];

            if ($newPickup !== null) {
                $shift = $newPickup->getTimestamp() - $previous->getTimestamp();
                $changes['pickup_datetime'] = $newPickup;

                if ($booking->estimated_dropoff_datetime !== null) {
                    $changes['estimated_dropoff_datetime'] = CarbonImmutable::instance($booking->estimated_dropoff_datetime)->addSeconds($shift);
                }
            }

            $booking->update($changes);
            $conflicts = $newPickup === null ? [] : $this->assignment->conflicts($booking->refresh());
        });

        $adjusted = $newPickup !== null;

        if ($adjusted || $snapshot->status === FlightStatus::Cancelled) {
            $this->notify(new FlightScheduleAdjusted(
                (string) $booking->tenant_id,
                $booking->id,
                $booking->booking_code,
                $snapshot->status->value,
                $snapshot->delayMinutes,
                $adjusted ? $previous->format(DATE_ATOM) : null,
                $newPickup?->format(DATE_ATOM),
                array_map(static fn (ComplianceIssue $issue): string => $issue->code, $conflicts),
            ), $booking);
        }

        return [
            'status' => $snapshot->status->value,
            'delay_minutes' => $snapshot->delayMinutes,
            'adjusted' => $adjusted,
            'previous_pickup' => $adjusted ? $previous->format(DATE_ATOM) : null,
            'new_pickup' => $newPickup?->format(DATE_ATOM),
            'conflicts' => array_map(static fn (ComplianceIssue $issue): array => $issue->toArray(), $conflicts),
        ];
    }

    /**
     * Tracks every flight of the current company that lands within the tracking window; returns the number of transfers moved.
     */
    public function trackDue(?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable;
        $until = $now->modify('+'.(int) config('passenger_transport.flights.track_window_hours').' hours');
        $moved = 0;

        $bookings = PassengerBooking::query()
            ->whereNotNull('flight_or_train_number')
            ->whereNotNull('arrival_airport_iata')
            ->whereNotNull('scheduled_flight_arrival_at')
            ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::Assigned->value, BookingStatus::EnRouteToPickup->value])
            ->whereBetween('scheduled_flight_arrival_at', [$now->modify('-2 hours'), $until->modify('+1 day')])
            ->get();

        foreach ($bookings as $booking) {
            if ($booking->flight_status === FlightStatus::Landed) {
                continue;
            }

            $moved += $this->track($booking, $now)['adjusted'] ? 1 : 0;
        }

        return $moved;
    }

    private function notify(FlightScheduleAdjusted $event, PassengerBooking $booking): void
    {
        event($event);

        $userIds = DB::table('tenant_user')->where('tenant_id', $event->tenantId)->whereIn('role', self::DISPATCH_ROLES)->whereNull('deleted_at')->pluck('user_id')->all();
        $driverUserId = $booking->driver?->user_id;

        if ($driverUserId !== null) {
            $userIds[] = $driverUserId;
        }

        $users = User::query()->whereIn('id', array_values(array_unique($userIds)))->get();

        if ($users->isNotEmpty()) {
            Notification::send($users, new FlightScheduleChangedNotification($event));
        }
    }
}
