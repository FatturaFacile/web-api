<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Invoicing\Models\ReceivableAllocation;

/**
 * @mixin ReceivableAllocation
 */
final class ReceivableAllocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'receivable_item_id' => $this->receivable_item_id,
            'source_type' => $this->source_type,
            'origin' => $this->origin,
            'source_id' => $this->source_id,
            'source_reference' => $this->source_reference,
            'amount' => (string) $this->amount,
            'value_date' => $this->value_date->toDateString(),
            'notes' => $this->notes,
            'allocated_by' => $this->allocated_by,
            'reversed_at' => $this->reversed_at?->toISOString(),
        ];
    }
}
