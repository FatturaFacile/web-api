<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Application\Queries;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\OliveMillAndGrainMill\Domain\Enums\Commodity;
use Modules\OliveMillAndGrainMill\Domain\Enums\CycleStatus;
use Modules\OliveMillAndGrainMill\Domain\Enums\DeliveryStatus;
use Modules\OliveMillAndGrainMill\Models\ByproductMovement;
use Modules\OliveMillAndGrainMill\Models\MillingCycle;
use Modules\OliveMillAndGrainMill\Models\MillingSettlement;
use Modules\OliveMillAndGrainMill\Models\OliveDelivery;
use Modules\OliveMillAndGrainMill\Models\SianTank;

/**
 * Campaign KPIs of the control tower: crop milled, average yield, oil stock by category, the molenda oil kept, the SIAN deadlines and the hoppers.
 */
final class DashboardQueries
{
    public function __construct(private readonly MillQueries $queries) {}

    /**
     * @return array<string, mixed>
     */
    public function stats(?int $campaignYear = null): array
    {
        $year = $campaignYear ?? $this->currentCampaign();
        $deliveryIds = OliveDelivery::query()->where('campaign_year', $year)->pluck('id')->all();

        $cycles = MillingCycle::query()
            ->with('delivery:id,commodity')
            ->whereIn('delivery_id', $deliveryIds)
            ->whereIn('status', [CycleStatus::Completed->value, CycleStatus::Settled->value])
            ->get();

        $olive = $cycles->filter(static fn (MillingCycle $c): bool => $c->delivery->commodity === Commodity::Olive);
        $grain = $cycles->filter(static fn (MillingCycle $c): bool => $c->delivery->commodity === Commodity::Cereali);
        $oliveIn = (float) $olive->sum(static fn (MillingCycle $c): float => (float) $c->input_net_kg);
        $oliveOut = (float) $olive->sum(static fn (MillingCycle $c): float => (float) $c->output_kg);
        $grainIn = (float) $grain->sum(static fn (MillingCycle $c): float => (float) $c->input_net_kg);
        $grainOut = (float) $grain->sum(static fn (MillingCycle $c): float => (float) $c->output_kg);
        $alerts = $this->queries->complianceAlerts();

        return [
            'campaign_year' => $year,
            'olives_milled_kg' => round($oliveIn, 3),
            'olives_milled_quintals' => round($oliveIn / 100, 2),
            'oil_produced_kg' => round($oliveOut, 3),
            'average_yield_percent' => $oliveIn > 0.0 ? round($oliveOut / $oliveIn * 100, 2) : 0.0,
            'grain_milled_kg' => round($grainIn, 3),
            'flour_produced_kg' => round($grainOut, 3),
            'average_flour_yield_percent' => $grainIn > 0.0 ? round($grainOut / $grainIn * 100, 2) : 0.0,
            'cold_extraction_cycles' => $olive->filter(static fn (MillingCycle $c): bool => $c->cold_extraction)->count(),
            'deliveries_by_status' => $this->deliveriesByStatus($year),
            'oil_stock_by_category' => $this->stockByCategory(),
            'quality_breakdown' => $this->qualityBreakdown($olive),
            'molenda_retained_kg' => round((float) MillingSettlement::query()->whereIn('delivery_id', $deliveryIds)->sum('retained_kg'), 3),
            'sian' => ['overdue' => $alerts['overdue'], 'due_soon' => $alerts['due_soon'], 'alerts' => array_slice($alerts['items'], 0, 10)],
            'yield_trend' => $this->yieldTrend($olive),
            'hoppers' => $this->hoppers(),
            'byproducts_produced' => [
                'sansa_kg' => round((float) $cycles->sum(static fn (MillingCycle $c): float => (float) $c->sansa_kg), 3),
                'nocciolino_kg' => round((float) $cycles->sum(static fn (MillingCycle $c): float => (float) $c->nocciolino_kg), 3),
                'vegetation_water_l' => round((float) $cycles->sum(static fn (MillingCycle $c): float => (float) $c->vegetation_water_l), 3),
                'bran_kg' => round((float) $cycles->sum(static fn (MillingCycle $c): float => (float) $c->bran_kg), 3),
            ],
            'byproducts_moved_count' => ByproductMovement::query()->whereYear('movement_date', $year)->count(),
        ];
    }

