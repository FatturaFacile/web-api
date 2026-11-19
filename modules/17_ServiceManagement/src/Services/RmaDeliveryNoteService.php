<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services;

use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Logistics\Enums\DeliveryNoteType;
use Modules\Logistics\Enums\TransportReason;
use Modules\Logistics\Services\DeliveryNoteService;
use Modules\ServiceManagement\Enums\DdtCausale;
use Modules\ServiceManagement\Models\DepotRepairRmaOrder;

/**
 * Issues the real transport document (DDT) of an RMA shipment through the Logistics delivery note service. The goods
 * of an RMA never change owner, so the document carries the repair reason (not a sale) and, where the RMA flags it,
 * the Art. 15 DPR 633/72 note (transport of the customer's own goods, outside the VAT base).
 */
final readonly class RmaDeliveryNoteService
{
    public function __construct(private DeliveryNoteService $deliveryNotes) {}

    /**
     * Idempotent: an RMA gets one return DDT.
     */
    public function issueReturnNote(DepotRepairRmaOrder $rma): SalesDeliveryNote
    {
        if ($rma->return_delivery_note_id !== null) {
            $existing = SalesDeliveryNote::query()->with('items')->find($rma->return_delivery_note_id);
            if ($existing !== null) {
                return $existing;
            }
        }

        $causale = $rma->ddt_causale_return ?? DdtCausale::ResoRiparato->value;
        $serial = (string) ($rma->passport->serial_number ?? $rma->rma_number);
        $notes = trim(sprintf('RMA %s - causale %s', $rma->rma_number, $causale)
            .($rma->vat_exempt_art15_dpr633 ? ' - Escluso dalla base imponibile IVA ex art. 15 DPR 633/72' : ''));

        $note = $this->deliveryNotes->create([
            'type' => DeliveryNoteType::RepairReturn->value,
            'contact_id' => $rma->customer_id,
            'transport_reason_code' => TransportReason::Repair->value,
            'show_prices' => false,
            'notes' => $notes,
        ], [[
            'product_id' => $rma->passport->product_id ?? null,
            'description' => 'Apparecchiatura riparata s/n '.$serial,
            'quantity' => 1,
            'unit_price' => 0,
            'tax_rate' => 0,
            'serial_number' => $serial,
        ]]);

        $note = $this->deliveryNotes->issue($note);

        $rma->return_delivery_note_id = (string) $note->id;

        return $note;
    }
}
