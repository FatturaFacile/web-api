<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use Carbon\CarbonImmutable;
use Modules\Manufacturing\Models\MfgMachineCenter;
use Modules\Manufacturing\Models\MfgMrpRun;
use Modules\Manufacturing\Models\MfgMrpShortage;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgWipTransaction;
use Modules\Manufacturing\Support\Money;

/**
 * Single aggregated read model for plant managers: open orders by production strategy (MTO, MTS, ATO), live machine
 * states, OEE and bottleneck utilization of the last window, MRP exceptions of the latest run and the WIP valuation
 * against the standard cost of the open orders.
 */
final readonly class ManufacturingCockpitService
{
    public function __construct(private ProductionKpiService $kpis) {}

    /**
     * The cockpit of the tenant over the last `$windowDays` days.
     *
     * @return array<string, mixed>
     */
    public function overview(string $tenantId, int $windowDays = 30): array
    {
        $to = CarbonImmutable::now();
        $kpis = $this->kpis->summary($tenantId, $to->subDays($windowDays)->toDateString(), $to->toDateString());

        return [
            'orders' => $this->orders($tenantId),
            'machines' => $this->machines($tenantId),
            'oee' => $kpis['work_centers'],
            'bottlenecks' => $this->bottlenecks($kpis['work_centers']),
            'mrp_exceptions' => $this->mrpExceptions($tenantId),
            'wip' => $this->wip($tenantId),
            'kpis' => ['window_days' => $windowDays, 'orders' => $kpis['orders'], 'quality' => $kpis['quality'], 'variances' => $kpis['variances']],
        ];
    }

    /**
     * The production strategy of an order: the stored one, else MTO when it is pegged to a sales order, else MTS.
     */
    public static function strategyOf(MfgProductionOrder $order): string
    {
        return $order->production_strategy ?? ($order->sales_order_id !== null ? 'MTO' : 'MTS');
    }

    /**
     * @return array{by_strategy: array<string, int>, by_status: array<string, int>, open: int}
     */
    private function orders(string $tenantId): array
    {
        $byStrategy = ['MTO' => 0, 'MTS' => 0, 'ATO' => 0];
        $byStatus = [];
        $open = 0;

        foreach (MfgProductionOrder::query()->where('tenant_id', $tenantId)->whereIn('status', ['planned', 'released', 'in_progress', 'completed'])->get(['id', 'status', 'sales_order_id', 'production_strategy']) as $order) {
            $byStatus[$order->status] = ($byStatus[$order->status] ?? 0) + 1;
            if (in_array($order->status, ['released', 'in_progress'], true)) {
                $open++;
                $byStrategy[self::strategyOf($order)]++;
            }
        }

        return ['by_strategy' => $byStrategy, 'by_status' => $byStatus, 'open' => $open];
    }

    /**
     * @return array{by_state: array<string, int>, alerts: list<array{machine_id: string, machine_code: string, state: string, since: string|null}>}
     */
    private function machines(string $tenantId): array
    {
        $byState = array_fill_keys(['RUNNING', 'SETUP', 'IDLE', 'FAULT', 'MAINTENANCE'], 0);
        $alerts = [];

        foreach (MfgMachineCenter::query()->where('tenant_id', $tenantId)->get() as $machine) {
            $state = $machine->current_state ?? 'IDLE';
            $byState[$state] = ($byState[$state] ?? 0) + 1;
            if (in_array($state, ['FAULT', 'MAINTENANCE'], true)) {
                $alerts[] = ['machine_id' => (string) $machine->id, 'machine_code' => $machine->machine_code, 'state' => $state, 'since' => $machine->state_since?->toIso8601String()];
            }
        }

        return ['by_state' => $byState, 'alerts' => $alerts];
    }

    /**
     * Work centres above 85 percent utilization, the most loaded first.
     *
     * @param  list<array<string, mixed>>  $workCenters
     * @return list<array{work_center_id: string, work_center_code: string, utilization_percent: string}>
     */
    private function bottlenecks(array $workCenters): array
    {
        $loaded = [];
        foreach ($workCenters as $center) {
            if ($center['utilization_percent'] !== null && Money::compare($center['utilization_percent'], '85') >= 0) {
                $loaded[] = ['work_center_id' => (string) $center['work_center_id'], 'work_center_code' => (string) $center['work_center_code'], 'utilization_percent' => (string) $center['utilization_percent']];
            }
        }
        usort($loaded, static fn (array $a, array $b): int => Money::compare($b['utilization_percent'], $a['utilization_percent']));

        return $loaded;
    }

    /**
     * @return array{run_id: string|null, shortages: int, items: list<array{mfg_item_id: string, required_date: string, net_shortage: string, recommended_action: string}>}
     */
    private function mrpExceptions(string $tenantId): array
    {
        $run = MfgMrpRun::query()->where('tenant_id', $tenantId)->latest('created_at')->first();
        if (! $run instanceof MfgMrpRun) {
            return ['run_id' => null, 'shortages' => 0, 'items' => []];
        }

        $shortages = MfgMrpShortage::query()->where('tenant_id', $tenantId)->where('mrp_run_id', $run->id)->orderBy('required_date')->limit(20)->get();

        return [
            'run_id' => (string) $run->id,
            'shortages' => MfgMrpShortage::query()->where('tenant_id', $tenantId)->where('mrp_run_id', $run->id)->count(),
            'items' => $shortages->map(static fn (MfgMrpShortage $s): array => ['mfg_item_id' => (string) $s->mfg_item_id, 'required_date' => $s->required_date->toDateString(), 'net_shortage' => (string) $s->net_shortage, 'recommended_action' => (string) $s->recommended_action])->all(),
        ];
    }

    /**
     * Live WIP of the released and in-progress orders against their standard cost.
     *
     * @return array{valuation: string, standard_cost: string, variance: string}
     */
    private function wip(string $tenantId): array
    {
        $open = MfgProductionOrder::query()->where('tenant_id', $tenantId)->whereIn('status', MfgProductionOrder::OpenStatuses);
        $valuation = (string) MfgWipTransaction::query()->where('tenant_id', $tenantId)->whereIn('production_order_id', (clone $open)->select('id'))->sum('amount');
        $standard = (string) (clone $open)->sum('standard_cost_total');

        return ['valuation' => Money::round($valuation, 4), 'standard_cost' => Money::round($standard, 4), 'variance' => Money::round(Money::sub($valuation, $standard), 4)];
    }
}
