<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Application\Handlers;

use Illuminate\Support\Collection;
use Modules\ResourcePlanning\Application\Queries\GetBillableUtilizationReportQuery;
use Modules\ResourcePlanning\Application\Services\CapacityLoadService;
use Modules\ResourcePlanning\Application\Services\CapacitySnapshotService;
use Modules\ResourcePlanning\Domain\Contracts\ResourceDirectoryPort;
use Modules\ResourcePlanning\Domain\ValueObjects\ResourceProfile;
use Modules\ResourcePlanning\Infrastructure\Models\ResourceCapacitySnapshot;
use Modules\ResourcePlanning\Infrastructure\Models\UtilizationTarget;

/**
 * Billable saturation, target against actual. The weekly snapshots of the window are brought up to date first, then summed
 * by resource, department or cost centre. Actual counts committed billable bookings, forecast adds the tentative ones.
 */
final class GetBillableUtilizationReportHandler
{
    public function __construct(
        private readonly ResourceDirectoryPort $directory,
        private readonly CapacitySnapshotService $snapshots,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetBillableUtilizationReportQuery $query): array
    {
        $start = CapacityLoadService::weekStart($query->from);
        $end = CapacityLoadService::weekStart($query->to);
        $this->snapshots->sync(null, $start, $end);

        /** @var Collection<int, ResourceCapacitySnapshot> $rows */
        $rows = ResourceCapacitySnapshot::query()
            ->whereDate('week_start_date', '>=', $start->format('Y-m-d'))
            ->whereDate('week_start_date', '<=', $end->format('Y-m-d'))
            ->get();

        $profiles = collect($this->directory->resources(null, null, $start))->keyBy(static fn (ResourceProfile $profile): string => $profile->employeeId);
        $targets = UtilizationTarget::query()->get();
        $default = $this->scopeTarget($targets, null, null) ?? (float) config('resource_planning.default_billable_target', 75.0);

        $groups = [];

        foreach ($rows as $row) {
            [$key, $name, $target] = $this->group($query->groupBy, $row, $profiles->get($row->employee_id), $profiles, $targets, $default);
            $groups[$key] ??= ['key_id' => $key === '' ? null : $key, 'key_name' => $name, 'target' => $target, 'net' => 0.0, 'committed' => 0.0, 'billable' => 0.0, 'internal' => 0.0, 'tentative' => 0.0];
            $groups[$key]['net'] += (float) $row->net_available_hours;
            $groups[$key]['committed'] += (float) $row->billable_committed_hours;
            $groups[$key]['billable'] += (float) $row->billable_booked_hours;
            $groups[$key]['internal'] += (float) $row->internal_booked_hours;
            $groups[$key]['tentative'] += (float) $row->tentative_booked_hours;
        }

        $lines = array_map(fn (array $group): array => $this->line($group['key_id'], $group['key_name'], $group, $group['target']), array_values($groups));
        usort($lines, static fn (array $left, array $right): int => strcmp($left['key_name'], $right['key_name']));

        $totals = array_reduce(array_values($groups), static fn (array $carry, array $group): array => [
            'net' => $carry['net'] + $group['net'],
            'committed' => $carry['committed'] + $group['committed'],
            'billable' => $carry['billable'] + $group['billable'],
            'internal' => $carry['internal'] + $group['internal'],
            'tentative' => $carry['tentative'] + $group['tentative'],
        ], ['net' => 0.0, 'committed' => 0.0, 'billable' => 0.0, 'internal' => 0.0, 'tentative' => 0.0]);

        return [
            'from' => $start->format('Y-m-d'),
            'to' => $end->addDays(6)->format('Y-m-d'),
            'group_by' => $query->groupBy,
            'default_target_percent' => round($default, 2),
            'rows' => $lines,
            'totals' => $this->line(null, 'total', $totals, $default),
        ];
    }

    /**
     * @param  Collection<int, UtilizationTarget>  $targets
     * @param  Collection<string, ResourceProfile>  $profiles
     * @return array{0: string, 1: string, 2: float}
     */
    private function group(string $groupBy, ResourceCapacitySnapshot $row, ?ResourceProfile $profile, Collection $profiles, Collection $targets, float $default): array
    {
        $departmentTarget = $this->scopeTarget($targets, $row->department_id, null);
        $costCenterTarget = $this->scopeTarget($targets, null, $row->cost_center_id);

        return match ($groupBy) {
            GetBillableUtilizationReportQuery::ByDepartment => [(string) $row->department_id, $this->departmentName($profiles, (string) $row->department_id), $departmentTarget ?? $default],
            GetBillableUtilizationReportQuery::ByCostCenter => [(string) $row->cost_center_id, $this->costCenterName($profiles, (string) $row->cost_center_id), $costCenterTarget ?? $default],
            default => [$row->employee_id, $profile?->fullName ?? $row->employee_id, $departmentTarget ?? $costCenterTarget ?? $default],
        };
    }

    /**
     * @param  Collection<int, UtilizationTarget>  $targets
     */
    private function scopeTarget(Collection $targets, ?string $departmentId, ?string $costCenterId): ?float
    {
        $target = $targets->first(static fn (UtilizationTarget $candidate): bool => $candidate->department_id === $departmentId && $candidate->cost_center_id === $costCenterId);

        return $target === null ? null : (float) $target->target_percent;
    }

    /**
     * @param  Collection<string, ResourceProfile>  $profiles
     */
    private function departmentName(Collection $profiles, string $id): string
    {
        return $id === '' ? 'unassigned' : ($profiles->first(static fn (ResourceProfile $profile): bool => $profile->departmentId === $id)?->departmentName ?? $id);
    }

    /**
     * @param  Collection<string, ResourceProfile>  $profiles
     */
    private function costCenterName(Collection $profiles, string $id): string
    {
        return $id === '' ? 'unassigned' : ($profiles->first(static fn (ResourceProfile $profile): bool => $profile->costCenterId === $id)?->costCenterName ?? $id);
    }

    /**
     * @param  array{net: float, committed: float, billable: float, internal: float, tentative: float}  $sums
     * @return array<string, mixed>
     */
    private function line(?string $id, string $name, array $sums, float $target): array
    {
        $actual = $sums['net'] > 0 ? round($sums['committed'] / $sums['net'] * 100, 2) : 0.0;
        $forecast = $sums['net'] > 0 ? round($sums['billable'] / $sums['net'] * 100, 2) : 0.0;
        $gap = round($actual - $target, 2);
        $tolerance = (float) config('resource_planning.target_tolerance', 5.0);

        return [
            'key_id' => $id,
            'key_name' => $name,
            'net_available_hours' => round($sums['net'], 2),
            'billable_committed_hours' => round($sums['committed'], 2),
            'billable_booked_hours' => round($sums['billable'], 2),
            'internal_booked_hours' => round($sums['internal'], 2),
            'tentative_booked_hours' => round($sums['tentative'], 2),
            'actual_billable_percent' => $actual,
            'forecast_billable_percent' => $forecast,
            'target_percent' => round($target, 2),
            'gap_percent' => $gap,
            'status' => abs($gap) <= $tolerance ? 'on_target' : ($gap < 0 ? 'below_target' : 'above_target'),
        ];
    }
}
