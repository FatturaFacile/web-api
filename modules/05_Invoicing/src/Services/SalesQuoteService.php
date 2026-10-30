<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Illuminate\Support\Str;
use Modules\Invoicing\Contracts\PricingAndDiscountServiceInterface;
use Modules\Invoicing\Contracts\SalesOrderServiceInterface;
use Modules\Invoicing\Contracts\SalesQuoteServiceInterface;
use Modules\Invoicing\Events\SalesQuoteAcceptedEvent;
use Modules\Invoicing\Events\SalesQuoteApprovedEvent;
use Modules\Invoicing\Events\SalesQuoteCreatedEvent;
use Modules\Invoicing\Events\SalesQuoteSubmittedEvent;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesQuote;
use Modules\Invoicing\Models\SalesQuoteItem;

final class SalesQuoteService implements SalesQuoteServiceInterface
{
    public function __construct(
        private readonly PricingAndDiscountServiceInterface $pricingService,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    public function createQuote(array $data, array $items): SalesQuote
    {
        $tenantId = (string) $data['tenant_id'];
        $quoteId = (string) Str::uuid7();

        // Calculate item pricing & totals
        $calculatedItems = [];
        foreach ($items as $item) {
            $productId = isset($item['product_id']) ? (string) $item['product_id'] : null;
            $pricing = $productId !== null ? $this->pricingService->resolvePrice(
                productId: $productId,
                quantity: (float) ($item['quantity'] ?? 1.0),
                priceListId: $data['price_list_id'] ?? null,
                discountPolicyId: $data['discount_policy_id'] ?? null
            ) : ['unit_price' => (float) ($item['unit_price'] ?? 0.0), 'discount_percentage' => (float) ($item['discount_percentage'] ?? 0.0)];

            $unitPrice = isset($item['unit_price']) ? (float) $item['unit_price'] : $pricing['unit_price'];
            $discountPct = isset($item['discount_percentage']) ? (float) $item['discount_percentage'] : $pricing['discount_percentage'];
            $taxRate = (float) ($item['tax_rate'] ?? 22.0);
            $qty = (float) ($item['quantity'] ?? 1.0);

            $lineNet = round(($qty * $unitPrice) * (1 - ($discountPct / 100.0)), 2);

            $calculatedItems[] = array_merge($item, [
                'unit_price' => $unitPrice,
                'discount_percentage' => $discountPct,
                'tax_rate' => $taxRate,
                'line_total' => $lineNet,
            ]);
        }

        $totals = $this->pricingService->calculateOrderTotals($calculatedItems, $data['discount_policy_id'] ?? null);

        $quoteNumber = $data['quote_number'] ?? 'Q-'.date('Y').'-'.strtoupper(Str::random(6));

        $quote = SalesQuote::create([
            'id' => $quoteId,
            'tenant_id' => $tenantId,
            'contact_id' => (string) $data['contact_id'],
            'quote_number' => $quoteNumber,
            'date' => $data['date'] ?? now()->toDateString(),
            'valid_until' => $data['valid_until'] ?? now()->addDays(30)->toDateString(),
            'currency' => $data['currency'] ?? 'EUR',
            'subtotal' => $totals['subtotal'],
            'discount_amount' => $totals['discount_total'],
            'total_net' => $totals['net_total'],
            'total_tax' => $totals['tax_total'],
            'total_gross' => $totals['gross_total'],
            'status' => $data['status'] ?? 'draft',
            'notes' => $data['notes'] ?? null,
            'discount_policy_id' => $data['discount_policy_id'] ?? null,
            'price_list_id' => $data['price_list_id'] ?? null,
            'payment_terms' => $data['payment_terms'] ?? null,
            'shipping_terms' => $data['shipping_terms'] ?? null,
            'revision_number' => 1,
            'created_by' => ! empty($data['created_by']) && Str::isUuid((string) $data['created_by']) ? (string) $data['created_by'] : null,
        ]);

        $actualQuoteId = (string) $quote->id;

        foreach ($calculatedItems as $item) {
            SalesQuoteItem::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'quote_id' => $actualQuoteId,
                'product_id' => $item['product_id'] ?? null,
                'description' => $item['description'] ?? 'Product item',
                'quantity' => (float) ($item['quantity'] ?? 1.0),
                'unit_price' => (float) ($item['unit_price'] ?? 0.0),
                'tax_rate' => (float) ($item['tax_rate'] ?? 0.0),
                'discount_percentage' => (float) ($item['discount_percentage'] ?? 0.0),
                'line_total' => (float) ($item['line_total'] ?? 0.0),
                'cost_price' => (float) ($item['cost_price'] ?? 0.0),
                'margin_percentage' => (float) ($item['margin_percentage'] ?? 0.0),
                'uom' => $item['uom'] ?? 'EA',
                'notes' => $item['notes'] ?? null,
            ]);
        }

