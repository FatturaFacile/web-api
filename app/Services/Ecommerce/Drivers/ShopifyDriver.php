<?php

declare(strict_types=1);

namespace App\Services\Ecommerce\Drivers;

use App\Exceptions\EcommerceException;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Shopify Admin REST API. The access token travels in `X-Shopify-Access-Token`, webhooks are signed with HMAC-SHA256
 * (base64) in `X-Shopify-Hmac-Sha256`. Stock is set per `inventory_item_id` at the location of the channel settings.
 * Products map to a Shopify product, variants to its `variants`.
 */
class ShopifyDriver extends AbstractEcommerceDriver
{
    private const DEFAULT_API_VERSION = '2024-10';

    protected function baseUrl(): string
    {
        $root = (string) $this->channel->api_url !== ''
            ? $this->apiUrl()
            : 'https://'.$this->credential('shop_domain');

        return rtrim($root, '/').'/admin/api/'.$this->setting('api_version', self::DEFAULT_API_VERSION);
    }

    protected function http(): PendingRequest
    {
        return Http::withHeaders(['X-Shopify-Access-Token' => $this->credential('access_token')])
            ->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS);
    }

    public function testConnection(): bool
    {
        try {
            return $this->http()->get("{$this->baseUrl()}/shop.json")->successful();
        } catch (ConnectionException|EcommerceException) {
            return false;
        }
    }

    public function pullOrders(?Carbon $since = null): array
    {
        $orders = [];
        $url = "{$this->baseUrl()}/orders.json";
        $query = array_filter(['status' => 'any', 'limit' => 250, 'created_at_min' => $since?->copy()->utc()->toIso8601String()]);

        for ($page = 1; $url !== null && $page <= self::MAX_PAGES; $page++) {
            $response = $this->ensureSuccessful($this->http()->get($url, $query), 'orders');

            foreach ($this->jsonBody($response, 'orders')['orders'] ?? [] as $order) {
                $orders[] = $this->normalizeOrder($order);
            }

            // Cursor pagination: the next page URL already carries every parameter.
            $url = $this->nextPageUrl($response);
            $query = [];
        }

        return $orders;
    }

    public function pushStock(Product $product, float $quantity): bool
    {
        $mapping = $this->mappingFor($product);
        $inventoryItemId = $mapping?->extra_data['inventory_item_id'] ?? null;

        if ($mapping === null || $inventoryItemId === null) {
            return false;
        }

        $this->setInventoryLevel((string) $inventoryItemId, $quantity);

        return true;
    }

    public function pushProducts(array $products): array
    {
        $results = [];

        foreach ($products as $product) {
            $variable = $product['type'] === 'variable';
            $variants = $variable ? $product['variants'] : [['local_id' => $product['local_id'], 'sku' => $product['sku'], 'price' => $product['price'], 'stock' => $product['stock'], 'attributes' => []]];
            $optionNames = $variable ? array_keys($variants[0]['attributes']) : [];

            $payload = ['product' => array_filter([
                'title' => $product['name'],
                'body_html' => (string) ($product['description'] ?? ''),
                'options' => array_map(fn (string $name): array => ['name' => $name], $optionNames) ?: null,
                'variants' => array_map(function (array $variant) use ($optionNames): array {
                    $row = [
                        'sku' => $variant['sku'],
                        'price' => number_format((float) $variant['price'], 2, '.', ''),
                        'inventory_management' => 'shopify',
                    ];

                    foreach ($optionNames as $index => $name) {
                        $row['option'.($index + 1)] = (string) ($variant['attributes'][$name] ?? '');
                    }

                    return $row;
                }, $variants),
            ])];

            $created = $this->jsonBody($this->ensureSuccessful($this->http()->post("{$this->baseUrl()}/products.json", $payload), 'product creation'), 'product creation')['product'] ?? [];
            $remoteVariants = collect($created['variants'] ?? [])->keyBy('sku');

            $result = ['local_id' => $product['local_id'], 'remote_id' => (string) ($created['id'] ?? ''), 'variants' => []];

            foreach ($variants as $variant) {
                $remote = $remoteVariants->get($variant['sku'], []);
                $inventoryItemId = isset($remote['inventory_item_id']) ? (string) $remote['inventory_item_id'] : null;

                if ($inventoryItemId !== null && $this->setting('location_id') !== null) {
                    $this->setInventoryLevel($inventoryItemId, (float) $variant['stock']);
                }

                if ($variable) {
                    $result['variants'][] = [
                        'local_id' => $variant['local_id'],
                        'remote_id' => (string) ($remote['id'] ?? ''),
                        'parent_remote_id' => $result['remote_id'],
                        'inventory_item_id' => $inventoryItemId,
                    ];
                } else {
                    $result['variant_id'] = isset($remote['id']) ? (string) $remote['id'] : null;
                    $result['inventory_item_id'] = $inventoryItemId;
                }
            }

            $results[] = $result;
        }

        return $results;
    }

    public function handleWebhook(Request $request): array
    {
        $topic = (string) $request->header('X-Shopify-Topic');
        $body = $request->json()->all();

        if (! str_starts_with($topic, 'orders/') || ! isset($body['id'])) {
            return [];
        }

        return [['type' => 'order', 'order' => $this->normalizeOrder($body)]];
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        return $this->signatureMatches($this->bodyHmac($request, base64: true), $request->header('X-Shopify-Hmac-Sha256'));
    }

    protected function normalizeOrder(array $order): array
    {
        $taxesIncluded = (bool) ($order['taxes_included'] ?? false);
        $customer = $order['customer'] ?? [];
        $billing = $order['billing_address'] ?? [];
        $lines = [];

        foreach ($order['line_items'] ?? [] as $item) {
            $quantity = (float) ($item['quantity'] ?? 0);
            $rate = (float) collect($item['tax_lines'] ?? [])->sum('rate') * 100;
            $discount = (float) collect($item['discount_allocations'] ?? [])->sum('amount');
            $unit = $quantity > 0 ? ((float) $item['price'] * $quantity - $discount) / $quantity : 0.0;

            $lines[] = [
                'remote_product_id' => ! empty($item['product_id']) ? (string) $item['product_id'] : null,
                'remote_variant_id' => ! empty($item['variant_id']) ? (string) $item['variant_id'] : null,
                'sku' => ($item['sku'] ?? '') !== '' ? (string) $item['sku'] : null,
                'name' => trim((string) ($item['name'] ?? $item['title'] ?? '')),
                'quantity' => $quantity,
                'unit_price' => round($taxesIncluded ? $unit / (1 + $rate / 100) : $unit, 4),
                'tax_rate' => round($rate, 2),
            ];
        }

        foreach ($order['shipping_lines'] ?? [] as $shipping) {
            $rate = (float) collect($shipping['tax_lines'] ?? [])->sum('rate') * 100;
            $gross = (float) ($shipping['discounted_price'] ?? $shipping['price'] ?? 0);

            $lines[] = [
                'remote_product_id' => null,
                'remote_variant_id' => null,
                'sku' => null,
                'name' => __('ecommerce.order.shipping', ['method' => $shipping['title'] ?? __('ecommerce.order.shipping_fallback')]),
                'quantity' => 1.0,
                'unit_price' => round($taxesIncluded ? $gross / (1 + $rate / 100) : $gross, 4),
                'tax_rate' => round($rate, 2),
            ];
        }

        $financial = (string) ($order['financial_status'] ?? 'pending');
        $fulfilled = ($order['fulfillment_status'] ?? null) === 'fulfilled';

        return [
            'remote_id' => (string) $order['id'],
            'number' => (string) ($order['name'] ?? $order['order_number'] ?? $order['id']),
            'status' => ! empty($order['cancelled_at']) ? 'cancelled' : $financial.($fulfilled ? '+fulfilled' : ''),
            'canonical_status' => match (true) {
                ! empty($order['cancelled_at']), in_array($financial, ['refunded', 'voided'], true) => 'cancelled',
                in_array($financial, ['paid', 'partially_paid', 'authorized', 'partially_refunded'], true) && $fulfilled => 'completed',
                in_array($financial, ['paid', 'partially_paid', 'authorized', 'partially_refunded'], true) => 'processing',
                default => 'pending',
            },
            'currency' => strtoupper((string) ($order['currency'] ?? 'EUR')),
            'date' => substr((string) ($order['created_at'] ?? now()->toDateString()), 0, 10),
            'customer' => [
                'remote_id' => ! empty($customer['id']) ? (string) $customer['id'] : null,
                'email' => (string) ($customer['email'] ?? $order['email'] ?? $order['contact_email'] ?? ''),
                'first_name' => (string) ($customer['first_name'] ?? $billing['first_name'] ?? ''),
                'last_name' => (string) ($customer['last_name'] ?? $billing['last_name'] ?? ''),
                'company' => (string) ($billing['company'] ?? ''),
                'phone' => (string) ($customer['phone'] ?? $billing['phone'] ?? ''),
                'address' => [
                    'street' => trim(($billing['address1'] ?? '').' '.($billing['address2'] ?? '')),
                    'postal_code' => (string) ($billing['zip'] ?? ''),
                    'city' => (string) ($billing['city'] ?? ''),
                    'state' => (string) ($billing['province_code'] ?? $billing['province'] ?? ''),
                    'country' => strtoupper((string) ($billing['country_code'] ?? '')),
                ],
            ],
            'lines' => $lines,
        ];
    }

    private function setInventoryLevel(string $inventoryItemId, float $quantity): void
    {
        $locationId = $this->setting('location_id');

        if ($locationId === null || $locationId === '') {
            throw EcommerceException::because(EcommerceException::MISSING_CONFIGURATION, ['field' => 'settings.location_id']);
        }

        $this->ensureSuccessful($this->http()->post("{$this->baseUrl()}/inventory_levels/set.json", [
            'location_id' => (int) $locationId,
            'inventory_item_id' => (int) $inventoryItemId,
            'available' => $this->stockQuantity($quantity),
        ]), 'stock update');
    }

    private function nextPageUrl(Response $response): ?string
    {
        return preg_match('/<([^>]+)>;\s*rel="next"/', (string) $response->header('Link'), $match) ? $match[1] : null;
    }
}
