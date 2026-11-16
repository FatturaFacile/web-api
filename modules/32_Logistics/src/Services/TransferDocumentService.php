<?php

declare(strict_types=1);

namespace Modules\Logistics\Services;

use Modules\Inventory\Models\TransferOrder;
use Modules\Inventory\Models\Warehouse;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Logistics\Enums\TransferPurpose;
use Modules\Logistics\Events\InterCompanyTransferShipped;
use Modules\Logistics\Events\StockTransferReceived;
use Modules\Logistics\Events\StockTransferShipped;

/**
 * The fiscal paperwork of a transfer order: the transport document (DDT) issued when it ships and delivered when the
 * last receipt arrives, and the inter-company invoice draft when it crosses two legal entities.
 */
final class TransferDocumentService
{
    public function __construct(
        private readonly DeliveryNoteService $deliveryNotes,
        private readonly InterCompanyInvoicingService $interCompanyInvoicing,
    ) {}

    /**
     * @param  array<string, mixed>  $deliveryData  carrier and package data given at dispatch
     */
    public function onShipped(TransferOrder $order, array $deliveryData): void
    {
        $purpose = TransferPurpose::tryFrom((string) $order->purpose) ?? TransferPurpose::InterWarehouse;
        $source = Warehouse::query()->findOrFail($order->source_warehouse_id);
        $destination = Warehouse::query()->findOrFail($order->destination_warehouse_id);

        $items = [];
        foreach ($order->lines()->with('item.product')->get() as $line) {
            $product = $line->item?->product;
            $items[] = [
                'product_id' => $product?->id !== null ? (string) $product->id : null,
                'description' => (string) ($line->item?->name ?? $product?->name),
                'quantity' => (float) $line->quantity_shipped,
                // Fiscal value of the goods travelling (at cost): required on consignment / subcontracting DDTs.
                'unit_price' => (float) ($product?->cost_price ?? $line->item?->standard_cost ?? 0),
                'tax_rate' => 0.0,
                'uom' => $product?->unit_measure,
            ];
        }

        $header = array_merge($deliveryData, [
            'type' => $purpose->deliveryNoteType()->value,
            'origin_warehouse_id' => (string) $source->id,
            'destination_warehouse_id' => (string) $destination->id,
            'stock_transfer_order_id' => (string) $order->id,
            'show_prices' => $purpose->showsPricesOnDdt(),
        ]);
        if ($purpose === TransferPurpose::CustomerConsignment && $destination->linked_partner_id !== null) {
            $header['contact_id'] = (string) $destination->linked_partner_id;
        }
        if ($purpose === TransferPurpose::ConsignmentReturn && $source->linked_partner_id !== null) {
            $header['contact_id'] = (string) $source->linked_partner_id;
        }
        if ($purpose === TransferPurpose::Subcontracting && $destination->linked_partner_id !== null) {
            $header['supplier_id'] = (string) $destination->linked_partner_id;
        }
        if ($purpose === TransferPurpose::SubcontractingReturn && $source->linked_partner_id !== null) {
            $header['supplier_id'] = (string) $source->linked_partner_id;
        }

        $note = $this->deliveryNotes->create($header, $items);
        $note = $this->deliveryNotes->startTransit($this->deliveryNotes->issue($note));

        $order->update([
            'delivery_note_id' => $note->id,
            'inter_company_invoice_id' => $order->is_inter_company
                ? $this->interCompanyInvoicing->draftInvoice($order, $destination, (string) $note->ddt_number)
                : null,
        ]);

        event(new StockTransferShipped((string) $order->id, (string) $note->id, (string) $order->tenant_id));
        if ($order->is_inter_company) {
            event(new InterCompanyTransferShipped(
                (string) $order->id,
                (string) $note->id,
                (string) $source->company_entity_id,
                (string) $destination->company_entity_id,
                (string) $order->tenant_id,
            ));
        }
    }

    public function onReceived(TransferOrder $order, bool $hasDiscrepancies): void
    {
        if ($order->delivery_note_id !== null) {
            $note = SalesDeliveryNote::query()->find($order->delivery_note_id);
            if ($note !== null && in_array($note->status, ['issued', 'in_transit'], true)) {
                $this->deliveryNotes->markDelivered($note);
            }
        }

        event(new StockTransferReceived((string) $order->id, (string) $order->tenant_id, $hasDiscrepancies));
    }
}
