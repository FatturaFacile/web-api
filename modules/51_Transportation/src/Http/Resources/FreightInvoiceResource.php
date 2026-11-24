<?php

declare(strict_types=1);

namespace Modules\Transportation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Transportation\Models\TmsFreightInvoice;

/**
 * An invoice received from a carrier. The audit compares each line with the expected rate of its transport order; a dispute tracks what is contested.
 *
 * @mixin TmsFreightInvoice
 */
final class FreightInvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'carrier_id' => $this->carrier_id,
            'invoice_number' => $this->invoice_number,
            'invoice_date' => $this->invoice_date->toDateString(),
            'currency' => $this->currency,
            'total_amount' => $this->total_amount,
            'expected_total' => $this->expected_total,
            'variance_amount' => $this->variance_amount,
            'tolerance_percent' => $this->tolerance_percent,
            'audit_status' => $this->audit_status,
            'dispute_status' => $this->dispute_status,
            'dispute_reason' => $this->dispute_reason,
            'resolution_note' => $this->resolution_note,
            'credited_amount' => $this->credited_amount,
            'audited_at' => $this->audited_at?->toIso8601String(),
            'notes' => $this->notes,
            'carrier' => $this->whenLoaded('carrier', fn (): ?array => $this->carrier !== null ? ['id' => $this->carrier->id, 'code' => $this->carrier->code, 'name' => $this->carrier->name] : null),
            'lines' => FreightInvoiceLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
