<?php

declare(strict_types=1);

namespace Modules\Wms\Services;

use Illuminate\Support\Carbon;
use Modules\Wms\Models\WmsCrossDockOrder;
use Modules\Wms\Models\WmsDockAppointment;
use Modules\Wms\Models\WmsDockDoor;
use Modules\Wms\Models\WmsSlottingRecommendation;
use Modules\Wms\Models\WmsTask;
use Modules\Wms\Models\WmsWave;

/**
 * The numbers of the warehouse floor: task throughput per day and type, the open work, the share of picks closed short and
 * the utilization of the dock doors (booked time over the operating time of the period).
 */
final class WmsDashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(string $tenantId, int $days, ?string $warehouseId, int $operatingHours): array
    {
        $to = now();
        $from = $to->copy()->subDays($days - 1)->startOfDay();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days],
            'throughput' => $this->throughput($tenantId, $warehouseId, $from, $to),
            'open_tasks' => $this->openTasks($tenantId, $warehouseId),
            'pick_short_rate_percent' => $this->pickShortRate($tenantId, $warehouseId, $from, $to),
            'dock_utilization' => $this->dockUtilization($tenantId, $warehouseId, $from, $to, $days, $operatingHours),
            'pending_recommendations' => WmsSlottingRecommendation::query()->where('tenant_id', $tenantId)->where('status', WmsSlottingRecommendation::Pending)
                ->when($warehouseId !== null, static fn ($query) => $query->where('warehouse_id', $warehouseId))->count(),
            'open_waves' => WmsWave::query()->where('tenant_id', $tenantId)->whereIn('status', [WmsWave::Planned, WmsWave::Released])
                ->when($warehouseId !== null, static fn ($query) => $query->where('warehouse_id', $warehouseId))->count(),
            'open_cross_docks' => WmsCrossDockOrder::query()->where('tenant_id', $tenantId)->whereIn('status', [WmsCrossDockOrder::Planned, WmsCrossDockOrder::Received])
                ->when($warehouseId !== null, static fn ($query) => $query->where('warehouse_id', $warehouseId))->count(),
        ];
    }

    /**
     * Tasks closed by the operators (completed or short) per day, in total and by type.
     *
     * @return array{total: int, by_type: array<string, int>, per_day: list<array<string, int|string>>}
     */
    private function throughput(string $tenantId, ?string $warehouseId, Carbon $from, Carbon $to): array
    {
        $rows = WmsTask::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [WmsTask::Completed, WmsTask::ShortCompleted])
            ->whereBetween('completed_at', [$from, $to])
            ->when($warehouseId !== null, static fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->get(['task_type', 'completed_at']);

        $byType = array_fill_keys(WmsTask::TaskTypes, 0);
        $perDay = [];

        for ($day = $from->copy(); $day->lessThanOrEqualTo($to); $day->addDay()) {
            $perDay[$day->toDateString()] = ['date' => $day->toDateString(), 'total' => 0, ...array_fill_keys(WmsTask::TaskTypes, 0)];
        }

        foreach ($rows as $row) {
            $byType[$row->task_type]++;
            $date = $row->completed_at->toDateString();

            if (isset($perDay[$date])) {
                $perDay[$date]['total']++;
                $perDay[$date][$row->task_type]++;
            }
        }

        return ['total' => $rows->count(), 'by_type' => $byType, 'per_day' => array_values($perDay)];
    }

    /**
     * @return array{by_status: array<string, int>, by_type: array<string, int>, total: int}
     */
    private function openTasks(string $tenantId, ?string $warehouseId): array
    {
        $open = [WmsTask::Open, WmsTask::Assigned, WmsTask::InProgress];
        $rows = WmsTask::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', $open)
            ->when($warehouseId !== null, static fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->groupBy('status', 'task_type')
            ->selectRaw('status, task_type, COUNT(*) as total')
            ->toBase()
            ->get();

        $byStatus = array_fill_keys($open, 0);
        $byType = array_fill_keys(WmsTask::TaskTypes, 0);

        foreach ($rows as $row) {
            $byStatus[$row->status] += (int) $row->total;
            $byType[$row->task_type] += (int) $row->total;
        }

        return ['by_status' => $byStatus, 'by_type' => $byType, 'total' => array_sum($byStatus)];
    }

    /**
     * Share of the pick tasks closed in the period that were closed short; null when none was closed.
     */
    private function pickShortRate(string $tenantId, ?string $warehouseId, Carbon $from, Carbon $to): ?string
    {
        $counts = WmsTask::query()
            ->where('tenant_id', $tenantId)
            ->where('task_type', 'pick')
            ->whereIn('status', [WmsTask::Completed, WmsTask::ShortCompleted])
            ->whereBetween('completed_at', [$from, $to])
            ->when($warehouseId !== null, static fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as total')
            ->toBase()
            ->pluck('total', 'status');
        $short = (int) ($counts[WmsTask::ShortCompleted] ?? 0);
        $closed = $short + (int) ($counts[WmsTask::Completed] ?? 0);

        return $closed === 0 ? null : bcround(bcdiv(bcmul((string) $short, '100', 8), (string) $closed, 8), 2, \RoundingMode::HalfAwayFromZero);
    }

    /**
     * Booked minutes of the appointments (clipped to the period) over the operating minutes of the period, per active door
     * and overall; capped at 100.
     *
     * @return array{overall_percent: string, doors: list<array<string, mixed>>}
     */
    private function dockUtilization(string $tenantId, ?string $warehouseId, Carbon $from, Carbon $to, int $days, int $operatingHours): array
    {
        $doors = WmsDockDoor::query()->where('tenant_id', $tenantId)->where('is_active', true)
            ->when($warehouseId !== null, static fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->orderBy('code')->get(['id', 'code', 'name']);
        $appointments = WmsDockAppointment::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('dock_door_id', $doors->modelKeys())
            ->whereIn('status', [WmsDockAppointment::Scheduled, WmsDockAppointment::CheckedIn, WmsDockAppointment::Completed])
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->get(['dock_door_id', 'starts_at', 'ends_at'])
            ->groupBy('dock_door_id');

        $available = $days * $operatingHours * 60;
        $rows = [];
        $bookedTotal = 0;

        foreach ($doors as $door) {
            $booked = 0;

            foreach ($appointments->get($door->id, []) as $appointment) {
                $start = $appointment->starts_at->greaterThan($from) ? $appointment->starts_at : $from;
                $end = $appointment->ends_at->lessThan($to) ? $appointment->ends_at : $to;
                $booked += max(0, (int) $start->diffInMinutes($end, true));
            }

            $bookedTotal += $booked;
            $rows[] = ['door_id' => $door->id, 'code' => $door->code, 'name' => $door->name, 'booked_hours' => bcdiv((string) $booked, '60', 2), 'utilization_percent' => $this->percent($booked, $available)];
        }

        return ['overall_percent' => $this->percent($bookedTotal, $available * max(1, $doors->count())), 'doors' => $rows];
    }

    private function percent(int $booked, int $available): string
    {
        if ($available <= 0) {
            return '0.00';
        }

        $percent = bcround(bcdiv(bcmul((string) $booked, '100', 8), (string) $available, 8), 2, \RoundingMode::HalfAwayFromZero);

        return bccomp($percent, '100', 2) > 0 ? '100.00' : $percent;
    }
}
