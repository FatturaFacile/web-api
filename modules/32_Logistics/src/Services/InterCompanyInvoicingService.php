<?php

declare(strict_types=1);

namespace Modules\Logistics\Services;

use Modules\Inventory\Models\TransferOrder;
use Modules\Inventory\Models\Warehouse;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;

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
    public function draftInvoice(TransferOrder $order, Warehouse $destination, string $deliveryNoteNumber): ?string
    {
        if ($destination->linked_partner_id === null) {
            return null;
        }

        $items = [];
        foreach ($order->lines()->with('item.product')->get() as $line) {
            $product = $line->item?->product;
            if ($product === null) {
                continue;
            }
            $items[] = [
                'product_id' => (string) $product->id,
                'description' => sprintf('%s (trasferimento infragruppo %s - DDT %s)', $product->name, $order->transfer_number, $deliveryNoteNumber),
                'quantity' => (float) $line->quantity_shipped,
                'unit_price' => (float) $product->cost_price,
                'tax_rate' => (float) $product->tax_rate,
            ];
        }

        if ($items === []) {
            return null;
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
