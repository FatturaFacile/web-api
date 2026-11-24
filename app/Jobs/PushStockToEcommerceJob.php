<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\EcommerceException;
use App\Models\EcommerceChannel;
use App\Models\EcommerceMapping;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Ecommerce\EcommerceManager;
use App\Services\Inventory\StockService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Aligns the available stock of one product (or of every mapped product) with every active channel that has stock
 * synchronisation enabled. It always sends the current absolute quantity, so repeating it is harmless, and a shop that
 * refuses an update is logged without failing the stock movement that triggered the job.
 */
class PushStockToEcommerceJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $tenantId,
        public readonly ?string $productId = null,
        public readonly ?string $channelId = null,
    ) {}

    public function handle(TenantContext $tenantContext, EcommerceManager $manager, StockService $stock): void
    {
        $tenant = Tenant::find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $tenantContext->runWithTenant($tenant, function () use ($tenant, $manager, $stock): void {
            $channels = EcommerceChannel::query()
                ->where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->where('sync_stock_enabled', true)
                ->when($this->channelId !== null, fn ($query) => $query->whereKey($this->channelId))
                ->get();

            foreach ($channels as $channel) {
                if ($channel->tenant_id !== $tenant->id || ! $channel->is_active || ! $channel->sync_stock_enabled) {
                    continue;
                }

                $driver = $manager->driver($channel);

                foreach ($this->products($channel) as $product) {
                    if ($product->tenant_id !== $tenant->id || ! $product->track_inventory) {
                        continue;
                    }

                    try {
                        $driver->pushStock($product, $stock->quantityOf($product->id));
                    } catch (EcommerceException $e) {
                        Log::warning('E-commerce stock push of product {product} to channel {channel} failed: {message}', [
                            'product' => $product->id,
                            'channel' => $channel->id,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }
            }
        });
    }

    /**
     * @return iterable<int, Product>
     */
    private function products(EcommerceChannel $channel): iterable
    {
        if ($this->productId !== null) {
            $isMapped = EcommerceMapping::withoutGlobalScopes()
                ->where('channel_id', $channel->id)
                ->where('tenant_id', $channel->tenant_id)
                ->where('local_id', $this->productId)
                ->whereIn('entity_type', ['product', 'product_variant'])
                ->exists();

            if (! $isMapped) {
                return [];
            }

            return Product::query()->whereKey($this->productId)->where('tenant_id', $channel->tenant_id)->get();
        }

        $ids = EcommerceMapping::query()
            ->where('channel_id', $channel->id)
            ->where('tenant_id', $channel->tenant_id)
            ->whereIn('entity_type', ['product', 'product_variant'])
            ->pluck('local_id');

        return Product::query()->whereIn('id', $ids)->where('tenant_id', $channel->tenant_id)->where('track_inventory', true)->get();
    }
}
