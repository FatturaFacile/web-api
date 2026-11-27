<?php

declare(strict_types=1);

namespace Modules\DownPayments\Services;

use App\Models\Contact;
use App\Models\Tenant;
use App\Services\Sales\DocumentTotalsCalculator;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Modules\DownPayments\Domain\Enums\SourceType;
use Modules\DownPayments\Exceptions\DownPaymentException;
use Modules\DownPayments\Support\ChainSource;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesQuote;

/**
 * Reads the document a chain is built from (sales order, quote or contract) and states its net per VAT rate.
 */
final class ChainSourceResolver
{
    /**
     * @param  array<string, mixed>  $payload  for a contract: customer_id, currency, number and lines (description, vat_rate, net)
     *
     * @throws DownPaymentException when the source does not exist in the company or has nothing to bill
     */
    public function resolve(string $tenantId, SourceType $type, string $sourceId, array $payload = []): ChainSource
    {
        return match ($type) {
            SourceType::SalesOrder => $this->order($tenantId, $sourceId),
            SourceType::Quote => $this->quote($tenantId, $sourceId),
            SourceType::Contract => $this->contract($tenantId, $sourceId, $payload),
        };
    }

    private function order(string $tenantId, string $id): ChainSource
    {
        $order = SalesOrder::withoutGlobalScope(TenantScope::class)->with('items')->where('tenant_id', $tenantId)->whereKey($id)->first()
            ?? throw new DownPaymentException('source_not_found', 404);

        return $this->build(SourceType::SalesOrder, $id, (string) $order->order_number, (string) $order->contact_id, (string) ($order->currency ?? 'EUR'), $tenantId, (string) $order->id, $this->basesOf($order->items->all()));
    }

    private function quote(string $tenantId, string $id): ChainSource
    {
        $quote = SalesQuote::withoutGlobalScope(TenantScope::class)->with('items')->where('tenant_id', $tenantId)->whereKey($id)->first()
            ?? throw new DownPaymentException('source_not_found', 404);

        return $this->build(SourceType::Quote, $id, (string) $quote->quote_number, (string) $quote->contact_id, (string) ($quote->currency ?? 'EUR'), $tenantId, $quote->converted_to_order_id !== null ? (string) $quote->converted_to_order_id : null, $this->basesOf($quote->items->all()));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function contract(string $tenantId, string $id, array $payload): ChainSource
    {
        $lines = [];
        $bases = [];

        foreach ((array) ($payload['lines'] ?? []) as $line) {
            $rate = Decimal::round2((string) ($line['vat_rate'] ?? '0'));
            $net = Decimal::round2((string) ($line['net'] ?? '0'));
            $lines[] = ['description' => (string) ($line['description'] ?? ''), 'vat_rate' => $rate, 'net' => $net];
            $bases[$rate] = Decimal::add($bases[$rate] ?? '0', $net);
        }

        if (empty($payload['customer_id'])) {
            throw new DownPaymentException('contract_requires_customer');
        }

        return $this->build(SourceType::Contract, $id, isset($payload['number']) ? (string) $payload['number'] : null, (string) $payload['customer_id'], (string) ($payload['currency'] ?? 'EUR'), $tenantId, null, $bases, $lines);
    }

    /**
     * @param  array<string, string>  $bases
     * @param  list<array{description: string, vat_rate: string, net: string}>|null  $lines
     */
    private function build(SourceType $type, string $id, ?string $number, string $customerId, string $currency, string $tenantId, ?string $orderId, array $bases, ?array $lines = null): ChainSource
    {
        $bases = array_filter(array_map(static fn (string $net): string => Decimal::round2($net), $bases), static fn (string $net): bool => Decimal::isPositive($net));

        if ($bases === []) {
            throw new DownPaymentException('source_has_nothing_to_bill');
        }

        $contact = Contact::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($customerId)->first()
            ?? throw new DownPaymentException('customer_not_found');
        $name = trim((string) ($contact->company_name ?: trim($contact->first_name.' '.$contact->last_name)));
        ksort($bases, SORT_NUMERIC);

        return new ChainSource($type->value, $id, $number, $customerId, $name !== '' ? $name : null, strtoupper($currency), $this->jurisdiction($tenantId), $orderId, $bases, $lines);
    }

    /**
     * @param  array<int, object>  $items
     * @return array<string, string>
     */
    private function basesOf(array $items): array
    {
        $bases = [];

        foreach ($items as $item) {
            $rate = Decimal::round2(DocumentTotalsCalculator::decimal($item->tax_rate ?? '0'));
            $bases[$rate] = Decimal::add($bases[$rate] ?? '0', DocumentTotalsCalculator::decimal($item->line_total));
        }

        return $bases;
    }

    /** Swiss companies bill with MWST and the Swiss deposit rules, every other company with IVA. */
    private function jurisdiction(string $tenantId): string
    {
        return strtoupper((string) Tenant::query()->whereKey($tenantId)->value('country')) === 'CH' ? 'CH' : 'IT';
    }
}
