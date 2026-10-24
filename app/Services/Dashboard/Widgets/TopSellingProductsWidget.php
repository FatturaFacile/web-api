<?php

declare(strict_types=1);

namespace App\Services\Dashboard\Widgets;

use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Best sellers of the current month by quantity, from invoices (credit notes subtract, proformas ignored) or from
 * confirmed sales orders (drafts and cancelled orders ignored), which suits shops without invoicing.
 */
class TopSellingProductsWidget extends AbstractDashboardWidget
{
    public function getId(): string
    {
        return 'top_selling_products';
    }

    public function getType(): string
    {
        return 'bar_chart';
    }

    public function getDefaultDimensions(): array
    {
        return ['w' => 4, 'h' => 4];
    }

    public function getConfigurableOptions(): array
    {
        return [
            'limit' => ['type' => 'integer', 'min' => 1, 'max' => 20, 'default' => 5],
            'source' => ['type' => 'enum', 'values' => ['invoices', 'orders'], 'default' => 'invoices'],
        ];
    }

    public function getData(array $settings = []): array
    {
        $resolved = $this->resolveSettings($settings);
        $now = CarbonImmutable::now();
        $range = [$now->startOfMonth()->toDateString(), $now->endOfMonth()->toDateString()];

        $rows = $resolved['source'] === 'orders' ? $this->fromOrders($range, $resolved['limit']) : $this->fromInvoices($range, $resolved['limit']);
        $products = Product::withTrashed()->whereIn('id', $rows->pluck('product_id'))->get(['id', 'sku', 'name'])->keyBy('id');

        return [
            'currency' => $this->currency(),
            'source' => $resolved['source'],
            'from' => $range[0],
            'to' => $range[1],
            'products' => $rows->map(fn (object $row): array => [
                'product_id' => $row->product_id,
                'sku' => $products[$row->product_id]->sku ?? null,
                'name' => $products[$row->product_id]->name ?? null,
                'quantity' => round((float) $row->quantity, 4),
                'revenue' => round((float) $row->revenue, 2),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array{0: string, 1: string}  $range
     * @return Collection<int, object>
     */
    private function fromInvoices(array $range, int $limit)
    {
        return DB::table('invoice_items as item')
            ->join('invoices as invoice', 'invoice.id', '=', 'item.invoice_id')
            ->where('invoice.tenant_id', $this->tenantId())
            ->whereNull('invoice.deleted_at')
            ->where('invoice.invoice_type', '!=', 'proforma')
            ->whereBetween('invoice.date', $range)
            ->whereNotNull('item.product_id')
            ->selectRaw("item.product_id, SUM(CASE WHEN invoice.invoice_type = 'credit_note' THEN -item.quantity ELSE item.quantity END) as quantity, SUM(CASE WHEN invoice.invoice_type = 'credit_note' THEN -item.line_total ELSE item.line_total END) as revenue")
            ->groupBy('item.product_id')
            ->orderByDesc('quantity')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  array{0: string, 1: string}  $range
     * @return Collection<int, object>
     */
    private function fromOrders(array $range, int $limit)
    {
        return DB::table('sales_order_items as item')
            ->join('sales_orders as sales_order', 'sales_order.id', '=', 'item.sales_order_id')
            ->where('sales_order.tenant_id', $this->tenantId())
            ->whereNull('sales_order.deleted_at')
            ->whereNotIn('sales_order.status', ['draft', 'cancelled'])
            ->whereBetween('sales_order.date', $range)
            ->whereNotNull('item.product_id')
            ->selectRaw('item.product_id, SUM(item.quantity) as quantity, SUM(item.line_total) as revenue')
            ->groupBy('item.product_id')
            ->orderByDesc('quantity')
            ->limit($limit)
            ->get();
    }
}
