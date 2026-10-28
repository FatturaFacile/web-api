<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ThreeWayMatchLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_order_id' => $this->purchase_order_id,
            'purchase_receipt_id' => $this->purchase_receipt_id,
            'passive_invoice_id' => $this->passive_invoice_id,
            'match_status' => $this->match_status,
            'po_total_amount' => (float) $this->po_total_amount,
            'grn_total_amount' => (float) $this->grn_total_amount,
            'invoice_total_amount' => (float) $this->invoice_total_amount,
            'variance_amount' => (float) $this->variance_amount,
            'variance_percentage' => (float) $this->variance_percentage,
            'discrepancy_details' => $this->discrepancy_details,
            'evaluated_at' => $this->evaluated_at?->toIso8601String(),
        ];
    }
}
