<?php

declare(strict_types=1);

namespace App\Services\Dashboard\Widgets;

use App\Models\Product;
use App\Services\Inventory\StockService;
use App\Services\Tenancy\TenantContext;

/**
 * Active, inventory-tracked products and variants whose stock is at or below their minimum stock (`min_stock`);
 * products without a minimum are not monitored.
 */
class LowStockWidget extends AbstractDashboardWidget
{
    public function __construct(TenantContext $tenantContext, private readonly StockService $stock)
    {
        parent::__construct($tenantContext);
    }

    public function getId(): string
    {
        return 'low_stock_alert';
    }

    public function getType(): string
    {
        return 'data_table';
    }

    public function getDefaultDimensions(): array
    {
        return ['w' => 4, 'h' => 3];
    }

    public function getConfigurableOptions(): array
    {
        return ['limit' => ['type' => 'integer', 'min' => 1, 'max' => 50, 'default' => 10]];
    }

    public function getData(array $settings = []): array
    {
        $limit = $this->resolveSettings($settings)['limit'];
        $levels = $this->stock->levels()->pluck('quantity', 'product_id');

        $low = Product::query()
            ->select(['id', 'sku', 'name', 'is_variant', 'parent_id', 'min_stock'])
            ->where('track_inventory', true)
            ->where('is_active', true)
            ->whereNotNull('min_stock')
            ->get()
            ->map(fn (Product $product): array => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'is_variant' => $product->is_variant,
                'parent_id' => $product->parent_id,
                'stock' => (float) ($levels[$product->id] ?? 0),
                'min_stock' => (float) $product->min_stock,
            ])
            ->filter(fn (array $row): bool => $row['stock'] <= $row['min_stock'])
            ->sortBy(fn (array $row): float => $row['stock'] - $row['min_stock'])
            ->values();

        return ['count' => $low->count(), 'products' => $low->take($limit)->all()];
    }
}
