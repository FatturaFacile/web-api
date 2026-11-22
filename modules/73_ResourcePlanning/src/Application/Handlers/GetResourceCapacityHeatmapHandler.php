<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Application\Handlers;

use Modules\ResourcePlanning\Application\Queries\GetResourceCapacityHeatmapQuery;
use Modules\ResourcePlanning\Application\Services\AllocationReader;
use Modules\ResourcePlanning\Application\Services\CapacityLoadService;
use Modules\ResourcePlanning\Domain\Contracts\ResourceDirectoryPort;
use Modules\ResourcePlanning\Domain\Enums\SaturationBand;
use Modules\ResourcePlanning\Domain\ValueObjects\LoadCell;
use Modules\ResourcePlanning\Domain\ValueObjects\ResourceProfile;

/**
 * The capacity heatmap: one row per employee, one cell per week (or day) with the net capacity, the committed and tentative
 * load and the colour band. Computed live from the contracts, calendars, leave and allocations.
 */
final class GetResourceCapacityHeatmapHandler
{
    public function __construct(
        private readonly ResourceDirectoryPort $directory,
        private readonly CapacityLoadService $load,
        private readonly AllocationReader $reader,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetResourceCapacityHeatmapQuery $query): array
    {
        $from = $query->weekly ? CapacityLoadService::weekStart($query->from) : $query->from->startOfDay();
        $to = $query->weekly ? CapacityLoadService::weekStart($query->to)->addDays(6) : $query->to->startOfDay();
        $profiles = $this->directory->resources($query->employeeIds, $query->departmentId, $from);
        $ids = array_map(static fn (ResourceProfile $profile): string => $profile->employeeId, $profiles);
        $grid = $this->load->grid($profiles, $ids === [] ? [] : $this->reader->bookings($ids, $from, $to), $from, $to, $query->weekly);

        $periods = [];

        for ($day = $from; $day <= $to; $day = $query->weekly ? $day->addWeek() : $day->addDay()) {
            $periods[] = $day->format('Y-m-d');
        }

        $rows = array_map(fn (ResourceProfile $profile): array => [
            'employee_id' => $profile->employeeId,
            'employee_name' => $profile->fullName,
            'department_id' => $profile->departmentId,
            'department_name' => $profile->departmentName,
            'cost_center_id' => $profile->costCenterId,
            'weekly_contract_hours' => round($profile->weeklyHours, 2),
            'cells' => array_map(
                static fn (string $period): array => ($grid[$profile->employeeId][$period] ?? new LoadCell($period, 0, 0, 0, 0, 0, 0, 0, 0))->toArray(),
                $periods,
            ),
        ], $profiles);

        return [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'granularity' => $query->weekly ? 'week' : 'day',
            'thresholds' => ['yellow' => SaturationBand::YellowFrom, 'red' => SaturationBand::RedAbove],
            'periods' => $periods,
            'rows' => $rows,
        ];
    }
}
