<?php

declare(strict_types=1);

namespace App\Listeners\Inventory;

use App\Events\Inventory\ProductVariantDeactivated;
use App\Jobs\PushStockToEcommerceJob;
use App\Models\EcommerceChannel;

/**
 * Tells the shops that a deactivated product is gone: the absolute quantity (zero or less) is pushed to every active
 * channel that synchronises stock, which makes the item unavailable on the platform.
 */
class PushVariantDeactivationToEcommerceListener
{
    public function handle(ProductVariantDeactivated $event): void
    {
        $hasChannel = EcommerceChannel::withoutGlobalScopes()
            ->where('tenant_id', $event->product->tenant_id)
            ->where('is_active', true)
            ->where('sync_stock_enabled', true)
            ->exists();

        if ($hasChannel) {
            PushStockToEcommerceJob::dispatch($event->product->tenant_id, $event->product->id)->afterCommit();
        }
    }
}
