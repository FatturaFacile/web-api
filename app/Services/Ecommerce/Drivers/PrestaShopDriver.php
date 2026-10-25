<?php

declare(strict_types=1);

namespace App\Services\Ecommerce\Drivers;

use App\Exceptions\EcommerceException;
use App\Models\EcommerceMapping;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * PrestaShop Webservice. The API key is the HTTP Basic user name (empty password). Reads use `output_format=JSON`,
 * writes send XML as the webservice requires. Products map to products and variants to `combinations`; stock lives in
 * `stock_availables`. PrestaShop has no native webhooks: a relay or module posts `{"event": "order.created",
 * "id_order": 12}` signed with the hex HMAC-SHA256 of the body in `X-Presta-Signature`, and the order is read back.
 */
class PrestaShopDriver extends AbstractEcommerceDriver
{
    private const DEFAULT_LANGUAGE_ID = 1;

    private const DEFAULT_CATEGORY_ID = 2;

    /**
     * Remote records fetched while normalising orders (customers, addresses, countries, currencies).
     *
     * @var array<string, array<string, mixed>>
     */
    private array $cache = [];

    protected function baseUrl(): string
    {
        return $this->apiUrl().'/api';
    }

    protected function http(): PendingRequest
    {
        return Http::withBasicAuth($this->credential('api_key'), '')->timeout(self::TIMEOUT_SECONDS);
    }

    public function testConnection(): bool
    {
        try {
            return $this->http()->get($this->baseUrl(), ['output_format' => 'JSON'])->successful();
        } catch (ConnectionException|EcommerceException) {
            return false;
        }
    }

    public function pullOrders(?Carbon $since = null): array
    {
        $orders = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $query = ['display' => 'full', 'sort' => '[id_ASC]', 'limit' => ($page * self::PAGE_SIZE).','.self::PAGE_SIZE];

            if ($since !== null) {
                $query['filter[date_add]'] = '['.$since->copy()->utc()->format('Y-m-d H:i:s').',9999-12-31 23:59:59]';
                $query['date'] = 1;
            }

            $batch = $this->getJson('orders', $query)['orders'] ?? [];

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

        $productId = (int) ($mapping->extra_data['product_id'] ?? ($mapping->entity_type === 'product' ? $mapping->remote_id : $mapping->extra_data['parent_remote_id'] ?? 0));
        $attributeId = (int) ($mapping->extra_data['attribute_id'] ?? ($mapping->entity_type === 'product_variant' ? $mapping->remote_id : 0));

        $this->putStock($this->stockAvailableId($mapping, $productId, $attributeId), $productId, $attributeId, $this->stockQuantity($quantity));

        return true;
    }

    public function pushProducts(array $products): array
    {
        $results = [];
        $categoryId = (int) $this->setting('category_id', self::DEFAULT_CATEGORY_ID);

        foreach ($products as $product) {
            $created = $this->post('products', $this->xml('product', [
                'name' => ['__lang' => $product['name']],
                'description' => ['__lang' => (string) ($product['description'] ?? '')],
                'reference' => $product['sku'],
                'price' => number_format((float) $product['price'], 6, '.', ''),
                'active' => 1,
                'state' => 1,
                'minimal_quantity' => 1,
                'id_category_default' => $categoryId,
                '__raw' => "<associations><categories><category><id>{$categoryId}</id></category></categories></associations>",
            ]), 'product');

            $productId = (int) $created;
            $result = ['local_id' => $product['local_id'], 'remote_id' => (string) $productId, 'product_id' => (string) $productId, 'attribute_id' => '0', 'variants' => []];

            if ($product['type'] !== 'variable') {
                $result['stock_available_id'] = $this->initialStock($productId, 0, (float) $product['stock']);
                $results[] = $result;

                continue;
            }

            foreach ($product['variants'] as $variant) {
                $combinationId = (int) $this->post('combinations', $this->xml('combination', [
                    'id_product' => $productId,
                    'reference' => $variant['sku'],
                    'price' => number_format((float) $variant['price'] - (float) $product['price'], 6, '.', ''), // impact on the base price
                    'minimal_quantity' => 1,
                ]), 'combination');

                $result['variants'][] = [
                    'local_id' => $variant['local_id'],
                    'remote_id' => (string) $combinationId,
                    'parent_remote_id' => (string) $productId,
                    'product_id' => (string) $productId,
                    'attribute_id' => (string) $combinationId,
                    'stock_available_id' => $this->initialStock($productId, $combinationId, (float) $variant['stock']),
                ];
            }

            $results[] = $result;
        }

        return $results;
    }

