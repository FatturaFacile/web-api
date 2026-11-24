<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Models\InvoiceMatchToleranceRule;

/**
 * @mixin InvoiceMatchToleranceRule
 */
final class InvoiceMatchToleranceRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'scope' => $this->scope->value,
            'supplier_id' => $this->supplier_id,
            'item_category' => $this->item_category,
            'match_type' => $this->match_type,
            'priority' => $this->priority,
            'price_tolerance_percent' => (float) $this->price_tolerance_percent,
            'price_tolerance_amount' => (float) $this->price_tolerance_amount,
            'quantity_tolerance_percent' => (float) $this->quantity_tolerance_percent,
            'quantity_tolerance_amount' => (float) $this->quantity_tolerance_amount,
            'currency' => $this->currency,
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
