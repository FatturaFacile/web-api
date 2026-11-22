<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Application\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Modules\ResourcePlanning\Domain\Contracts\LeavePort;
use Modules\ResourcePlanning\Domain\Services\CapacityCalculator;
use Modules\ResourcePlanning\Domain\ValueObjects\LeaveSpan;
use Modules\ResourcePlanning\Domain\ValueObjects\LoadCell;
use Modules\ResourcePlanning\Domain\ValueObjects\PlannedBooking;
use Modules\ResourcePlanning\Domain\ValueObjects\ResourceProfile;

/**
 * Turns profiles, leave, holidays and bookings into the capacity grid every view reads: one cell per employee and period
 * (a day or a Monday-to-Sunday week) with net hours, booked hours and the load. Nothing is stored here.
 */
final class CapacityLoadService
{
    public function __construct(private readonly LeavePort $leave, private readonly CapacityCalculator $calculator) {}

    public static function weekStart(CarbonImmutable $day): CarbonImmutable
    {
        return $day->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
    }

    /**
     * @param  list<ResourceProfile>  $profiles
     * @param  list<PlannedBooking>  $bookings  bookings of any employee; those of employees without a profile are ignored
     * @return array<string, array<string, LoadCell>> cells keyed by employee id, then by the first day of the period
     */
    public function grid(array $profiles, array $bookings, CarbonImmutable $from, CarbonImmutable $to, bool $weekly): array
    {
        $start = $weekly ? self::weekStart($from) : $from->startOfDay();
        $end = $weekly ? self::weekStart($to)->addDays(6) : $to->startOfDay();
        $leave = $this->leave->approvedLeave(array_map(static fn (ResourceProfile $profile): string => $profile->employeeId, $profiles), $start, $end);

        $byEmployee = [];

        foreach ($bookings as $booking) {
            $byEmployee[$booking->employeeId][] = $booking;
        }

        $grid = [];

        foreach ($profiles as $profile) {
            $grid[$profile->employeeId] = $this->employeeCells($profile, $byEmployee[$profile->employeeId] ?? [], $leave[$profile->employeeId] ?? [], $start, $end, $weekly);
        }

        return $grid;
    }

    /**
     * The hours a booking really adds: its hours per day on every day the employee would work, holidays excluded.
     */
    public function totalHours(ResourceProfile $profile, CarbonImmutable $from, CarbonImmutable $to, float $hoursPerDay): float
    {
        $leave = $this->leave->approvedLeave([$profile->employeeId], $from, $to);
        $total = 0.0;

        foreach ($this->calculator->daily($profile, $from, $to, $leave[$profile->employeeId] ?? []) as $capacity) {
            if ($capacity->isBookable()) {
                $total += $hoursPerDay;
            }
        }

        return round($total, 2);
    }

    /**
     * @param  list<PlannedBooking>  $bookings
     * @param  list<LeaveSpan>  $leave
     * @return array<string, LoadCell>
     */
    private function employeeCells(ResourceProfile $profile, array $bookings, array $leave, CarbonImmutable $start, CarbonImmutable $end, bool $weekly): array
    {
        $capacities = $this->calculator->daily($profile, $start, $end, $leave);

        /** @var array<string, array<string, float>> $buckets */
        $buckets = [];
        $blank = ['contractual' => 0.0, 'off' => 0.0, 'net' => 0.0, 'hard' => 0.0, 'soft' => 0.0, 'billable' => 0.0, 'committed' => 0.0, 'internal' => 0.0];

        foreach ($capacities as $date => $capacity) {
            $key = $weekly ? self::weekStart(CarbonImmutable::parse($date))->format('Y-m-d') : $date;
            $buckets[$key] ??= $blank;
            $buckets[$key]['contractual'] += $capacity->contractualHours;
            $buckets[$key]['off'] += $capacity->holidayHours + $capacity->leaveHours;
            $buckets[$key]['net'] += $capacity->netHours();
        }

        foreach ($bookings as $booking) {
            for ($day = max($start, $booking->start->startOfDay()); $day <= min($end, $booking->end->startOfDay()); $day = $day->addDay()) {
                $date = $day->format('Y-m-d');

                if (! ($capacities[$date] ?? null)?->isBookable()) {
                    continue;
                }

                $key = $weekly ? self::weekStart($day)->format('Y-m-d') : $date;
                $buckets[$key][$booking->type->isCommitted() ? 'hard' : 'soft'] += $booking->hoursPerDay;
                $buckets[$key][$booking->billable ? 'billable' : 'internal'] += $booking->hoursPerDay;

                if ($booking->billable && $booking->type->isCommitted()) {
                    $buckets[$key]['committed'] += $booking->hoursPerDay;
                }
            }
        }

        $cells = [];

        foreach ($buckets as $key => $bucket) {
            $cells[$key] = new LoadCell($key, $bucket['contractual'], $bucket['off'], $bucket['net'], $bucket['hard'], $bucket['soft'], $bucket['billable'], $bucket['committed'], $bucket['internal']);
        }

        return $cells;
    }
}