    public function handleWebhook(Request $request): array
    {
        $orderId = (int) $request->json('id_order');

        if (! str_starts_with((string) $request->json('event'), 'order.') || $orderId <= 0) {
            return [];
        }

        $order = $this->getJson("orders/{$orderId}")['order'] ?? null;

        if (! is_array($order)) {
            throw EcommerceException::because(EcommerceException::INVALID_RESPONSE, ['operation' => 'order']);
        }

        return [['type' => 'order', 'order' => $this->normalizeOrder($order)]];
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        return $this->signatureMatches($this->bodyHmac($request, base64: false), $request->header('X-Presta-Signature'));
    }

    protected function normalizeOrder(array $order): array
    {
        $lines = [];

        foreach ($order['associations']['order_rows'] ?? [] as $row) {
            $net = (float) ($row['unit_price_tax_excl'] ?? 0);
            $gross = (float) ($row['unit_price_tax_incl'] ?? 0);

            $lines[] = [
                'remote_product_id' => isset($row['product_id']) ? (string) $row['product_id'] : null,
                'remote_variant_id' => (int) ($row['product_attribute_id'] ?? 0) > 0 ? (string) $row['product_attribute_id'] : null,
                'sku' => ($row['product_reference'] ?? '') !== '' ? (string) $row['product_reference'] : null,
                'name' => (string) ($row['product_name'] ?? ''),
                'quantity' => (float) ($row['product_quantity'] ?? 0),
                'unit_price' => $net,
                'tax_rate' => $net > 0 ? round(($gross / $net - 1) * 100, 2) : 0.0,
            ];
        }

        $shippingNet = (float) ($order['total_shipping_tax_excl'] ?? 0);

        if ($shippingNet > 0) {
            $lines[] = [
                'remote_product_id' => null,
                'remote_variant_id' => null,
                'sku' => null,
                'name' => __('ecommerce.order.shipping', ['method' => __('ecommerce.order.shipping_fallback')]),
                'quantity' => 1.0,
                'unit_price' => $shippingNet,
                'tax_rate' => round(((float) ($order['total_shipping_tax_incl'] ?? $shippingNet) / $shippingNet - 1) * 100, 2),
            ];
        }

        $state = (string) ($order['current_state'] ?? '1');
        $customer = $this->remote('customers', (int) ($order['id_customer'] ?? 0), 'customer');
        $address = $this->remote('addresses', (int) ($order['id_address_invoice'] ?? 0), 'address');
        $country = $this->remote('countries', (int) ($address['id_country'] ?? 0), 'country');
        $currency = $this->remote('currencies', (int) ($order['id_currency'] ?? 0), 'currency');

        return [
            'remote_id' => (string) $order['id'],
            'number' => (string) ($order['reference'] ?? $order['id']),
            'status' => $state,
            'canonical_status' => match (true) {
                in_array($state, ['2', '3', '9', '11', '12'], true) => 'processing',
                in_array($state, ['4', '5'], true) => 'completed',
                in_array($state, ['6', '7', '8'], true) => 'cancelled',
                default => 'pending', // awaiting payment states
            },
            'currency' => strtoupper((string) ($currency['iso_code'] ?? $this->setting('currency', 'EUR'))),
            'date' => substr((string) ($order['date_add'] ?? now()->toDateString()), 0, 10),
            'customer' => [
                'remote_id' => isset($customer['id']) ? (string) $customer['id'] : null,
                'email' => strtolower(trim((string) ($customer['email'] ?? ''))),
                'first_name' => (string) ($customer['firstname'] ?? $address['firstname'] ?? ''),
                'last_name' => (string) ($customer['lastname'] ?? $address['lastname'] ?? ''),
                'company' => (string) ($address['company'] ?? $customer['company'] ?? ''),
                'phone' => (string) ($address['phone'] ?? $address['phone_mobile'] ?? ''),
                'address' => [
                    'street' => trim(($address['address1'] ?? '').' '.($address['address2'] ?? '')),
                    'postal_code' => (string) ($address['postcode'] ?? ''),
                    'city' => (string) ($address['city'] ?? ''),
                    'state' => '',
                    'country' => strtoupper((string) ($country['iso_code'] ?? '')),
                ],
            ],
            'lines' => $lines,
        ];
    }

