<?php

declare(strict_types=1);

namespace Modules\Marketplace\Mappers;

use Modules\Marketplace\Exceptions\RecordMappingException;
use Modules\Marketplace\Support\MappedRecord;

/**
 * Maps the orders, products and customers of the e-commerce connectors (Shopify, WooCommerce). Orders carry the customer
 * reference and the lines; amounts are decimal strings, dates ISO 8601 UTC.
 */
final class EcommerceRecordMapper extends AbstractRecordMapper
{
    protected function handled(): array
    {
        return [
            'shopify' => ['orders', 'products', 'customers'],
            'woocommerce' => ['orders', 'products', 'customers'],
        ];
    }

    public function map(string $connectorKey, string $resource, array $record): MappedRecord
    {
        $id = $this->requiredId($record);

        return match ($resource) {
            'orders' => new MappedRecord(MappedRecord::Order, $id, $this->order($connectorKey, $record)),
            'products' => new MappedRecord(MappedRecord::Product, $id, $this->product($connectorKey, $record)),
            'customers' => new MappedRecord(MappedRecord::Customer, $id, $this->customer($record)),
            default => throw new RecordMappingException(__('marketplace_errors.sync.unknown_resource', ['resource' => $resource])),
        };
    }

    public function cursorOf(string $connectorKey, string $resource, array $record): ?string
    {
        if ($connectorKey === 'woocommerce') {
            return $resource === 'orders' ? $this->isoDate($record['date_created_gmt'] ?? null) : null;
        }

        $id = $record['id'] ?? null;

        return is_int($id) || is_string($id) ? (string) $id : null;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function order(string $connectorKey, array $record): array
    {
        $shopify = $connectorKey === 'shopify';
        $customer = $record['customer'] ?? [];
        $billing = $record['billing'] ?? [];
        $lines = $record['line_items'] ?? [];

        if (! is_array($lines)) {
            throw new RecordMappingException(__('marketplace_errors.mapping.invalid_lines'));
        }

        return [
            'number' => $this->optionalString($record, $shopify ? 'name' : 'number'),
            'status' => $this->optionalString($record, $shopify ? 'financial_status' : 'status'),
            'currency' => $this->currency($record['currency'] ?? null),
            'total' => $this->decimal($record[$shopify ? 'total_price' : 'total'] ?? null, 'total'),
            'ordered_at' => $this->isoDate($record[$shopify ? 'created_at' : 'date_created_gmt'] ?? null),
            'customer' => [
                'external_id' => $this->optionalString(is_array($customer) ? $customer : [], 'id') ?? $this->optionalString($record, 'customer_id'),
                'email' => $this->optionalString($record, 'email') ?? $this->optionalString(is_array($billing) ? $billing : [], 'email') ?? $this->optionalString(is_array($customer) ? $customer : [], 'email'),
                'name' => $this->fullName($shopify ? (is_array($customer) ? $customer : []) : (is_array($billing) ? $billing : [])),
            ],
            'lines' => array_values(array_map(fn (mixed $line): array => $this->line(is_array($line) ? $line : [], $shopify), $lines)),
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function line(array $line, bool $shopify): array
    {
        $quantity = $line['quantity'] ?? null;

        if (! is_int($quantity) && ! (is_string($quantity) && ctype_digit($quantity))) {
            throw new RecordMappingException(__('marketplace_errors.mapping.invalid_lines'));
        }

        return [
            'sku' => $this->optionalString($line, 'sku') ?: null,
            'name' => $this->optionalString($line, $shopify ? 'title' : 'name'),
            'quantity' => (int) $quantity,
            'unit_price' => $this->decimal($line['price'] ?? null, 'price'),
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function product(string $connectorKey, array $record): array
    {
        $name = $this->optionalString($record, $connectorKey === 'shopify' ? 'title' : 'name');

        if ($name === null || $name === '') {
            throw new RecordMappingException(__('marketplace_errors.mapping.missing_field', ['field' => 'name']));
        }

        $shopify = $connectorKey === 'shopify';
        $price = $shopify ? data_get($record, 'variants.0.price') : ($record['price'] ?? null);

        return [
            'name' => $name,
            'sku' => ($this->optionalString($record, $shopify ? 'variants.0.sku' : 'sku')) ?: null,
            'price' => $price === null || $price === '' ? null : $this->decimal($price, 'price'),
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function customer(array $record): array
    {
        $email = $this->optionalString($record, 'email');

        if ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RecordMappingException(__('marketplace_errors.mapping.missing_field', ['field' => 'email']));
        }

        return ['email' => strtolower($email), 'name' => $this->fullName($record)];
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function fullName(array $source): ?string
    {
        $name = trim(($this->optionalString($source, 'first_name') ?? '').' '.($this->optionalString($source, 'last_name') ?? ''));

        return $name === '' ? null : $name;
    }
}
