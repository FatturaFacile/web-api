<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Infrastructure\Calculators;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\KpiDashboards\Domain\Enums\KpiCode;
use Modules\KpiDashboards\Domain\ValueObjects\CockpitFilter;
use Modules\KpiDashboards\Infrastructure\Services\KpiContext;
use Modules\KpiDashboards\Infrastructure\Services\LedgerAggregator;
use stdClass;

/**
 * Supply chain and operations cockpit: inventory turnover, OTIF, OEE (Mod 061) and scrap rate.
 */
final class OperationsKpiCalculator implements KpiCalculator
{
    public function __construct(private readonly LedgerAggregator $ledger) {}

    public function supports(): array
    {
        return [KpiCode::InventoryTurnover, KpiCode::Otif, KpiCode::Oee, KpiCode::ScrapRate];
    }

    public function headline(KpiCode $kpi, CockpitFilter $filter): ?float
    {
        return match ($kpi) {
            KpiCode::InventoryTurnover => $this->turnover($filter)['turnover'],
            KpiCode::Otif => $this->otif($filter)['rate'],
            KpiCode::Oee => $this->oee($filter)['oee'],
            KpiCode::ScrapRate => $this->scrap($filter)['rate'],
            default => null,
        };
    }

    public function payload(KpiCode $kpi, KpiContext $context): array
    {
        $filter = $context->filter;

        return match ($kpi) {
            KpiCode::InventoryTurnover => ['unit' => 'ratio', ...$this->moneyParts($context, $this->turnover($filter), ['cogs', 'average_inventory'])],
            KpiCode::Otif => ['unit' => 'percent', 'target' => 95, ...$this->otif($filter)],
            KpiCode::Oee => $this->oeePayload($filter),
            KpiCode::ScrapRate => $this->scrapPayload($filter),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $parts
     * @param  list<string>  $moneyKeys
     * @return array<string, mixed>
     */
    private function moneyParts(KpiContext $context, array $parts, array $moneyKeys): array
    {
        foreach ($moneyKeys as $key) {
            $parts[$key] = $context->money((float) $parts[$key]);
        }

        return $parts;
    }

    /**
     * Cost of goods sold over the average inventory of the period, annualized.
     *
     * @return array{turnover: float|null, cogs: float, average_inventory: float}
     */
    private function turnover(CockpitFilter $filter): array
    {
        $cogs = $this->ledger->net($filter, ['cogs'], $filter->from, $filter->to);
        $opening = $this->ledger->net($filter, ['asset'], null, $filter->from->subDay(), 'inventory');
        $closing = $this->ledger->net($filter, ['asset'], null, $filter->to, 'inventory');
        $average = ($opening + $closing) / 2;

        return ['turnover' => $average > 0 ? round($cogs / $average * (365 / $filter->days()), 2) : null, 'cogs' => $cogs, 'average_inventory' => round($average, 2)];
    }

    /**
     * Share of the orders due in the period delivered in full and on time: due = requested delivery date, in full =
     * delivered_percentage of 100, on time = last update (the last delivery) not after the due date.
     *
     * @return array{rate: float|null, orders: int, on_time_in_full: int, late: int, short: int}
     */
    private function otif(CockpitFilter $filter): array
    {
        $orders = DB::table('sales_orders')
            ->where('tenant_id', $filter->tenantId)->whereNull('deleted_at')
            ->whereNotIn('status', ['draft', 'cancelled', 'rejected'])
            ->whereDate('requested_delivery_date', '>=', $filter->from->toDateString())->whereDate('requested_delivery_date', '<=', $filter->to->toDateString())
            ->get(['requested_delivery_date', 'delivered_percentage', 'updated_at']);

        $ok = $late = $short = 0;

        foreach ($orders as $order) {
            $inFull = (float) $order->delivered_percentage >= 100.0;
            $onTime = CarbonImmutable::parse($order->updated_at)->toDateString() <= CarbonImmutable::parse($order->requested_delivery_date)->toDateString();

            if ($inFull && $onTime) {
                $ok++;
            } elseif (! $inFull) {
                $short++;
            } else {
                $late++;
            }
        }

        $total = $orders->count();

        return ['rate' => $total > 0 ? round($ok / $total * 100, 2) : null, 'orders' => $total, 'on_time_in_full' => $ok, 'late' => $late, 'short' => $short];
    }

    /**
     * Production orders finished in the period.
     *
     * @return Collection<int, stdClass>
     */
    private function finishedOrders(CockpitFilter $filter): Collection
    {
        return DB::table('mfg_production_orders')
            ->where('tenant_id', $filter->tenantId)->whereNull('deleted_at')
            ->whereNotNull('actual_end_date')
            ->whereDate('actual_end_date', '>=', $filter->from->toDateString())->whereDate('actual_end_date', '<=', $filter->to->toDateString())
            ->get(['mfg_item_id', 'target_quantity', 'completed_quantity', 'scrapped_quantity', 'planned_start_date', 'planned_end_date', 'actual_start_date', 'actual_end_date']);
    }

    /**
     * OEE = availability x performance x quality over the finished production orders. Availability is the planned
     * duration over the longer of planned and actual duration, performance the quantity produced (capped at the target)
     * over the target, quality the good share of the produced quantity.
     *
     * @return array{oee: float|null, availability: float|null, performance: float|null, quality: float|null, orders: int}
     */
    private function oee(CockpitFilter $filter): array
    {
        $orders = $this->finishedOrders($filter);
        $planned = $span = $target = $produced = $good = $totalProduced = 0.0;

        foreach ($orders as $order) {
            $plannedHours = $this->hours($order->planned_start_date, $order->planned_end_date);
            $actualHours = $this->hours($order->actual_start_date, $order->actual_end_date);
            $planned += $plannedHours;
            $span += max($plannedHours, $actualHours);
            $target += (float) $order->target_quantity;
            $produced += min((float) $order->completed_quantity, (float) $order->target_quantity);
            $totalProduced += (float) $order->completed_quantity;
            $good += (float) $order->completed_quantity - (float) $order->scrapped_quantity;
        }

        if ($orders->isEmpty() || $target <= 0 || $totalProduced <= 0) {
            return ['oee' => null, 'availability' => null, 'performance' => null, 'quality' => null, 'orders' => $orders->count()];
        }

        $availability = $span > 0 ? $planned / $span : 1.0;
        $performance = $produced / $target;
        $quality = $good / $totalProduced;

        return [
            'oee' => round($availability * $performance * $quality * 100, 2),
            'availability' => round($availability * 100, 2),
            'performance' => round($performance * 100, 2),
            'quality' => round($quality * 100, 2),
            'orders' => $orders->count(),
        ];
    }

    private function hours(mixed $start, mixed $end): float
    {
        if ($start === null || $end === null) {
            return 0.0;
        }

        return max(0.0, CarbonImmutable::parse((string) $start)->diffInSeconds(CarbonImmutable::parse((string) $end)) / 3600);
    }

    /**
     * @return array<string, mixed>
     */
    private function oeePayload(CockpitFilter $filter): array
    {
        $oee = $this->oee($filter);

        return [
            'unit' => 'percent',
            'target' => 75,
            'factors' => ['availability' => $oee['availability'], 'performance' => $oee['performance'], 'quality' => $oee['quality']],
            'orders' => $oee['orders'],
            'waterfall' => $oee['oee'] === null ? [] : [
                ['step' => 'available_time', 'amount' => 100.0],
                ['step' => 'availability_loss', 'amount' => round(-(100 - $oee['availability']), 2)],
                ['step' => 'performance_loss', 'amount' => round(-($oee['availability'] * (100 - $oee['performance']) / 100), 2)],
                ['step' => 'quality_loss', 'amount' => round(-($oee['availability'] * $oee['performance'] / 100 * (100 - $oee['quality']) / 100), 2)],
                ['step' => 'oee', 'amount' => $oee['oee'], 'is_total' => true],
            ],
        ];
    }

    /**
     * @return array{rate: float|null, scrapped: float, produced: float}
     */
    private function scrap(CockpitFilter $filter): array
    {
        $orders = $this->finishedOrders($filter);
        $produced = (float) $orders->sum('completed_quantity');
        $scrapped = (float) $orders->sum('scrapped_quantity');

        return ['rate' => $produced > 0 ? round($scrapped / $produced * 100, 2) : null, 'scrapped' => $scrapped, 'produced' => $produced];
    }

    /**
     * @return array<string, mixed>
     */
    private function scrapPayload(CockpitFilter $filter): array
    {
        $months = [];

        foreach ($this->finishedOrders($filter) as $order) {
            $month = CarbonImmutable::parse($order->actual_end_date)->format('Y-m');
            $months[$month]['produced'] = ($months[$month]['produced'] ?? 0.0) + (float) $order->completed_quantity;
            $months[$month]['scrapped'] = ($months[$month]['scrapped'] ?? 0.0) + (float) $order->scrapped_quantity;
        }

        ksort($months);

        return ['unit' => 'percent', ...$this->scrap($filter), 'series' => array_map(
            static fn (string $month, array $row): array => ['period' => $month, 'rate' => $row['produced'] > 0 ? round($row['scrapped'] / $row['produced'] * 100, 2) : null],
            array_keys($months), array_values($months),
        )];
    }
}
