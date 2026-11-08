<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use App\Enums\PurchaseOrderStatus;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\Support\Decimal;

/**
 * Purchase analytics of a company: spend by supplier, supplier category or cost centre (net order value of committed
 * orders converted with the order exchange rate), open purchase orders with their value still to receive, and price
 * variance of ordered prices against the supplier price list.
 */
final class ProcurementAnalyticsService
{
    public const array SpendDimensions = ['supplier', 'category', 'cost_center'];

    /**
     * Spend in the period grouped by the dimension, largest first.
     *
     * @return list<array{key: ?string, label: ?string, orders: int, spend: string}>
     */
    public function spend(string $tenantId, string $from, string $to, string $dimension): array
    {
        $query = DB::table('purchase_orders as po')
            ->leftJoin('suppliers as s', 's.id', '=', 'po.supplier_id')
            ->leftJoin('supplier_categories as sc', 'sc.id', '=', 's.supplier_category_id')
            ->where('po.tenant_id', $tenantId)
            ->whereNull('po.deleted_at')
            ->whereIn('po.status', PurchaseOrderStatus::committedValues())
            ->whereBetween('po.date', [$from, $to]);

        [$key, $label] = match ($dimension) {
            'category' => ['sc.id', 'sc.name'],
            'cost_center' => ['po.cost_center_id', 'po.cost_center_id'],
            default => ['po.supplier_id', 's.company_name'],
        };

        $rows = $query
            ->groupBy($key, $label)
            ->selectRaw("{$key} as group_key, {$label} as group_label, COUNT(*) as orders, COALESCE(SUM(COALESCE(NULLIF(po.net_amount, 0), po.total_net) * COALESCE(po.exchange_rate, 1)), 0) as spend")
            ->get();

        return $rows
            ->map(static fn (object $row): array => [
                'key' => $row->group_key !== null ? (string) $row->group_key : null,
                'label' => $row->group_label !== null ? (string) $row->group_label : null,
                'orders' => (int) $row->orders,
                'spend' => Decimal::of((string) $row->spend, 2),
            ])
            ->sortByDesc(static fn (array $row): string => str_pad($row['spend'], 24, '0', STR_PAD_LEFT))
            ->values()
            ->all();
    }

    /**
     * Orders still waiting for goods with the value not yet received (order currency).
     *
     * @return list<array{purchase_order_id: string, po_number: ?string, supplier_id: string, status: string, expected_delivery_date: ?string, currency: string, open_value: string, is_overdue: bool}>
     */
    public function openOrders(string $tenantId): array
    {
        $rows = DB::table('purchase_orders as po')
            ->join('purchase_order_lines as l', 'l.purchase_order_id', '=', 'po.id')
            ->where('po.tenant_id', $tenantId)
            ->whereNull('po.deleted_at')
            ->whereNull('l.deleted_at')
            ->whereIn('po.status', [PurchaseOrderStatus::Approved->value, PurchaseOrderStatus::Sent->value, PurchaseOrderStatus::PartiallyReceived->value])
            ->where('l.is_closed', false)
            ->groupBy('po.id', 'po.po_number', 'po.order_number', 'po.supplier_id', 'po.status', 'po.expected_delivery_date', 'po.currency')
            ->selectRaw('po.id, COALESCE(po.po_number, po.order_number) as po_number, po.supplier_id, po.status, po.expected_delivery_date, po.currency, SUM(GREATEST(l.quantity_ordered - l.quantity_cancelled - l.quantity_received, 0) * l.unit_price) as open_value')
            ->orderBy('po.expected_delivery_date')
            ->get();

        $today = now()->toDateString();

        return $rows->map(static fn (object $row): array => [
            'purchase_order_id' => (string) $row->id,
            'po_number' => $row->po_number !== null ? (string) $row->po_number : null,
            'supplier_id' => (string) $row->supplier_id,
            'status' => (string) $row->status,
            'expected_delivery_date' => $row->expected_delivery_date !== null ? substr((string) $row->expected_delivery_date, 0, 10) : null,
            'currency' => (string) $row->currency,
            'open_value' => Decimal::of((string) $row->open_value, 2),
            'is_overdue' => $row->expected_delivery_date !== null && substr((string) $row->expected_delivery_date, 0, 10) < $today,
        ])->all();
    }

    /**
     * Ordered prices against the price list of the supplier, per catalogue entry, in the period.
     *
     * @return list<array{supplier_catalog_id: string, supplier_id: string, description: ?string, quantity: string, ordered_value: string, list_value: string, variance_amount: string, variance_percent: string}>
     */
    public function priceVariance(string $tenantId, string $from, string $to): array
    {
        $rows = DB::table('purchase_order_lines as l')
            ->join('purchase_orders as po', 'po.id', '=', 'l.purchase_order_id')
            ->join('supplier_catalogs as c', 'c.id', '=', 'l.supplier_catalog_id')
            ->where('po.tenant_id', $tenantId)
            ->whereNull('po.deleted_at')
            ->whereNull('l.deleted_at')
            ->whereNotNull('l.list_unit_price')
            ->whereIn('po.status', PurchaseOrderStatus::committedValues())
            ->whereBetween('po.date', [$from, $to])
            ->groupBy('c.id', 'c.supplier_id', 'c.name')
            ->selectRaw('c.id, c.supplier_id, c.name, SUM(l.quantity_ordered) as quantity, SUM(l.quantity_ordered * l.unit_price) as ordered_value, SUM(l.quantity_ordered * l.list_unit_price) as list_value')
            ->get();

        return $rows->map(static function (object $row): array {
            $ordered = Decimal::of((string) $row->ordered_value, 2);
            $list = Decimal::of((string) $row->list_value, 2);
            $variance = Decimal::sub($ordered, $list, 2);

            return [
                'supplier_catalog_id' => (string) $row->id,
                'supplier_id' => (string) $row->supplier_id,
                'description' => $row->name !== null ? (string) $row->name : null,
                'quantity' => Decimal::of((string) $row->quantity, 4),
                'ordered_value' => $ordered,
                'list_value' => $list,
                'variance_amount' => $variance,
                'variance_percent' => Decimal::percentOf($variance, $list, 2),
            ];
        })->all();
    }
}
