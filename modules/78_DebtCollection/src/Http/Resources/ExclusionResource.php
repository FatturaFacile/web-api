<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DebtCollection\Models\Exclusion;

/**
 * @mixin Exclusion
 */
final class ExclusionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->whenLoaded('customer', fn (): ?string => $this->customer === null ? null : (trim((string) $this->customer->company_name) !== '' ? (string) $this->customer->company_name : trim($this->customer->first_name.' '.$this->customer->last_name))),
            'receivable_item_id' => $this->receivable_item_id,
            'type' => $this->type,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'released_at' => $this->released_at?->toIso8601String(),
            'release_note' => $this->release_note,
        ];
    }
}