    /**
     * GET a webservice resource as JSON.
     *
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    private function getJson(string $path, array $query = []): array
    {
        return $this->jsonBody($this->ensureSuccessful($this->http()->get("{$this->baseUrl()}/{$path}", $query + ['output_format' => 'JSON']), $path), $path);
    }

    /**
     * One related record (customer, address, ...) read once per driver instance; an id of 0 means "none".
     *
     * @return array<string, mixed>
     */
    private function remote(string $resource, int $id, string $key): array
    {
        if ($id <= 0) {
            return [];
        }

        return $this->cache["{$resource}.{$id}"] ??= (array) ($this->getJson("{$resource}/{$id}")[$key] ?? []);
    }

    /**
     * Create a resource and return its new id from the XML response.
     */
    private function post(string $resource, string $xml, string $operation): string
    {
        $response = $this->ensureSuccessful($this->http()->withBody($xml, 'application/xml')->post("{$this->baseUrl()}/{$resource}"), "{$operation} creation");

        return $this->createdId($response, $operation);
    }

    private function createdId(Response $response, string $element): string
    {
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($response->body());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $id = $document !== false ? trim((string) ($document->{$element}->id ?? '')) : '';

        if ($id === '') {
            throw EcommerceException::because(EcommerceException::INVALID_RESPONSE, ['operation' => "{$element} creation"]);
        }

        return $id;
    }

    /**
     * Find the stock record of a product or combination (and remember it on the mapping).
     */
    private function stockAvailableId(EcommerceMapping $mapping, int $productId, int $attributeId): int
    {
        $known = (int) ($mapping->extra_data['stock_available_id'] ?? 0);

        if ($known > 0) {
            return $known;
        }

        $id = $this->lookupStockAvailableId($productId, $attributeId);
        $mapping->update(['extra_data' => array_merge($mapping->extra_data ?? [], ['stock_available_id' => (string) $id, 'product_id' => (string) $productId, 'attribute_id' => (string) $attributeId])]);

        return $id;
    }

    private function lookupStockAvailableId(int $productId, int $attributeId): int
    {
        $found = $this->getJson('stock_availables', ['display' => '[id]', 'filter[id_product]' => $productId, 'filter[id_product_attribute]' => $attributeId])['stock_availables'][0]['id'] ?? null;

        if ($found === null) {
            throw EcommerceException::because(EcommerceException::INVALID_RESPONSE, ['operation' => 'stock_availables']);
        }

        return (int) $found;
    }

    private function initialStock(int $productId, int $attributeId, float $quantity): string
    {
        $id = $this->lookupStockAvailableId($productId, $attributeId);
        $this->putStock($id, $productId, $attributeId, $this->stockQuantity($quantity));

        return (string) $id;
    }

    private function putStock(int $stockAvailableId, int $productId, int $attributeId, int $quantity): void
    {
        $xml = $this->xml('stock_available', [
            'id' => $stockAvailableId,
            'id_product' => $productId,
            'id_product_attribute' => $attributeId,
            'quantity' => $quantity,
            'depends_on_stock' => 0,
            'out_of_stock' => 2,
        ]);

        $this->ensureSuccessful($this->http()->withBody($xml, 'application/xml')->put("{$this->baseUrl()}/stock_availables/{$stockAvailableId}"), 'stock update');
    }

    /**
     * Webservice XML envelope. Plain values are escaped; `['__lang' => text]` becomes a multi-language field and
     * `__raw` is inserted unescaped (only used with trusted, locally built fragments).
     *
     * @param  array<string, mixed>  $fields
     */
    private function xml(string $resource, array $fields): string
    {
        $language = (int) $this->setting('language_id', self::DEFAULT_LANGUAGE_ID);
        $escape = fn (mixed $value): string => htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $inner = '';

        foreach ($fields as $tag => $value) {
            $inner .= match (true) {
                $tag === '__raw' => (string) $value,
                is_array($value) => "<{$tag}><language id=\"{$language}\">{$escape($value['__lang'])}</language></{$tag}>",
                default => "<{$tag}>{$escape($value)}</{$tag}>",
            };
        }

        return '<?xml version="1.0" encoding="UTF-8"?><prestashop xmlns:xlink="http://www.w3.org/1999/xlink"><'.$resource.'>'.$inner.'</'.$resource.'></prestashop>';
    }
}
