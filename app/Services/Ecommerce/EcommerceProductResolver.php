<?php

declare(strict_types=1);

namespace App\Services\Ecommerce;

use App\Models\EcommerceChannel;
use App\Models\EcommerceMapping;
use App\Models\Product;

/**
 * Finds the ERP product (or variant) behind an order line of a channel. Order of precedence:
 *  1. the mapping of the remote variant (or of the remote product for simple items),
 *  2. an exact SKU match,
 *  3. the mapping of the remote parent product.
 */
class EcommerceProductResolver
{
    /**
     * @param  array<string, mixed>  $line  a normalised order line
     */
    public function resolve(EcommerceChannel $channel, array $line): ?Product
    {
        $variantId = $line['remote_variant_id'] ?? null;
        $productId = $line['remote_product_id'] ?? null;
        $sku = $line['sku'] ?? null;

        if ($variantId !== null) {
            $product = $this->mapped($channel, 'product_variant', $variantId);
        } else {
            $product = $productId !== null ? $this->mapped($channel, 'product', $productId) : null;
        }

        if ($product === null && $sku !== null) {
            $product = Product::query()->where('tenant_id', $channel->tenant_id)->where('sku', $sku)->first();
        }

        if ($product === null && $variantId !== null && $productId !== null) {
            $product = $this->mapped($channel, 'product', $productId);
        }

        return $product;
    }

    private function mapped(EcommerceChannel $channel, string $entityType, string $remoteId): ?Product
    {
        $mapping = EcommerceMapping::query()
            ->where('tenant_id', $channel->tenant_id)
            ->where('channel_id', $channel->id)
            ->where('entity_type', $entityType)
            ->where('remote_id', $remoteId)
            ->first();

        return $mapping !== null ? Product::query()->where('tenant_id', $channel->tenant_id)->find($mapping->local_id) : null;
    }
}
