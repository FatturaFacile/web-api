<?php

declare(strict_types=1);

namespace App\Contracts\Ecommerce;

use App\Exceptions\EcommerceException;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * One implementation per e-commerce platform. All drivers return the same normalised structures, so the sync actions
 * never depend on a platform.
 *
 * A normalised order is an array with: remote_id, number, status (raw platform status), canonical_status
 * (pending, processing, completed or cancelled), currency, date (Y-m-d), customer (remote_id, email, first_name,
 * last_name, company, phone, address{street, postal_code, city, state, country}) and lines (each with
 * remote_product_id, remote_variant_id, sku, name, quantity, unit_price excluding tax, tax_rate in percent).
 */
interface EcommerceDriverInterface
{
    /**
     * Whether the stored credentials are accepted by the platform. Never throws for an unreachable or refusing shop.
     */
    public function testConnection(): bool;

    /**
     * Orders created since the given moment, as normalised orders.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws EcommerceException
     */
    public function pullOrders(?Carbon $since = null): array;

    /**
     * Set the available quantity of a mapped product or variant on the platform.
     * Returns false when the product is not mapped on this channel, true when the quantity was accepted.
     *
     * @throws EcommerceException when the platform refuses the update
     */
    public function pushStock(Product $product, float $quantity): bool;

    /**
     * Create products on the platform. Each entry has local_id, sku, name, description, price, stock, type
     * (simple or variable) and variants (local_id, sku, price, stock, attributes). Returns one result per product with
     * the remote ids (and platform specific ids) of the product and its variants.
     *
     * @param  array<int, array<string, mixed>>  $products
     * @return array<int, array<string, mixed>>
     *
     * @throws EcommerceException
     */
    public function pushProducts(array $products): array;

    /**
     * Translate a webhook delivery into events; every order event carries a normalised order.
     *
     * @return array<int, array{type: string, order: array<string, mixed>}>
     *
     * @throws EcommerceException
     */
    public function handleWebhook(Request $request): array;

    /**
     * Whether the webhook carries a valid signature for the channel secret.
     */
    public function verifyWebhookSignature(Request $request): bool;
}