        $this->dispatchDomainEvent(new SalesQuoteCreatedEvent(
            quoteId: $actualQuoteId,
            quoteNumber: $quoteNumber,
            customerId: (string) $quote->contact_id,
            totalGross: (float) $quote->total_gross,
            currency: (string) $quote->currency,
            tenantId: $tenantId,
            companyId: $tenantId
        ));

        return $quote->load('items');
    }

    public function submitForApproval(SalesQuote $quote, string $userId): SalesQuote
    {
        $quote->update([
            'status' => 'pending_approval',
        ]);

        $this->dispatchDomainEvent(new SalesQuoteSubmittedEvent(
            quoteId: (string) $quote->id,
            quoteNumber: (string) $quote->quote_number,
            customerId: (string) $quote->contact_id,
            totalGross: (float) $quote->total_gross,
            currency: (string) $quote->currency,
            userId: $userId,
            tenantId: (string) $quote->tenant_id,
            companyId: (string) $quote->tenant_id
        ));

        return $quote;
    }

    public function approveQuote(SalesQuote $quote, string $userId): SalesQuote
    {
        $quote->update([
            'status' => 'approved',
            'approved_by' => Str::isUuid($userId) ? $userId : (string) Str::uuid7(),
            'approved_at' => now(),
        ]);

        $this->dispatchDomainEvent(new SalesQuoteApprovedEvent(
            quoteId: (string) $quote->id,
            approvedBy: $userId,
            tenantId: (string) $quote->tenant_id,
            companyId: (string) $quote->tenant_id
        ));

        return $quote;
    }

    public function acceptQuote(SalesQuote $quote): SalesQuote
    {
        $quote->update([
            'status' => 'accepted',
            'signed_at' => now(),
        ]);

        $this->dispatchDomainEvent(new SalesQuoteAcceptedEvent(
            quoteId: (string) $quote->id,
            salesOrderId: null,
            tenantId: (string) $quote->tenant_id,
            companyId: (string) $quote->tenant_id,
            quoteNumber: (string) ($quote->quote_number ?? $quote->number ?? ''),
            customerId: (string) ($quote->contact_id ?? $quote->customer_id ?? ''),
            totalAmount: (float) ($quote->total_amount ?? $quote->total_gross ?? 0.0),
            salesQuoteId: (string) $quote->id
        ));

        return $quote;
    }

    private function dispatchDomainEvent(DomainEventInterface $event): void
    {
        $this->eventDispatcher->publish($event);
        $this->eventDispatcher->dispatch($event);
    }

    public function convertToOrder(SalesQuote $quote, string $userId): SalesOrder
    {
        $quote->load('items');
        $tenantId = (string) $quote->tenant_id;

        $orderService = app(SalesOrderServiceInterface::class);

        $orderData = [
            'tenant_id' => $tenantId,
            'contact_id' => (string) $quote->contact_id,
            'quote_id' => (string) $quote->id,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency' => $quote->currency ?? 'EUR',
            'price_list_id' => $quote->price_list_id,
            'discount_policy_id' => $quote->discount_policy_id,
            'payment_terms' => $quote->payment_terms,
            'shipping_method' => $quote->shipping_terms,
            'notes' => 'Converted from Quote '.$quote->quote_number,
            'created_by' => $userId,
        ];

        $orderItems = [];
        foreach ($quote->items as $item) {
            $orderItems[] = [
                'product_id' => (string) $item->product_id,
                'description' => (string) $item->description,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'tax_rate' => (float) $item->tax_rate,
                'discount_percentage' => (float) $item->discount_percentage,
                'cost_price' => (float) $item->cost_price,
                'uom' => (string) $item->uom,
                'notes' => $item->notes,
            ];
        }

        $order = $orderService->createOrder($orderData, $orderItems);

        $quote->update([
            'converted_to_order_id' => (string) $order->id,
            'status' => 'converted',
        ]);

        return $order;
    }
}
