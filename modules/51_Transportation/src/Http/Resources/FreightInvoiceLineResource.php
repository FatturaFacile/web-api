<?php

declare(strict_types=1);

namespace Modules\Transportation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Transportation\Models\TmsFreightInvoiceLine;

/**
 * A billed amount of a carrier invoice, linked to the transport order it pays, with the variance found by the audit.
 *
 * @mixin TmsFreightInvoiceLine
 */
final class FreightInvoiceLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'freight_invoice_id' => $this->freight_invoice_id,
            'transport_order_id' => $this->transport_order_id,
            'description' => $this->description,
            'billed_amount' => $this->billed_amount,
            'expected_amount' => $this->expected_amount,
            'variance_amount' => $this->variance_amount,
            'variance_percent' => $this->variance_percent,
            'audit_status' => $this->audit_status,
            'order_number' => $this->whenLoaded('transportOrder', fn (): ?string => $this->transportOrder?->number),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
