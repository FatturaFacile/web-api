<?php

declare(strict_types=1);

namespace Modules\Logistics\Services;

use App\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Logistics\Models\StockTransferOrder;

/**
 * Domain 01 bridge: a transfer between two legal entities of the same group is a sale between those entities,
 * so shipping it drafts the inter-company invoice (goods valued at cost) addressed to the receiving entity's partner.
 */
final class InterCompanyInvoicingService
{
    public function __construct(private readonly InvoicingServiceInterface $invoicing) {}

    /**
     * @return string|null id of the drafted invoice, null when the receiving warehouse has no partner to bill
     */
    public function draftInvoice(StockTransferOrder $order, Warehouse $destination, string $deliveryNoteNumber): ?string
    {
        if ($destination->linked_partner_id === null) {
            return null;
        }

        $items = [];
        foreach ($order->lines as $line) {
            $product = Product::query()->findOrFail($line->product_id);
            $items[] = [
                'product_id' => (string) $product->id,
                'description' => sprintf('%s (trasferimento infragruppo %s - DDT %s)', $line->description ?? $product->name, $order->transfer_number, $deliveryNoteNumber),
                'quantity' => (float) $line->quantity,
                'unit_price' => (float) $product->cost_price,
                'tax_rate' => (float) $product->tax_rate,
            ];
        }

        $invoice = $this->invoicing->createInvoice([
            'tenant_id' => (string) $order->tenant_id,
            'contact_id' => (string) $destination->linked_partner_id,
            'notes' => sprintf('Fattura infragruppo - trasferimento %s', $order->transfer_number),
            'created_by' => auth()->id(),
        ], $items);

        return (string) $invoice->id;
    }
}
