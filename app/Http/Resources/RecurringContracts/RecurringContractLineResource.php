<?php

declare(strict_types=1);

namespace App\Http\Resources\RecurringContracts;

use App\Models\RecurringContracts\RecurringContractLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RecurringContractLine
 */
class RecurringContractLineResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'recurring_contract_id' => $this->recurring_contract_id,
            'product_id' => $this->product_id,
            'sort_order' => $this->sort_order,
            'description' => $this->description,
            'quantity' => (string) $this->quantity,
            'unit_price' => (string) $this->unit_price,
            'discount_percent' => (string) ($this->discount_percent ?? '0.00'),
            'vat_rate_id' => $this->vat_rate_id,
            'tax_rate' => (string) ($this->tax_rate ?? '0.00'),
            'proration_mode' => $this->proration_mode,
            'line_subtotal' => $this->calculateLineSubtotal(),
            'line_tax' => $this->calculateLineTax(),
            'line_total' => $this->calculateLineTotal(),
        ];
    }
}
