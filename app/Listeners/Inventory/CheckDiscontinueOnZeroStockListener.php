<?php

declare(strict_types=1);

namespace App\Listeners\Inventory;

use App\Events\Inventory\ProductVariantDeactivated;
use App\Events\Inventory\StockMovementRecorded;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Inventory\StockService;

/**
 * Switches a product off when an outbound movement exhausts its stock, either because the tenant enabled
 * `inventory.auto_deactivate_on_zero_stock` or because the product carries the `discontinue_on_zero_stock` flag.
 * The deactivation is announced to the e-commerce channels unless `inventory.sync_deactivation_to_ecommerce` is off.
 */
class CheckDiscontinueOnZeroStockListener
{
    public function __construct(private readonly StockService $stock) {}

    public function handle(StockMovementRecorded $event): void
    {
        if (! $event->isOutbound()) {
            return;
        }

        $product = Product::withoutGlobalScopes()->find($event->movement->product_id);

        if ($product === null || ! $product->is_active) {
            return;
        }

        $preferences = Tenant::find($product->tenant_id)?->settings['preferences']['inventory'] ?? [];

        if (! ($preferences['auto_deactivate_on_zero_stock'] ?? false) && ! $product->discontinuesOnZeroStock()) {
            return;
        }

        if ($this->stock->quantityOf($product->id) > 0) {
            return;
        }

        $product->update(['is_active' => false]);

        if ($preferences['sync_deactivation_to_ecommerce'] ?? true) {
            ProductVariantDeactivated::dispatch($product);
        }
    }
}
