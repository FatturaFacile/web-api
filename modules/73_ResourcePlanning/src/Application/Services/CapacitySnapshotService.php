<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Application\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\ResourcePlanning\Domain\Contracts\ResourceDirectoryPort;
use Modules\ResourcePlanning\Domain\ValueObjects\ResourceProfile;
use Modules\ResourcePlanning\Infrastructure\Models\ResourceCapacitySnapshot;

/**
 * Keeps ResourceCapacitySnapshot in step with the bookings and the calendars: one row per employee and week, with the
 * department and cost centre of the day so that reports by organisational unit survive a transfer.
 */
final class CapacitySnapshotService
{
    public function __construct(
        private readonly ResourceDirectoryPort $directory,
        private readonly CapacityLoadService $load,
        private readonly AllocationReader $reader,
    ) {}

    /**
     * The stored snapshots of the weeks that start inside the window.
     *
     * @return list<ResourceCapacitySnapshot>
     */
    public function snapshots(CarbonImmutable $from, CarbonImmutable $to, ?string $employeeId): array
    {
        return ResourceCapacitySnapshot::query()
            ->whereDate('week_start_date', '>=', CapacityLoadService::weekStart($from)->format('Y-m-d'))
            ->whereDate('week_start_date', '<=', $to->format('Y-m-d'))
            ->when($employeeId !== null, static fn ($query) => $query->where('employee_id', $employeeId))
            ->orderBy('week_start_date')
            ->orderBy('employee_id')
            ->get()
            ->all();
    }

    /**
     * Recomputes the weekly snapshots from the Monday of $from to the Sunday of $to.
     *
     * @param  list<string>|null  $employeeIds  every employee with an active contract when null
     * @return array{weeks: int, snapshots: int}
     */
    public function sync(?array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = CapacityLoadService::weekStart($from);
        $end = CapacityLoadService::weekStart($to)->addDays(6);
        $profiles = $this->directory->resources($employeeIds, null, $start);

        if ($profiles === []) {
            return ['weeks' => 0, 'snapshots' => 0];
        }

        $ids = array_map(static fn (ResourceProfile $profile): string => $profile->employeeId, $profiles);
        $grid = $this->load->grid($profiles, $this->reader->bookings($ids, $start, $end), $start, $end, true);
        $written = 0;

        DB::transaction(function () use ($profiles, $grid, &$written): void {
            foreach ($profiles as $profile) {
                foreach ($grid[$profile->employeeId] ?? [] as $week => $cell) {
                    // whereDate: the date cast may be stored with a time part, so an exact match would miss the row.
                    $snapshot = ResourceCapacitySnapshot::query()
                        ->where('employee_id', $profile->employeeId)
                        ->whereDate('week_start_date', $week)
                        ->first() ?? new ResourceCapacitySnapshot(['employee_id' => $profile->employeeId, 'week_start_date' => $week]);
                    $snapshot->fill(
                        [
                            'contractual_hours' => round($cell->contractualHours, 2),
                            'leave_and_holiday_hours' => round($cell->leaveAndHolidayHours, 2),
                            'net_available_hours' => round($cell->netHours, 2),
                            'billable_booked_hours' => round($cell->billableHours, 2),
                            'billable_committed_hours' => round($cell->billableCommittedHours, 2),
                            'internal_booked_hours' => round($cell->internalHours, 2),
                            'tentative_booked_hours' => round($cell->softHours, 2),
                            'utilization_rate_percent' => $cell->loadPercent(),
                            'department_id' => $profile->departmentId,
                            'cost_center_id' => $profile->costCenterId,
                            'computed_at' => now(),
                        ],
                    )->save();
                    $written++;
                }
            }
        });

        return ['weeks' => (int) ($start->diffInWeeks($end->addDay())), 'snapshots' => $written];
    }
}
