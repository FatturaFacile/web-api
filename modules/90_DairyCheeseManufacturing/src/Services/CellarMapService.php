<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Modules\DairyCheeseManufacturing\Models\AgingCareLog;
use Modules\DairyCheeseManufacturing\Models\AgingRack;
use Modules\DairyCheeseManufacturing\Models\CheeseAgingWheel;

/**
 * Visual map of the aging cellar: shelves with occupancy and overdue care, and for every shelf the wheels on it
 * (age, status, batch and whether care is overdue) for the colour coding.
 */
final class CellarMapService
{
    public function __construct(private readonly WheelMetrics $metrics, private readonly CareService $care) {}

    /**
     * @return Collection<int, AgingRack>
     */
    public function racks(): Collection
    {
        $racks = AgingRack::query()->orderBy('room')->orderBy('rack')->orderBy('level')->orderBy('shelf')->get();
        $overdue = AgingCareLog::query()->where('status', 'scheduled')->whereDate('scheduled_for', '<', CarbonImmutable::today()->toDateString())->get();
        $counts = array_fill_keys($racks->pluck('id')->all(), 0);

        foreach ($overdue as $log) {
            $targets = match ($log->scope) {
                'shelf' => [(string) $log->target_id],
                'rack' => $this->care->rackGroupIds((string) $log->target_id),
                'batch' => CheeseAgingWheel::query()->where('vat_batch_id', $log->target_id)->whereNotNull('rack_id')->distinct()->pluck('rack_id')->all(),
                default => CheeseAgingWheel::query()->whereKey($log->target_id)->whereNotNull('rack_id')->pluck('rack_id')->all(),
            };

            foreach (array_unique($targets) as $id) {
                if (isset($counts[$id])) {
                    $counts[$id]++;
                }
            }
        }

        $occupied = CheeseAgingWheel::query()->whereIn('status', ['brine', 'aging'])->whereNotNull('rack_id')->selectRaw('rack_id, COUNT(*) as total')->groupBy('rack_id')->pluck('total', 'rack_id');

        foreach ($racks as $rack) {
            $rack->setAttribute('occupied_count', (int) ($occupied[$rack->id] ?? 0));
            $rack->setAttribute('overdue_count', $counts[$rack->id] ?? 0);
        }

        return $racks;
    }

    /**
     * @return list<array{rack_id: string, wheels: list<array<string, mixed>>}>
     */
    public function cells(): array
    {
        $overdueWheels = $this->care->overdueWheelIds();
        $cells = [];

        $wheels = CheeseAgingWheel::query()->with('batch')->whereIn('status', ['brine', 'aging'])->whereNotNull('rack_id')->orderBy('position')->orderBy('serial')->get();

        foreach ($wheels->groupBy('rack_id') as $rackId => $group) {
            $cells[] = [
                'rack_id' => (string) $rackId,
                'wheels' => $group->map(fn (CheeseAgingWheel $w): array => [
                    'id' => $w->id, 'serial' => $w->serial, 'age_months' => number_format($this->metrics->ageMonths($w), 2, '.', ''), 'status' => $w->status,
                    'vat_batch_code' => $w->batch->code, 'overdue' => isset($overdueWheels[$w->id]),
                ])->values()->all(),
            ];
        }

        return $cells;
    }
}
