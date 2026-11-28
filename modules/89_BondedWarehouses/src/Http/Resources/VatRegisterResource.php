<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\BondedWarehouses\Models\VatDepotRegisterEntry;

/**
 * @mixin VatDepotRegisterEntry
 */
final class VatRegisterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'authorization_id' => $this->authorization_id,
            'register_progressive_number' => $this->register_progressive_number,
            'movement_type' => $this->movement_type->value,
            'movement_date' => $this->movement_date->format('Y-m-d'),
            'product_id' => $this->product_id,
            'lot_reference' => $this->lot_reference,
            'quantity' => $this->quantity,
            'base_goods_value' => $this->base_goods_value,
            'storage_and_processing_services_value' => $this->storage_and_processing_services_value,
            'total_taxable_extraction_base' => $this->total_taxable_extraction_base,
            'vat_rate_percent' => $this->vat_rate_percent,
            'vat_amount' => $this->vat_amount,
            'self_invoice_id' => $this->self_invoice_id,
            'sdi_document_type' => $this->sdi_document_type->value,
            'f24_payment_reference' => $this->f24_payment_reference,
            'f24_payment_date' => $this->f24_payment_date?->format('Y-m-d'),
            'notes' => $this->notes,
        ];
    }
}
