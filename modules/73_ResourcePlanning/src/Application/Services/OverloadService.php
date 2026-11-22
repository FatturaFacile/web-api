<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Application\Services;

use Carbon\CarbonImmutable;
use Modules\ResourcePlanning\Application\DTOs\OverloadReport;
use Modules\ResourcePlanning\Domain\Contracts\ResourceDirectoryPort;
use Modules\ResourcePlanning\Domain\Enums\SaturationBand;
use Modules\ResourcePlanning\Domain\ValueObjects\LoadCell;
use Modules\ResourcePlanning\Domain\ValueObjects\PlannedBooking;
use Modules\ResourcePlanning\Domain\ValueObjects\ResourceProfile;
use Modules\ResourcePlanning\Infrastructure\Models\ResourceAllocation;

/**
 * Real-time overload detection: a week is overloaded when the bookings (tentative ones included) exceed 100 % of the net
 * capacity. The flag and the peak load are kept on every allocation so that lists and bars can show them without a query.
 */
final class OverloadService
{
    public function __construct(
        private readonly ResourceDirectoryPort $directory,
        private readonly CapacityLoadService $load,
        private readonly AllocationReader $reader,
    ) {}

    /**
     * The overloaded weeks of one employee between two dates.
     */
    public function report(string $employeeId, CarbonImmutable $from, CarbonImmutable $to): OverloadReport
    {
        $profiles = $this->directory->resources([$employeeId], null, $from);

        if ($profiles === []) {
            return new OverloadReport(false, 0.0, []);
        }

        $cells = $this->load->grid($profiles, $this->reader->bookings([$employeeId], $from, $to), $from, $to, true)[$employeeId] ?? [];
        $weeks = [];
        $peak = 0.0;

        foreach ($cells as $cell) {
            $peak = max($peak, $cell->loadPercent());

            if ($cell->isOverloaded()) {
                $weeks[] = ['week_start' => $cell->periodStart, 'load_percent' => $cell->loadPercent()];
            }
        }

        return new OverloadReport($weeks !== [], $peak, $weeks);
    }

    /**
     * The overloaded weeks the employee would have with an extra booking, leaving out some allocations (nothing is written).
     *
     * @param  list<string>  $excludeAllocationIds
     */
    public function simulate(ResourceProfile $profile, PlannedBooking $extra, array $excludeAllocationIds = []): OverloadReport
    {
        $bookings = $this->reader->overlapping([$profile->employeeId], $extra->start, $extra->end)
            ->when($excludeAllocationIds !== [], static fn ($query) => $query->whereNotIn('id', $excludeAllocationIds))
            ->get()
            ->map(static fn (ResourceAllocation $allocation): PlannedBooking => $allocation->toBooking())
            ->values()
            ->all();

        $cells = $this->load->grid([$profile], [...$bookings, $extra], $extra->start, $extra->end, true)[$profile->employeeId] ?? [];
        $weeks = [];
        $peak = 0.0;

        foreach ($cells as $cell) {
            $peak = max($peak, $cell->loadPercent());

            if ($cell->isOverloaded()) {
                $weeks[] = ['week_start' => $cell->periodStart, 'load_percent' => $cell->loadPercent()];
            }
        }

        return new OverloadReport($weeks !== [], $peak, $weeks);
    }

    /**
     * Recomputes the overload flag and peak load of every allocation of the employees that touches the window.
     *
     * @param  list<string>  $employeeIds
     */
    public function refresh(array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $employeeIds = array_values(array_unique($employeeIds));
        $touched = $this->reader->overlapping($employeeIds, $from, $to)->get();

        if ($touched->isEmpty()) {
            return;
        }

        // An allocation is judged over its whole length: widen the window to the allocations that touch it.
        $start = CapacityLoadService::weekStart(CarbonImmutable::parse($touched->min(static fn (ResourceAllocation $allocation): string => $allocation->start_date->format('Y-m-d'))));
        $end = CarbonImmutable::parse($touched->max(static fn (ResourceAllocation $allocation): string => $allocation->end_date->format('Y-m-d')));

        $allocations = $this->reader->overlapping($employeeIds, $start, $end)->get();
        $profiles = $this->directory->resources($employeeIds, null, $start);
        $grid = $this->load->grid($profiles, $allocations->map(static fn (ResourceAllocation $allocation) => $allocation->toBooking())->values()->all(), $start, $end, true);

        foreach ($allocations as $allocation) {
            $peak = $this->peak($grid[$allocation->employee_id] ?? [], $allocation);
            $overloaded = $peak > SaturationBand::RedAbove;

            if ($allocation->is_overloaded !== $overloaded || (float) $allocation->peak_load_percent !== $peak) {
                ResourceAllocation::query()->whereKey($allocation->getKey())->update(['is_overloaded' => $overloaded, 'peak_load_percent' => $peak]);
            }
        }
    }

    /**
     * @param  array<string, LoadCell>  $cells
     */
    private function peak(array $cells, ResourceAllocation $allocation): float
    {
        $peak = 0.0;
        $from = CapacityLoadService::weekStart($allocation->start_date);

        for ($week = $from; $week <= $allocation->end_date; $week = $week->addWeek()) {
            $cell = $cells[$week->format('Y-m-d')] ?? null;

            if ($cell !== null) {
                $peak = max($peak, $cell->loadPercent());
            }
        }

        return $peak;
    }
}
