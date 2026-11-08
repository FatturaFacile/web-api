<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Models\MfgOperationConfirmation;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use Modules\Manufacturing\Models\MfgShopFloorExecution;
use Modules\Manufacturing\Models\MfgWorkCenter;
use Modules\Manufacturing\Support\Money;

/**
 * Production KPIs computed only from recorded data; a ratio without data is null, never estimated.
 *
 * - Orders completed in the window: count, average lead time (actual start to actual end, days), on-time share
 *   (completed by the planned end date).
 * - Confirmations posted in the window: good and scrap quantities, scrap rate.
 * - OEE inputs per work centre: operating minutes (setup plus run confirmed), downtime reported on job cards and
 *   confirmations, ideal minutes (planned run minutes per unit of the order operation times the units processed), good
 *   and total units. Availability = operating / (operating + downtime), performance = ideal / run, quality =
 *   good / total; OEE is their product when all three exist. Utilization compares operating time with the calendar
 *   capacity of the work centre.
 * - Variances of the orders closed in the window.
 */
final readonly class ProductionKpiService
{
    public function __construct(private WorkCenterService $workCenters) {}

    /**
     * KPIs of the tenant between two dates, optionally of one work centre.
     *
     * @return array<string, mixed>
     */
    public function summary(string $tenantId, string $from, string $to, ?string $workCenterId = null): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->endOfDay();
        if ($end->lt($start) || $start->diffInDays($end) >= 366) {
            throw new ManufacturingOperationException('The KPI window must end after it starts and span at most 366 days.', 'to');
        }

        return [
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'orders' => $this->orders($tenantId, $start, $end),
            'quality' => $this->quality($tenantId, $start, $end, $workCenterId),
            'work_centers' => $this->workCenterInputs($tenantId, $start, $end, $workCenterId),
            'variances' => $this->variances($tenantId, $start, $end),
        ];
    }

    /**
     * @return array{completed: int, average_lead_time_days: string|null, on_time_percent: string|null}
     */
    private function orders(string $tenantId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $orders = MfgProductionOrder::query()->where('tenant_id', $tenantId)->whereIn('status', ['completed', 'closed'])
            ->whereBetween('actual_end_date', [$start, $end])->get();

        $leadDays = '0';
        $withStart = 0;
        $onTime = 0;
        foreach ($orders as $order) {
            if ($order->actual_start_date !== null && $order->actual_end_date !== null) {
                $leadDays = Money::add($leadDays, Money::div((string) $order->actual_start_date->diffInSeconds($order->actual_end_date), '86400'));
                $withStart++;
            }
            if ($order->actual_end_date !== null && $order->actual_end_date->lte($order->planned_end_date->copy()->endOfDay())) {
                $onTime++;
            }
        }

        return [
            'completed' => $orders->count(),
            'average_lead_time_days' => $withStart > 0 ? Money::round(Money::div($leadDays, (string) $withStart)) : null,
            'on_time_percent' => $orders->isNotEmpty() ? Money::round(Money::mul(Money::div((string) $onTime, (string) $orders->count()), '100')) : null,
        ];
    }

    /**
     * @return array{good_quantity: string, scrap_quantity: string, scrap_rate_percent: string|null}
     */
    private function quality(string $tenantId, CarbonImmutable $start, CarbonImmutable $end, ?string $workCenterId): array
    {
        $confirmations = $this->confirmations($tenantId, $start, $end, $workCenterId);
        $good = $confirmations->reduce(static fn (string $sum, MfgOperationConfirmation $row): string => Money::add($sum, $row->good_quantity), '0');
        $scrap = $confirmations->reduce(static fn (string $sum, MfgOperationConfirmation $row): string => Money::add($sum, $row->scrap_quantity), '0');
        $total = Money::add($good, $scrap);

        return [
            'good_quantity' => Money::qty($good),
            'scrap_quantity' => Money::qty($scrap),
            'scrap_rate_percent' => Money::isPositive($total) ? Money::round(Money::mul(Money::div($scrap, $total), '100')) : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function workCenterInputs(string $tenantId, CarbonImmutable $start, CarbonImmutable $end, ?string $workCenterId): array
    {
        $workCenters = MfgWorkCenter::query()->where('tenant_id', $tenantId)->when($workCenterId !== null, static fn ($query) => $query->whereKey($workCenterId))->orderBy('work_center_code')->get();
        $rows = [];

        foreach ($workCenters as $workCenter) {
            $confirmations = $this->confirmations($tenantId, $start, $end, (string) $workCenter->id);
            $operations = MfgProductionOrderOperation::query()->with('productionOrder')->whereIn('id', $confirmations->pluck('production_order_operation_id')->unique()->all())->get()->keyBy('id');

            $operating = '0';
            $run = '0';
            $ideal = '0';
            $good = '0';
            $total = '0';
            $downtime = '0';
            foreach ($confirmations as $confirmation) {
                $operating = Money::add($operating, Money::add($confirmation->setup_minutes, $confirmation->run_minutes));
                $run = Money::add($run, $confirmation->run_minutes);
                $downtime = Money::add($downtime, $confirmation->downtime_minutes);
                $processed = Money::add($confirmation->good_quantity, $confirmation->scrap_quantity);
                $good = Money::add($good, $confirmation->good_quantity);
                $total = Money::add($total, $processed);

                $operation = $operations->get($confirmation->production_order_operation_id);
                $target = $operation?->productionOrder?->target_quantity;
                if ($operation instanceof MfgProductionOrderOperation && $target !== null && Money::isPositive($target)) {
                    $ideal = Money::add($ideal, Money::mul(Money::div($operation->planned_run_minutes, $target), $processed));
                }
            }

            $downtime = Money::add($downtime, $this->openJobCardDowntime($tenantId, (string) $workCenter->id, $start, $end));
            $available = collect($this->workCenters->capacity($workCenter, $start->toDateString(), $end->toDateString()))
                ->reduce(static fn (string $sum, array $day): string => Money::add($sum, $day['available_hours']), '0');

            $availability = Money::isPositive(Money::add($operating, $downtime)) ? Money::div($operating, Money::add($operating, $downtime)) : null;
            $performance = Money::isPositive($run) && Money::isPositive($ideal) ? Money::div($ideal, $run) : null;
            $quality = Money::isPositive($total) ? Money::div($good, $total) : null;

            $rows[] = [
                'work_center_id' => (string) $workCenter->id,
                'work_center_code' => $workCenter->work_center_code,
                'operating_minutes' => Money::round($operating),
                'run_minutes' => Money::round($run),
                'downtime_minutes' => Money::round($downtime),
                'ideal_minutes' => Money::round($ideal),
                'good_units' => Money::qty($good),
                'total_units' => Money::qty($total),
                'available_hours' => Money::round($available),
                'utilization_percent' => Money::isPositive($available) ? Money::round(Money::mul(Money::div(Money::div($operating, '60'), $available), '100')) : null,
                'availability' => $availability !== null ? Money::round($availability, 4) : null,
                'performance' => $performance !== null ? Money::round($performance, 4) : null,
                'quality' => $quality !== null ? Money::round($quality, 4) : null,
                'oee' => $availability !== null && $performance !== null && $quality !== null ? Money::round(Money::mul(Money::mul($availability, $performance), $quality), 4) : null,
            ];
        }

        return $rows;
    }

    /**
     * @return array{orders_closed: int, material: string, labor: string, overhead: string, subcontract: string, total: string}
     */
    private function variances(string $tenantId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $orders = MfgProductionOrder::query()->where('tenant_id', $tenantId)->where('status', 'closed')->whereBetween('closed_at', [$start, $end])->get();
        $sum = static fn (string $column): string => Money::round($orders->reduce(static fn (string $carry, MfgProductionOrder $order): string => Money::add($carry, (string) $order->getAttribute($column)), '0'));

        return [
            'orders_closed' => $orders->count(),
            'material' => $sum('material_variance'),
            'labor' => $sum('labor_variance'),
            'overhead' => $sum('overhead_variance'),
            'subcontract' => $sum('subcontract_variance'),
            'total' => $sum('variance_total'),
        ];
    }

    /**
     * Confirmations posted in the window, optionally of one work centre.
     *
     * @return Collection<int, MfgOperationConfirmation>
     */
    private function confirmations(string $tenantId, CarbonImmutable $start, CarbonImmutable $end, ?string $workCenterId): Collection
    {
        return MfgOperationConfirmation::query()
            ->where('mfg_operation_confirmations.tenant_id', $tenantId)
            ->whereBetween('posting_date', [$start->toDateString(), $end->toDateString()])
            ->when($workCenterId !== null, static fn ($query) => $query->whereIn('production_order_operation_id', MfgProductionOrderOperation::query()->select('id')->where('work_center_id', $workCenterId)))
            ->get();
    }

    /**
     * Downtime reported on job cards that have not been completed yet (completed ones carry it on their confirmation).
     */
    private function openJobCardDowntime(string $tenantId, string $workCenterId, CarbonImmutable $start, CarbonImmutable $end): string
    {
        return MfgShopFloorExecution::query()->where('tenant_id', $tenantId)->where('work_center_id', $workCenterId)->where('status', 'active')
            ->whereBetween('started_at', [$start, $end])->get()
            ->reduce(static fn (string $sum, MfgShopFloorExecution $row): string => Money::add($sum, $row->downtime_minutes), '0');
    }
}
