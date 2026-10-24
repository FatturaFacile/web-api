<?php

declare(strict_types=1);

namespace App\Services\Ecommerce\Drivers;

use App\Exceptions\EcommerceException;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * WooCommerce / WordPress REST API v3. Consumer key and secret go in HTTP Basic authentication and webhooks are signed
 * with HMAC-SHA256 (base64) in `X-WC-Webhook-Signature`. Variable products and their variations are supported.
 */
class WooCommerceDriver extends AbstractEcommerceDriver
{
    protected function baseUrl(): string
    {
        return $this->apiUrl().'/wp-json/wc/v3';
    }

    protected function http(): PendingRequest
    {
        return Http::withBasicAuth($this->credential('consumer_key'), $this->credential('consumer_secret'))
            ->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS);
    }

    public function testConnection(): bool
    {
        try {
            return $this->http()->get("{$this->baseUrl()}/products", ['per_page' => 1])->successful();
        } catch (ConnectionException|EcommerceException) {
            return false;
        }
    }

    public function pullOrders(?Carbon $since = null): array
    {
        $orders = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $query = array_filter([
                'per_page' => self::PAGE_SIZE,
                'page' => $page,
                'orderby' => 'date',
                'order' => 'asc',
                'after' => $since?->copy()->utc()->toIso8601String(),
            ]);

            $batch = $this->jsonBody($this->ensureSuccessful($this->http()->get("{$this->baseUrl()}/orders", $query), 'orders'), 'orders');

            foreach ($batch as $order) {
                $orders[] = $this->normalizeOrder($order);
            }

            if (count($batch) < self::PAGE_SIZE) {
                break;
            }
        }

        return $orders;
    }

    public function pushStock(Product $product, float $quantity): bool
    {
        $mapping = $this->mappingFor($product);

        if ($mapping === null) {
            return false;
        }

        if ($mapping->entity_type === 'product_variant') {
            $parent = $mapping->extra_data['parent_remote_id'] ?? null;

            if ($parent === null) {
                throw EcommerceException::because(EcommerceException::MISSING_CONFIGURATION, ['field' => 'parent_remote_id']);
            }

            $url = "{$this->baseUrl()}/products/{$parent}/variations/{$mapping->remote_id}";
        } else {
            $url = "{$this->baseUrl()}/products/{$mapping->remote_id}";
        }

        $this->ensureSuccessful($this->http()->put($url, ['manage_stock' => true, 'stock_quantity' => $this->stockQuantity($quantity)]), 'stock update');

        return true;
    }

    public function pushProducts(array $products): array
    {
        $results = [];

        foreach ($products as $product) {
            $variable = $product['type'] === 'variable';
            $payload = [
                'name' => $product['name'],
                'type' => $variable ? 'variable' : 'simple',
                'sku' => $product['sku'],
                'description' => (string) ($product['description'] ?? ''),
                'regular_price' => number_format((float) $product['price'], 2, '.', ''),
            ];

            if ($variable) {
                $payload['attributes'] = $this->attributeDefinitions($product['variants']);
            } else {
                $payload += ['manage_stock' => true, 'stock_quantity' => $this->stockQuantity((float) $product['stock'])];
            }

            $created = $this->jsonBody($this->ensureSuccessful($this->http()->post("{$this->baseUrl()}/products", $payload), 'product creation'), 'product creation');
            $remoteId = (string) ($created['id'] ?? '');

            $variants = [];

            foreach ($variable ? $product['variants'] : [] as $variant) {
                $variation = $this->jsonBody($this->ensureSuccessful($this->http()->post("{$this->baseUrl()}/products/{$remoteId}/variations", [
                    'sku' => $variant['sku'],
                    'regular_price' => number_format((float) $variant['price'], 2, '.', ''),
                    'manage_stock' => true,
                    'stock_quantity' => $this->stockQuantity((float) $variant['stock']),
                    'attributes' => collect($variant['attributes'])->map(fn (string $option, string $name): array => ['name' => $name, 'option' => $option])->values()->all(),
                ]), 'variation creation'), 'variation creation');

                $variants[] = ['local_id' => $variant['local_id'], 'remote_id' => (string) ($variation['id'] ?? ''), 'parent_remote_id' => $remoteId];
            }

            $results[] = ['local_id' => $product['local_id'], 'remote_id' => $remoteId, 'variants' => $variants];
        }

        return $results;
    }

    public function handleWebhook(Request $request): array
    {
        $topic = (string) $request->header('X-WC-Webhook-Topic');
        $body = $request->json()->all();

        // Only order topics carry an order; the creation "ping" and other topics are acknowledged and ignored.
        if (! str_starts_with($topic, 'order.') || ! isset($body['id'])) {
            return [];
        }

        return [['type' => 'order', 'order' => $this->normalizeOrder($body)]];
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        return $this->signatureMatches($this->bodyHmac($request, base64: true), $request->header('X-WC-Webhook-Signature'));
    }

    protected function normalizeOrder(array $order): array
    {
        $billing = $order['billing'] ?? [];
        $lines = [];

        foreach ($order['line_items'] ?? [] as $item) {
            $quantity = (float) ($item['quantity'] ?? 0);
            $net = (float) ($item['total'] ?? 0);

            $lines[] = [
                'remote_product_id' => isset($item['product_id']) ? (string) $item['product_id'] : null,
                'remote_variant_id' => ! empty($item['variation_id']) ? (string) $item['variation_id'] : null,
                'sku' => ($item['sku'] ?? '') !== '' ? (string) $item['sku'] : null,
                'name' => (string) ($item['name'] ?? ''),
                'quantity' => $quantity,
                'unit_price' => $quantity > 0 ? round($net / $quantity, 4) : 0.0,
                'tax_rate' => $this->taxRate($net, (float) ($item['total_tax'] ?? 0)),
            ];
        }

        foreach ($order['shipping_lines'] ?? [] as $shipping) {
            $net = (float) ($shipping['total'] ?? 0);

            $lines[] = [
                'remote_product_id' => null,
                'remote_variant_id' => null,
                'sku' => null,
                'name' => __('ecommerce.order.shipping', ['method' => $shipping['method_title'] ?? __('ecommerce.order.shipping_fallback')]),
                'quantity' => 1.0,
                'unit_price' => $net,
                'tax_rate' => $this->taxRate($net, (float) ($shipping['total_tax'] ?? 0)),
            ];
        }

        $status = (string) ($order['status'] ?? 'pending');

        return [
            'remote_id' => (string) $order['id'],
            'number' => (string) ($order['number'] ?? $order['id']),
            'status' => $status,
            'canonical_status' => match ($status) {
                'processing' => 'processing',
                'completed' => 'completed',
                'cancelled', 'refunded', 'failed' => 'cancelled',
                default => 'pending', // pending, on-hold
            },
            'currency' => strtoupper((string) ($order['currency'] ?? 'EUR')),
            'date' => substr((string) ($order['date_created_gmt'] ?? $order['date_created'] ?? now()->toDateString()), 0, 10),
            'customer' => [
                'remote_id' => ! empty($order['customer_id']) ? (string) $order['customer_id'] : null,
                'email' => (string) ($billing['email'] ?? ''),
                'first_name' => (string) ($billing['first_name'] ?? ''),
                'last_name' => (string) ($billing['last_name'] ?? ''),
                'company' => (string) ($billing['company'] ?? ''),
                'phone' => (string) ($billing['phone'] ?? ''),
                'address' => [
                    'street' => trim(($billing['address_1'] ?? '').' '.($billing['address_2'] ?? '')),
                    'postal_code' => (string) ($billing['postcode'] ?? ''),
                    'city' => (string) ($billing['city'] ?? ''),
                    'state' => (string) ($billing['state'] ?? ''),
                    'country' => strtoupper((string) ($billing['country'] ?? '')),
                ],
            ],
            'lines' => $lines,
        ];
    }

    /**
     * Attribute definitions of a variable product, built from its variants: name and every option in use.
     *
     * @param  array<int, array<string, mixed>>  $variants
     * @return array<int, array<string, mixed>>
     */
    private function attributeDefinitions(array $variants): array
    {
        $options = [];

        foreach ($variants as $variant) {
            foreach ($variant['attributes'] as $name => $option) {
                $options[$name][$option] = $option;
            }
        }

        return collect($options)->map(fn (array $values, string $name): array => [
            'name' => $name,
            'visible' => true,
            'variation' => true,
            'options' => array_values($values),
        ])->values()->all();
    }
}