    /**
     * Olives are crushed from October: until March the current campaign is the one of the previous year.
     */
    public function currentCampaign(): int
    {
        $now = Carbon::now();

        return $now->month <= 3 ? $now->year - 1 : $now->year;
    }

    /**
     * @return array<string, int>
     */
    private function deliveriesByStatus(int $year): array
    {
        $counts = array_fill_keys(DeliveryStatus::values(), 0);

        foreach (OliveDelivery::query()->where('campaign_year', $year)->get(['status']) as $delivery) {
            $counts[$delivery->status->value]++;
        }

        return $counts;
    }

    /**
     * @return list<array{category: string, stock_kg: float, capacity_kg: float, tanks: int}>
     */
    private function stockByCategory(): array
    {
        $groups = [];

        foreach (SianTank::query()->where('is_active', true)->get() as $tank) {
            $category = $tank->category;
            $key = $category === null ? 'EMPTY' : $category->value;
            $groups[$key] ??= ['category' => $key, 'stock_kg' => 0.0, 'capacity_kg' => 0.0, 'tanks' => 0];
            $groups[$key]['stock_kg'] = round($groups[$key]['stock_kg'] + (float) $tank->stock_kg, 3);
            $groups[$key]['capacity_kg'] = round($groups[$key]['capacity_kg'] + (float) $tank->capacity_kg, 3);
            $groups[$key]['tanks']++;
        }

        return array_values($groups);
    }

    /**
     * Oil produced by lab quality class.
     *
     * @param  Collection<int, MillingCycle>  $olive
     * @return array<string, float>
     */
    private function qualityBreakdown($olive): array
    {
        $result = [];

        foreach ($olive as $cycle) {
            $key = $cycle->quality_class ?? 'UNGRADED';
            $result[$key] = round(($result[$key] ?? 0.0) + (float) $cycle->output_kg, 3);
        }

        return $result;
    }

    /**
     * Average yield per day, weighted by the olives of each cycle.
     *
     * @param  Collection<int, MillingCycle>  $olive
     * @return list<array{date: string, yield_percent: float, cycles: int}>
     */
    private function yieldTrend($olive): array
    {
        $days = [];

        foreach ($olive as $cycle) {
            $date = ($cycle->ended_at ?? $cycle->started_at)->toDateString();
            $days[$date] ??= ['in' => 0.0, 'out' => 0.0, 'cycles' => 0];
            $days[$date]['in'] += (float) $cycle->input_net_kg;
            $days[$date]['out'] += (float) $cycle->output_kg;
            $days[$date]['cycles']++;
        }

        ksort($days);
        $trend = [];

        foreach ($days as $date => $day) {
            $trend[] = ['date' => $date, 'yield_percent' => $day['in'] > 0.0 ? round($day['out'] / $day['in'] * 100, 2) : 0.0, 'cycles' => $day['cycles']];
        }

        return array_slice($trend, -30);
    }

    /**
     * What each line is crushing now.
     *
     * @return list<array{line: string, cycle_id: string, lot_code: string, started_at: string, input_net_kg: string}>
     */
    private function hoppers(): array
    {
        return MillingCycle::query()
            ->with('delivery:id,lot_code')
            ->where('status', CycleStatus::InProgress->value)
            ->orderBy('started_at')
            ->get()
            ->map(static fn (MillingCycle $c): array => [
                'line' => $c->line_code ?? '',
                'cycle_id' => (string) $c->id,
                'lot_code' => $c->delivery->lot_code,
                'started_at' => $c->started_at->toIso8601String(),
                'input_net_kg' => $c->input_net_kg,
            ])
            ->values()
            ->all();
    }
}
