<?php

declare(strict_types=1);

namespace Modules\Transportation\Services;

use Illuminate\Support\Carbon;
use Modules\Transportation\Models\TmsFreightInvoice;
use Modules\Transportation\Models\TmsTransportOrder;
use Modules\Transportation\Models\TmsTrip;

/**
 * The numbers of the transport desk: on-time delivery, cost per km, cost per kg, the workload by status and what is still
 * contested with the carriers.
 */
final class TransportationDashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(string $tenantId, int $days): array
    {
        $to = now();
        $from = $to->copy()->subDays($days - 1)->startOfDay();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days],
            'on_time' => $this->onTime($tenantId, $from, $to),
            'cost_per_km' => $this->costPerKm($tenantId, $from, $to),
            'cost_per_kg' => $this->costPerKg($tenantId, $from, $to),
            'orders_by_status' => $this->countByStatus(TmsTransportOrder::query()->where('tenant_id', $tenantId), TmsTransportOrder::Statuses),
            'trips_by_status' => $this->countByStatus(TmsTrip::query()->where('tenant_id', $tenantId), TmsTrip::Statuses),
            'disputes' => $this->disputes($tenantId),
        ];
    }

    /**
     * Orders delivered in the period that have a delivery window, and the share delivered by the end of it.
     *
     * @return array{delivered: int, on_time: int, percent: string|null}
     */
    private function onTime(string $tenantId, Carbon $from, Carbon $to): array
    {
        $delivered = TmsTransportOrder::query()
            ->where('tenant_id', $tenantId)
            ->where('status', TmsTransportOrder::Delivered)
            ->whereBetween('delivered_at', [$from, $to])
            ->whereNotNull('delivery_to');
        $total = (clone $delivered)->count();
        $onTime = (clone $delivered)->whereColumn('delivered_at', '<=', 'delivery_to')->count();

        return ['delivered' => $total, 'on_time' => $onTime, 'percent' => $total === 0 ? null : bcdiv(bcmul((string) $onTime, '100', 6), (string) $total, 2)];
    }

    /**
     * Cost of the trips completed in the period over the kilometres they drove.
     *
     * @return array{trips: int, total_cost: string, total_km: string, value: string|null}
     */
    private function costPerKm(string $tenantId, Carbon $from, Carbon $to): array
    {
        $trips = TmsTrip::query()
            ->where('tenant_id', $tenantId)
            ->where('status', TmsTrip::Completed)
            ->whereBetween('actual_end_at', [$from, $to])
            ->whereNotNull('actual_cost')
            ->where('actual_distance_km', '>', 0)
            ->get(['actual_cost', 'actual_distance_km']);

        $cost = '0';
        $km = '0';

        foreach ($trips as $trip) {
            $cost = bcadd($cost, (string) $trip->actual_cost, 2);
            $km = bcadd($km, (string) $trip->actual_distance_km, 2);
        }

        return ['trips' => $trips->count(), 'total_cost' => $cost, 'total_km' => $km, 'value' => bccomp($km, '0', 2) > 0 ? bcdiv($cost, $km, 4) : null];
    }

    /**
     * Average cost per kg of the orders delivered in the period (the billed cost when known, else the expected one).
     *
     * @return array{orders: int, value: string|null}
     */
    private function costPerKg(string $tenantId, Carbon $from, Carbon $to): array
    {
        $orders = TmsTransportOrder::query()
            ->where('tenant_id', $tenantId)
            ->where('status', TmsTransportOrder::Delivered)
            ->whereBetween('delivered_at', [$from, $to])
            ->get(['actual_cost', 'expected_cost', 'weight_kg'])
            ->filter(static fn (TmsTransportOrder $order): bool => ($order->actual_cost ?? $order->expected_cost) !== null);

        $cost = '0';
        $weight = '0';

        foreach ($orders as $order) {
            $cost = bcadd($cost, (string) ($order->actual_cost ?? $order->expected_cost), 2);
            $weight = bcadd($weight, (string) $order->weight_kg, 3);
        }

        return ['orders' => $orders->count(), 'value' => bccomp($weight, '0', 3) > 0 ? bcdiv($cost, $weight, 4) : null];
    }

    /**
     * @return array{open: int, over_billed_amount: string, credited_amount: string}
     */
    private function disputes(string $tenantId): array
    {
        $invoices = TmsFreightInvoice::query()->where('tenant_id', $tenantId);
        $contested = (clone $invoices)->where('audit_status', TmsFreightInvoice::OverBilled)->whereIn('dispute_status', [TmsFreightInvoice::None, TmsFreightInvoice::Open])->get(['variance_amount']);
        $overBilled = $contested->reduce(static fn (string $sum, TmsFreightInvoice $invoice): string => bcadd($sum, (string) $invoice->variance_amount, 2), '0.00');
        $credited = (clone $invoices)->where('dispute_status', TmsFreightInvoice::ResolvedCredited)->get(['credited_amount'])
            ->reduce(static fn (string $sum, TmsFreightInvoice $invoice): string => bcadd($sum, (string) $invoice->credited_amount, 2), '0.00');

        return ['open' => (clone $invoices)->where('dispute_status', TmsFreightInvoice::Open)->count(), 'over_billed_amount' => $overBilled, 'credited_amount' => $credited];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     * @param  list<string>  $statuses
     * @return array<string, int>
     */
    private function countByStatus($query, array $statuses): array
    {
        $counts = array_fill_keys($statuses, 0);

        foreach ($query->groupBy('status')->selectRaw('status, COUNT(*) as total')->toBase()->get() as $row) {
            $counts[$row->status] = (int) $row->total;
        }

        return $counts;
    }
}
